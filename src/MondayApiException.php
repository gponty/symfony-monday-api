<?php declare(strict_types=1);

namespace Gponty\MondayBundle;

/**
 * Thrown when a request to the Monday.com API cannot be sent (transport
 * error) or is answered with an HTTP error status (4xx/5xx).
 *
 * The message carries the HTTP status, the reason given by Monday.com (when
 * any) and the beginning of the query, so that a log line is enough to
 * understand the failure.
 */
class MondayApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $query,
        private readonly ?int $statusCode = null,
        private readonly ?string $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    /**
     * The GraphQL query (or mutation) that was sent.
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * HTTP status code of the response, null when no response was received.
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Raw response body, null when no response was received.
     */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
