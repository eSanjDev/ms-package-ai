<?php

namespace Esanj\AiClient\Resources;

use Esanj\AiClient\Enums\ResultType;
use Esanj\AiClient\Exceptions\AiUnexpectedResponseException;
use Psr\Http\Message\ResponseInterface;

/**
 * What a workflow answered: the JSON built from its JSON structure, or the HTML page rendered from
 * its template, depending on its result type.
 */
final class ExecutionOutput
{
    /**
     * @param string                  $executionUuid The execution that answered; continue a conversation with it.
     * @param array<array-key, mixed> $data          The resolved JSON structure; empty for a template.
     * @param ConversationMessage[]   $messages      A conversation's messages, oldest first; JSON runs only.
     * @param string|null             $html          The rendered page of a template.
     * @param string|null             $accessToken   The execution's access token the run was sent with; null for output().
     */
    public function __construct(
        public readonly ResultType $type,
        public readonly string $executionUuid,
        public readonly array $data,
        public readonly array $messages,
        public readonly ?string $html,
        public readonly ?string $accessToken = null,
    ) {}

    /**
     * @param array|null $json The decoded body, or null when it is not a JSON object.
     */
    public static function fromResponse(string $executionUuid, ResponseInterface $response, ?array $json, ?string $accessToken = null): self
    {
        if (str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html')) {
            return new self(
                type:          ResultType::Template,
                executionUuid: $executionUuid,
                data:          [],
                messages:      [],
                html:          (string) $response->getBody(),
                accessToken:   $accessToken,
            );
        }

        if ($json === null) {
            throw new AiUnexpectedResponseException(
                "The AI service returned neither a JSON object nor an HTML page (HTTP {$response->getStatusCode()})."
            );
        }

        $body = ResponsePayload::of($json);

        return new self(
            type:          ResultType::Json,
            executionUuid: $executionUuid,
            data:          $body->array('data'),
            messages:      array_map(ConversationMessage::fromItem(...), array_values($body->array('messages'))),
            html:          null,
            accessToken:   $accessToken,
        );
    }

    public function isJson(): bool
    {
        return $this->type->isJson();
    }

    public function isTemplate(): bool
    {
        return $this->type->isTemplate();
    }

    /**
     * The reply to a conversation's latest message.
     */
    public function lastReply(): ?string
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)]->reply;
    }
}
