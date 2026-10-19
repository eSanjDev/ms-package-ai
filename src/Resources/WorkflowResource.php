<?php

namespace Esanj\AiClient\Resources;

use Esanj\AiClient\Enums\ResultType;
use Esanj\AiClient\Enums\WorkflowType;

/**
 * A workflow the calling service has access to. Start an execution of it by its {@see $key}.
 */
final class WorkflowResource
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $description,
        public readonly WorkflowType $type,
        public readonly ResultType $resultType,
        public readonly ?string $version,
        public readonly bool $isActive,
    ) {}

    public static function fromItem(mixed $item): self
    {
        $item = ResponsePayload::of($item);

        return new self(
            key:         $item->string('key'),
            name:        $item->string('name'),
            description: $item->nullableString('description'),
            type:        $item->enum('type', WorkflowType::class),
            resultType:  $item->enum('result_type', ResultType::class),
            version:     $item->nullableString('version'),
            isActive:    $item->bool('is_active'),
        );
    }
}
