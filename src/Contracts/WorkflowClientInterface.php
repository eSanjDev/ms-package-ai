<?php

namespace Esanj\AiClient\Contracts;

use Esanj\AiClient\Resources\ExecutionResource;
use Esanj\AiClient\Resources\PaginatedResult;
use Esanj\AiClient\Resources\WorkflowResource;

interface WorkflowClientInterface
{
    /**
     * The workflows the calling service has access to, by name. $search matches the name or key.
     *
     * @return PaginatedResult<WorkflowResource>
     */
    public function list(?string $search = null, int $page = 1, int $perPage = 30): PaginatedResult;

    /**
     * Open an execution of a workflow. Nothing runs yet: pass the returned uuid to
     * executions()->run(), or hand it (or executions()->runUrl()) to the user. Retried on a server
     * or connection error, which at worst leaves an extra execution that never runs.
     */
    public function start(string $key): ExecutionResource;
}
