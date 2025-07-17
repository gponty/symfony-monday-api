<?php declare(strict_types=1);

namespace Gponty\MondayBundle;

class MondayApi
{
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
     * @return array|false The API response or false on failure
     */
    public function request(string $query, array $variables = []): array|false
    {
        $apiUrl = 'https://api.monday.com/v2';
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
            ],
        ]);

        $data = \file_get_contents($apiUrl, false, $context);

        if (!$data) {
            return false;
        }

        $json = \json_decode($data, true);
        if (!\is_array($json)) {
            return false;
        }

        return $json['data'] ?? $json['errors'] ?? false;
    }

    /**
     * Create a group if it doesn't exist yet.
     *
     * @param int    $boardId    The board ID
     * @param string $groupTitle The group title to create or retrieve
     *
     * @return string|false Group ID or false if error
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
