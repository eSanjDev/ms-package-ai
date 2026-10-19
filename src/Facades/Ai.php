<?php

namespace Esanj\AiClient\Facades;

use Esanj\AiClient\Contracts\AiClientInterface;
use Esanj\AiClient\Contracts\ExecutionClientInterface;
use Esanj\AiClient\Contracts\WorkflowClientInterface;
use Esanj\AiClient\Resources\ExecutionOutput;
use Illuminate\Support\Facades\Facade;

/**
 * @method static WorkflowClientInterface workflows()
 * @method static ExecutionClientInterface executions()
 * @method static ExecutionOutput run(string $workflowKey, array $input)
 *
 * @see \Esanj\AiClient\AiClient
 */
class Ai extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AiClientInterface::class;
    }
}
