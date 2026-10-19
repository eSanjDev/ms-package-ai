<?php

namespace Esanj\AiClient\Resources;

/**
 * One completed message of a conversation: what was sent, and what the last agent that ran replied.
 */
final class ConversationMessage
{
    /**
     * @param array<string, ?string> $input
     */
    public function __construct(
        public readonly array $input,
        public readonly ?string $reply,
    ) {}

    public static function fromItem(mixed $item): self
    {
        $item = ResponsePayload::of($item);

        return new self(
            input: $item->array('input'),
            reply: $item->nullableString('reply'),
        );
    }
}
