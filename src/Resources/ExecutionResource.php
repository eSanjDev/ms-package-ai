<?php

namespace Esanj\AiClient\Resources;

/**
 * An execution of a workflow, started by the calling service. Its {@see $uuid} together with its
 * {@see $accessToken} is what runs it, and what the service hands to its user to run it from the browser.
 */
final class ExecutionResource
{
    /**
     * @param string|null            $workflow             The workflow's name; null once the workflow is deleted.
     * @param string|null            $typeLabel            The workflow type as a translated label, e.g. "Conversation".
     * @param ExecutionLogResource[] $logs                 Its runs, oldest first; only returned by show() and retry().
     * @param string|null            $accessToken          The token that runs the execution; only returned by start() and issueToken().
     * @param string|null            $accessTokenExpiresAt When that token expires, e.g. "2026-10-20T14:05:12+03:30".
     */
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $workflow,
        public readonly ?string $typeLabel,
        public readonly ?string $executedBy,
        public readonly array $logs,
        public readonly ?string $accessToken = null,
        public readonly ?string $accessTokenExpiresAt = null,
    ) {}

    public static function fromArray(array $response): self
    {
        return self::fromItem($response['data'] ?? null);
    }

    /**
     * For the responses that issue the execution's access token, which the service sends next to
     * its "data" object, only once.
     */
    public static function fromArrayWithAccessToken(array $response): self
    {
        $body = ResponsePayload::of($response);

        return self::make(
            ResponsePayload::of($response['data'] ?? null),
            $body->string('access_token'),
            $body->nullableString('access_token_expires_at'),
        );
    }

    public static function fromItem(mixed $item): self
    {
        return self::make(ResponsePayload::of($item));
    }

    /**
     * The latest run, whose output the execution currently shows.
     */
    public function currentLog(): ?ExecutionLogResource
    {
        foreach ($this->logs as $log) {
            if ($log->isCurrent) {
                return $log;
            }
        }

        return null;
    }

    private static function make(ResponsePayload $item, ?string $accessToken = null, ?string $accessTokenExpiresAt = null): self
    {
        return new self(
            uuid:                 $item->string('uuid'),
            workflow:             $item->nullableString('workflow'),
            typeLabel:            $item->nullableString('type'),
            executedBy:           $item->nullableString('executed_by'),
            logs:                 array_map(ExecutionLogResource::fromItem(...), array_values($item->array('logs'))),
            accessToken:          $accessToken,
            accessTokenExpiresAt: $accessTokenExpiresAt,
        );
    }
}
