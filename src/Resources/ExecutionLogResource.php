<?php

namespace Esanj\AiClient\Resources;

use Esanj\AiClient\Enums\ResultType;

/**
 * One run of an execution: the input it got and the parameters its steps produced. Dates are
 * local to the AI service, e.g. "2026-10-19T14:05:12".
 */
final class ExecutionLogResource
{
    /**
     * @param array<string, ?string>       $inputParams
     * @param array<array-key, mixed>|null $outputParams Keyed by "input" and the id of each step that ran; null until completed.
     */
    public function __construct(
        public readonly string $uuid,
        public readonly array $inputParams,
        public readonly ?array $outputParams,
        public readonly ResultType $resultType,
        public readonly ?array $jsonStructure,
        public readonly ?array $template,
        public readonly ?string $errorMessage,
        public readonly bool $isCurrent,
        public readonly ?string $completedAt,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {}

    public static function fromItem(mixed $item): self
    {
        $item = ResponsePayload::of($item);

        return new self(
            uuid:          $item->string('uuid'),
            inputParams:   $item->array('input_params'),
            outputParams:  $item->nullableArray('output_params'),
            resultType:    $item->enum('result_type', ResultType::class),
            jsonStructure: $item->nullableArray('json_structure'),
            template:      $item->nullableArray('template'),
            errorMessage:  $item->nullableString('error_message'),
            isCurrent:     $item->bool('is_current'),
            completedAt:   $item->nullableString('completed_at'),
            createdAt:     $item->nullableString('created_at'),
            updatedAt:     $item->nullableString('updated_at'),
        );
    }

    public function isFailed(): bool
    {
        return $this->errorMessage !== null;
    }

    public function isCompleted(): bool
    {
        return $this->completedAt !== null && !$this->isFailed();
    }

    /**
     * Neither completed nor failed yet.
     */
    public function isRunning(): bool
    {
        return $this->completedAt === null;
    }
}
