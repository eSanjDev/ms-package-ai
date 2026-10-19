<?php

namespace Esanj\AiClient;

use Esanj\AiClient\Contracts\AiClientInterface;
use Esanj\AiClient\Contracts\ExecutionClientInterface;
use Esanj\AiClient\Contracts\WorkflowClientInterface;
use Esanj\AiClient\Resources\ExecutionOutput;

/**
 * Entry point that groups the AI service's two resources behind a single
 * injectable client: {@see workflows()} and {@see executions()}.
 */
class AiClient implements AiClientInterface
{
    public function __construct(
        private readonly WorkflowClientInterface $workflows,
        private readonly ExecutionClientInterface $executions,
    ) {}

    public function workflows(): WorkflowClientInterface
    {
        return $this->workflows;
    }

    public function executions(): ExecutionClientInterface
    {
        return $this->executions;
    }

    public function run(string $workflowKey, array $input): ExecutionOutput
    {
        return $this->executions->run($this->workflows->start($workflowKey)->uuid, $input);
    }
}
