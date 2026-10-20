<?php declare(strict_types=1);

namespace Gponty\MondayBundle;

class MondayApi
{
    private const API_URL = 'https://api.monday.com/v2';

    private string $apiKey = '';

    public function __construct()
    {
    }

    /**
     * Set the API key.
     */
    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Send a GraphQL request to the Monday.com API.
     *
     * @param string $query     GraphQL query string
     * @param array  $variables Optional GraphQL variables
     *
     * @return array|false The `data` part of the response (or its `errors`
     *                     list when there is no data), false on an unreadable body
     *
     * @throws MondayApiException when the API cannot be reached or answers
     *                            with an HTTP error status (4xx/5xx); its
     *                            message carries the status and the reason
     *                            returned by Monday.com
     */
    public function request(string $query, array $variables = []): array|false
    {
        $headers = [
            'Content-Type: application/json',
            'User-Agent: Github.com/symfony-monday-api',
            'API-Version: 2023-10',
            'Authorization: '.$this->apiKey,
        ];

        $payload = ['query' => $query];
        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        $context = \stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => \implode("\r\n", $headers),
                'content' => \json_encode($payload),
                // Read the body of 4xx/5xx responses too: it holds Monday's error message
                'ignore_errors' => true,
            ],
        ]);

        // Turn the warning raised on a transport failure (DNS, connection, timeout) into an exception
        $warning = null;
        \set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $body = \file_get_contents(self::API_URL, false, $context);
        } finally {
            \restore_error_handler();
        }

        if (false === $body) {
            throw new MondayApiException(
                \sprintf('Monday API unreachable: %s (query: %s)', $warning ?? 'unknown transport error', self::excerpt($query)),
                $query,
            );
        }

        // $http_response_header is deprecated since PHP 8.5 (compile-time notice when named), hence get_defined_vars() on PHP < 8.4
        $responseHeaders = \function_exists('http_get_last_response_headers')
            ? \http_get_last_response_headers()
            : \get_defined_vars()['http_response_header'];
        $statusCode = self::parseStatusCode(\is_array($responseHeaders) ? $responseHeaders : []);
        $json = \json_decode($body, true);

        if ($statusCode >= 400) {
            throw new MondayApiException(
                \sprintf(
                    'Monday API error (HTTP %d): %s (query: %s)',
                    $statusCode,
                    self::extractErrorMessage(\is_array($json) ? $json : null, $body),
                    self::excerpt($query),
                ),
                $query,
                $statusCode,
                $body,
            );
        }

        if (!\is_array($json)) {
            return false;
        }

        return $json['data'] ?? $json['errors'] ?? false;
    }

    /**
     * HTTP status code from the `$http_response_header` lines, 0 when missing.
     *
     * @param list<string> $responseHeaders
     */
    private static function parseStatusCode(array $responseHeaders): int
    {
        // Several status lines may be present after redirects: the last one is the final answer
        $statusCode = 0;
        foreach ($responseHeaders as $header) {
            if (\preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                $statusCode = (int) $matches[1];
            }
        }

        return $statusCode;
    }

    /**
     * Human readable reason from the various error formats of the Monday.com API:
     * `{"error_message": ..., "error_code": ...}`, `{"errors": [{"message": ...}]}`
     * or `{"error": ...}`. Falls back to an excerpt of the raw body.
     */
    private static function extractErrorMessage(?array $json, string $body): string
    {
        if (null === $json) {
            return self::excerpt($body);
        }

        if (isset($json['error_message']) && \is_string($json['error_message'])) {
            $message = $json['error_message'];
            if (isset($json['error_code']) && \is_string($json['error_code'])) {
                $message .= \sprintf(' [%s]', $json['error_code']);
            }

            return $message;
        }

        if (isset($json['errors']) && \is_array($json['errors'])) {
            $messages = [];
            foreach ($json['errors'] as $error) {
                if (\is_array($error) && isset($error['message']) && \is_string($error['message'])) {
                    $message = $error['message'];
                    if (isset($error['extensions']['code']) && \is_string($error['extensions']['code'])) {
                        $message .= \sprintf(' [%s]', $error['extensions']['code']);
                    }
                    $messages[] = $message;
                } elseif (\is_string($error)) {
                    $messages[] = $error;
                }
            }
            if ([] !== $messages) {
                return \implode(' ; ', $messages);
            }
        }

        if (isset($json['error']) && \is_string($json['error'])) {
            return $json['error'];
        }

        return self::excerpt($body);
    }

    /**
     * Beginning of a text (raw body, query), on one line, for an error message.
     */
    private static function excerpt(string $text): string
    {
        $text = \trim((string) \preg_replace('/\s+/', ' ', $text));
        if ('' === $text) {
            return '(empty)';
        }

        return \mb_strlen($text) > 300 ? \mb_substr($text, 0, 300).'…' : $text;
    }

    /**
     * Create a group if it doesn't exist yet.
     *
     * @param int    $boardId    The board ID
     * @param string $groupTitle The group title to create or retrieve
     *
     * @return string|false Group ID or false if error
     *
     * @throws MondayApiException when the API cannot be reached or answers with an HTTP error (see request())
     */
    public function createGroup(int $boardId, string $groupTitle): string|false
    {
        $query = <<<GRAPHQL
        {
            boards(ids: $boardId) {
                groups {
                    id
                    title
                }
            }
        }
        GRAPHQL;

        $data = $this->request($query);
        if (!$data || !isset($data['boards'][0]['groups'])) {
            return false;
        }

        foreach ($data['boards'][0]['groups'] as $group) {
            if ($group['title'] === $groupTitle) {
                return $group['id'];
            }
        }

        // Group does not exist, create it
        $mutation = <<<GRAPHQL
        mutation {
            create_group(board_id: $boardId, group_name: "{$this->escape($groupTitle)}") {
                id
            }
        }
        GRAPHQL;

        $response = $this->request($mutation);

        return $response['create_group']['id'] ?? false;
    }

    /**
     * Create or update an item based on its name.
     *
     * @param int    $boardId    Board ID
     * @param string $groupId    Group ID
     * @param string $itemName   Item name (must be unique in the group)
     * @param array  $itemValues Array of column values
     *
     * @return string|false The item ID or false on failure
     *
     * @throws MondayApiException when the API cannot be reached or answers with an HTTP error (see request())
     */
    public function createItem(int $boardId, string $groupId, string $itemName, array $itemValues): string|false
    {
        $escapedItemName = $this->escape($itemName);

        $query = <<<GRAPHQL
        {
            boards(ids: $boardId) {
                groups(ids: "$groupId") {
                    items_page(query_params: {
                        rules: [{ column_id: "name", compare_value: ["$escapedItemName"] }],
                        operator: and
                    }) {
                        items {
                            id
                            name
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $response = $this->request($query);
        if (!$response || empty($response['boards'][0]['groups'][0]['items_page']['items'])) {
            // Item not found, create it
            $mutation = <<<GRAPHQL
            mutation {
                create_item(board_id: $boardId, group_id: "$groupId", item_name: "$escapedItemName") {
                    id
                }
            }
            GRAPHQL;

            $createData = $this->request($mutation);
            $itemId = $createData['create_item']['id'] ?? null;
        } else {
            $itemId = $response['boards'][0]['groups'][0]['items_page']['items'][0]['id'];
        }

        if (!$itemId) {
            return false;
        }

        $json = $this->encodeValueMutation($itemValues);
        if ($json === false) {
            return false;
        }

        // Update item column values
        $mutation = <<<GRAPHQL
        mutation {
            change_multiple_column_values(
                item_id: $itemId,
                board_id: $boardId,
                column_values: "$json",
                create_labels_if_missing: true
            ) {
                id
            }
        }
        GRAPHQL;

        $this->request($mutation);

        return $itemId;
    }

    /**
     * Create or update a subitem based on its name.
     *
     * @param int    $boardId       Parent board ID
     * @param string $itemId        Parent item ID
     * @param string $subItemName   Subitem name
     * @param array  $subItemValues Column values
     *
     * @return string|false Subitem ID or false on failure
     *
     * @throws MondayApiException when the API cannot be reached or answers with an HTTP error (see request())
     */
    public function createSubItem(int $boardId, string $itemId, string $subItemName, array $subItemValues): string|false
    {
        $escapedSubItemName = $this->escape($subItemName);

        $query = <<<GRAPHQL
        {
            items(ids: [$itemId]) {
                subitems {
                    id
                    name
                    board { id }
                }
            }
        }
        GRAPHQL;

        $response = $this->request($query);
        $subItems = $response['items'][0]['subitems'] ?? [];

        foreach ($subItems as $subItem) {
            if ($subItem['name'] === $subItemName) {
                $subItemId = $subItem['id'];
                $boardId = $subItem['board']['id'];
                goto update;
            }
        }

        // Subitem not found, create it
        $mutation = <<<GRAPHQL
        mutation {
            create_subitem(parent_item_id: $itemId, item_name: "$escapedSubItemName") {
                id
                board { id }
            }
        }
        GRAPHQL;

        $data = $this->request($mutation);
        if (!$data || !isset($data['create_subitem']['id'], $data['create_subitem']['board']['id'])) {
            return false;
        }

        $subItemId = $data['create_subitem']['id'];
        $boardId = $data['create_subitem']['board']['id'];

        update:

        $json = $this->encodeValueMutation($subItemValues);
        if ($json === false) {
            return false;
        }

        $mutation = <<<GRAPHQL
        mutation {
            change_multiple_column_values(
                item_id: $subItemId,
                board_id: $boardId,
                column_values: "$json",
                create_labels_if_missing: true
            ) {
                id
            }
        }
        GRAPHQL;

        $this->request($mutation);

        return $subItemId;
    }

    /**
     * Encode values for the `column_values` GraphQL mutation.
     *
     * @param array $values The values to encode
     *
     * @return string|false Escaped JSON string or false on failure
     */
    private function encodeValueMutation(array $values): string|false
    {
        $encodedValue = \json_encode($values);

        return $encodedValue ? \addslashes($encodedValue) : false;
    }

    /**
     * Escape GraphQL string value.
     */
    private function escape(string $value): string
    {
        return \addslashes($value);
    }
}
