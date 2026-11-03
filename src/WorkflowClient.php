<?php

namespace Esanj\AiClient;

use Esanj\AiClient\Contracts\WorkflowClientInterface;
use Esanj\AiClient\Http\ApiClient;
use Esanj\AiClient\Resources\ExecutionResource;
use Esanj\AiClient\Resources\PaginatedResult;
use Esanj\AiClient\Resources\WorkflowResource;

class WorkflowClient implements WorkflowClientInterface
{
    private const BASE = 'api/v1/application/workflows';

    public function __construct(private readonly ApiClient $apiClient) {}

    public function list(?string $search = null, int $page = 1, int $perPage = 30): PaginatedResult
    {
        return PaginatedResult::fromArray(
            $this->apiClient->get(self::BASE, array_filter([
                'search'   => $search,
                'page'     => $page,
                'per_page' => $perPage,
            ], fn ($value) => $value !== null && $value !== '')),
            WorkflowResource::fromItem(...),
        );
    }

    public function start(string $key): ExecutionResource
    {
        return ExecutionResource::fromArrayWithAccessToken(
            $this->apiClient->post(self::BASE . '/' . rawurlencode($key) . '/executions', idempotent: true)
        );
    }
}
