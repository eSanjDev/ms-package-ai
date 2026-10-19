<?php

namespace Esanj\AiClient\Exceptions;

use Throwable;

/**
 * Thrown when the AI service returns an error response.
 *
 * A 403 means the workflow is inactive, the calling service is inactive, or the service has no
 * permission for the workflow; the reason is in {@see getMessage()}.
 */
class AiApiException extends AiException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $responseBody = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Field-level validation errors, when present (HTTP 422).
     *
     * @return array<string, string[]>
     */
    public function getErrors(): array
    {
        $errors = $this->responseBody['errors'] ?? [];

        return is_array($errors) ? $errors : [];
    }

    public function isUnauthorized(): bool
    {
        return $this->statusCode === 401;
    }

    public function isForbidden(): bool
    {
        return $this->statusCode === 403;
    }

    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    /**
     * The payload was rejected field by field; inspect {@see getErrors()}.
     */
    public function isValidationError(): bool
    {
        return $this->statusCode === 422;
    }

    public function isTooManyRequests(): bool
    {
        return $this->statusCode === 429;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }
}
