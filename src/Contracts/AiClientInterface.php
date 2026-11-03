<?php

namespace Esanj\AiClient\Contracts;

use Esanj\AiClient\Resources\ExecutionOutput;

interface AiClientInterface
{
    /**
     * The workflow endpoints of the AI service.
     */
    public function workflows(): WorkflowClientInterface;

    /**
     * The execution endpoints of the AI service.
     */
    public function executions(): ExecutionClientInterface;

    /**
     * Start an execution of a workflow and send it its first message in one go. Keep the result's
     * executionUuid and accessToken to continue a conversation with executions()->run().
     *
     * @param array<string, ?string> $input
     */
    public function run(string $workflowKey, array $input): ExecutionOutput;
}
