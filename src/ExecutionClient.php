<?php

namespace Esanj\AiClient;

use Esanj\AiClient\Contracts\ExecutionClientInterface;
use Esanj\AiClient\Http\ApiClient;
use Esanj\AiClient\Resources\ExecutionOutput;
use Esanj\AiClient\Resources\ExecutionResource;
use Esanj\AiClient\Resources\PaginatedResult;

class ExecutionClient implements ExecutionClientInterface
{
    private const BASE = 'api/v1/application/executions';

    private const RUN_PATH = 'api/v1/client/executions/%s/run';

    /**
     * A run answers with JSON or, for a template workflow, an HTML page.
     */
    private const OUTPUT_ACCEPT = 'application/json, text/html;q=0.9';

    public function __construct(private readonly ApiClient $apiClient) {}

    public function list(?string $search = null, int $page = 1, int $perPage = 30): PaginatedResult
    {
        return PaginatedResult::fromArray(
            $this->apiClient->get(self::BASE, array_filter([
                'search'   => $search,
                'page'     => $page,
                'per_page' => $perPage,
            ], fn ($value) => $value !== null && $value !== '')),
            ExecutionResource::fromItem(...),
        );
    }

    public function show(string $uuid): ExecutionResource
    {
        return ExecutionResource::fromArray($this->apiClient->get($this->path($uuid)));
    }

    public function run(string $uuid, string $accessToken, array $input): ExecutionOutput
    {
        // The run endpoint takes the execution's own token instead of the service token.
        $response = $this->apiClient->send(
            'POST',
            sprintf(self::RUN_PATH, rawurlencode($uuid)),
            [
                'json'    => ['input' => $input],
                'headers' => ['Accept' => self::OUTPUT_ACCEPT, 'Authorization' => "Bearer {$accessToken}"],
            ],
            authenticated: false,
        );

        return ExecutionOutput::fromResponse($uuid, $response, $this->apiClient->decode($response), $accessToken);
    }

    /**
     * Retried on a server or connection error: each issue replaces the previous token, so the
     * last answer always holds the valid one.
     */
    public function issueToken(string $uuid): ExecutionResource
    {
        return ExecutionResource::fromArrayWithAccessToken(
            $this->apiClient->post($this->path($uuid) . '/token', idempotent: true)
        );
    }

    public function revokeToken(string $uuid): void
    {
        $this->apiClient->send('DELETE', $this->path($uuid) . '/token', retryable: true);
    }

    public function retry(string $uuid, string $logUuid): ExecutionResource
    {
        return ExecutionResource::fromArray($this->apiClient->post($this->path($uuid, $logUuid, 'retry')));
    }

    public function output(string $uuid, string $logUuid): ExecutionOutput
    {
        $response = $this->apiClient->send(
            'GET',
            $this->path($uuid, $logUuid, 'interface'),
            ['headers' => ['Accept' => self::OUTPUT_ACCEPT]],
            retryable: true,
        );

        return ExecutionOutput::fromResponse($uuid, $response, $this->apiClient->decode($response));
    }

    public function runUrl(string $uuid): string
    {
        return $this->apiClient->url(sprintf(self::RUN_PATH, rawurlencode($uuid)));
    }

    private function path(string $uuid, ?string $logUuid = null, ?string $action = null): string
    {
        $path = self::BASE . '/' . rawurlencode($uuid);

        return $logUuid ? "{$path}/logs/" . rawurlencode($logUuid) . "/{$action}" : $path;
    }
}
