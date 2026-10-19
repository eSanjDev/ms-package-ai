<?php

namespace Esanj\AiClient\Resources;

/**
 * An execution of a workflow, started by the calling service. Its {@see $uuid} is what runs it,
 * and what the service hands to its user to run it from the browser.
 */
final class ExecutionResource
{
    /**
     * @param string|null            $workflow  The workflow's name; null once the workflow is deleted.
     * @param string|null            $typeLabel The workflow type as a translated label, e.g. "Conversation".
     * @param ExecutionLogResource[] $logs      Its runs, oldest first; only returned by show() and retry().
     */
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $workflow,
        public readonly ?string $typeLabel,
        public readonly ?string $executedBy,
        public readonly array $logs,
    ) {}

    public static function fromArray(array $response): self
    {
        return self::fromItem($response['data'] ?? null);
    }

    public static function fromItem(mixed $item): self
    {
        $item = ResponsePayload::of($item);

        return new self(
            uuid:       $item->string('uuid'),
            workflow:   $item->nullableString('workflow'),
            typeLabel:  $item->nullableString('type'),
            executedBy: $item->nullableString('executed_by'),
            logs:       array_map(ExecutionLogResource::fromItem(...), array_values($item->array('logs'))),
        );
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
}
