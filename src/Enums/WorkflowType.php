<?php

namespace Esanj\AiClient\Enums;

enum WorkflowType: string
{
    /** Every run of an execution runs the whole workflow on its own. */
    case Single = 'single';

    /** Runs after the first one continue the conversation with the workflow's last text agent. */
    case Conversation = 'conversation';

    public function isConversation(): bool
    {
        return $this === self::Conversation;
    }
}
