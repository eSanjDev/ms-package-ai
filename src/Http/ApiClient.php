<?php

namespace Esanj\AiClient\Http;

use Esanj\AiClient\Contracts\TokenProviderInterface;
use Esanj\AiClient\Exceptions\AiApiException;
use Esanj\AiClient\Exceptions\AiOutcomeUnknownException;
use Esanj\AiClient\Exceptions\AiUnexpectedResponseException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class ApiClient
{
    /**
     * The service answers 401 for a missing, expired or invalid token. A 403 is a definitive answer
     * (inactive workflow or service, or no access to the workflow), so it is never retried.
     */
    private const TOKEN_INVALID_STATUS = 401;

    /**
     * cURL errors raised after the request may have reached the service: HTTP/2 framing (16),
     * partial body (18), send failure (55), receive failure (56) and HTTP/2 stream (92).
     * Connection and timeout errors arrive as ConnectException instead.
     */
    private const INTERRUPTED_TRANSFER_ERRNOS = [16, 18, 55, 56, 92];

    public function __construct(
        private readonly Client $httpClient,
        private readonly TokenProviderInterface $tokenProvider,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly int $retryAttempts,
        private readonly int $retrySleepMs,
    ) {}

    public function get(string $path, array $query = []): array
    {
        return $this->decodeSuccess($this->send('GET', $path, array_filter(['query' => $query ?: null]), retryable: true), $path);
    }

    /**
     * Server and connection failures are retried only for an $idempotent call, i.e. one that is safe
     * to repeat. Any other call fails with an {@see AiOutcomeUnknownException} instead, since it may
     * already have been applied.
     */
    public function post(string $path, array $body = [], bool $idempotent = false): array
    {
        return $this->decodeSuccess($this->send('POST', $path, ['json' => $body], $idempotent), $path);
    }

    /**
     * Sends a request and returns the successful response as is, for the endpoints that answer with
     * a workflow's output: JSON or a rendered HTML page. Without $authenticated no token is sent,
     * for the service's public endpoints.
     */
    public function send(string $method, string $path, array $options = [], bool $retryable = false, bool $authenticated = true): ResponseInterface
    {
        $url = $this->url($path);
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->retryAttempts; $attempt++) {
            try {
                return $this->httpClient->request($method, $url, array_merge($options, [
                    'headers' => array_merge(
                        ['Accept' => 'application/json'],
                        $authenticated ? ['Authorization' => $this->tokenProvider->authorizationHeader()] : [],
                        $options['headers'] ?? [],
                    ),
                ]));

            } catch (ClientException $e) {
                $status = $e->getResponse()->getStatusCode();
                $body   = $this->decode($e->getResponse()) ?? [];
                $tokenRejected = $authenticated && $status === self::TOKEN_INVALID_STATUS;

                // Only a rejected token is retryable (the service checks it before handling the
                // request); everything else is a definitive answer.
                if (!$tokenRejected || $attempt >= $this->retryAttempts) {
                    if ($tokenRejected) {
                        $this->logger->error('[AiClient] Authentication failed after all retry attempts.', [
                            'status' => $status,
                            'url'    => $url,
                        ]);
                    }

                    throw $this->makeApiException($body, $status, $e);
                }

                $this->logger->warning('[AiClient] Token rejected, refreshing and retrying.', [
                    'status'  => $status,
                    'attempt' => $attempt,
                    'url'     => $url,
                ]);

                $this->tokenProvider->invalidate();
                $lastException = $this->makeApiException($body, $status, $e);
                $this->sleep();

            } catch (GuzzleException $e) {
                if (!$this->isTransportFailure($e)) {
                    $this->logger->error('[AiClient] Unexpected HTTP error.', [
                        'attempt' => $attempt,
                        'url'     => $url,
                        'error'   => $e->getMessage(),
                    ]);

                    throw new AiApiException('Unexpected error: ' . $e->getMessage(), statusCode: 0, previous: $e);
                }

                $status = $e instanceof ServerException ? $e->getResponse()->getStatusCode() : 0;
                $body   = $e instanceof ServerException ? ($this->decode($e->getResponse()) ?? []) : [];

                if (!$retryable) {
                    $this->logger->error('[AiClient] Request failed after it may have been applied; not retrying.', [
                        'status' => $status,
                        'url'    => $url,
                    ]);

                    throw new AiOutcomeUnknownException(
                        "The AI service did not confirm the result of {$method} {$path}; it may have been applied.",
                        statusCode: $status,
                        responseBody: $body,
                        previous: $e,
                    );
                }

                $this->logger->warning('[AiClient] Server or connection error, retrying.', [
                    'status'  => $status,
                    'attempt' => $attempt,
                    'url'     => $url,
                ]);

                $lastException = $e instanceof ServerException
                    ? $this->makeApiException($body, $status, $e, 'server error')
                    : new AiApiException('Connection error: ' . $e->getMessage(), statusCode: 0, previous: $e);

                if ($attempt < $this->retryAttempts) {
                    $this->sleep();
                }
            }
        }

        $this->logger->error('[AiClient] All retry attempts exhausted.', [
            'url'      => $url,
            'attempts' => $this->retryAttempts,
        ]);

        throw $lastException ?? new AiApiException('Request failed after all retry attempts.', statusCode: 0);
    }

    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * A 5xx answer, a connection error, or a transfer cut off after it started. Other errors, such
     * as an invalid URL or a TLS certificate problem, are not transient.
     */
    private function isTransportFailure(GuzzleException $e): bool
    {
        return $e instanceof ServerException
            || $e instanceof ConnectException
            || ($e instanceof RequestException
                && !$e->hasResponse()
                && in_array($e->getHandlerContext()['errno'] ?? null, self::INTERRUPTED_TRANSFER_ERRNOS, true));
    }

    private function decodeSuccess(ResponseInterface $response, string $path): array
    {
        $body = $this->decode($response);

        if ($body === null) {
            $this->logger->error('[AiClient] Unreadable response.', [
                'status'       => $response->getStatusCode(),
                'content_type' => $response->getHeaderLine('Content-Type'),
                'url'          => $this->url($path),
            ]);

            throw new AiUnexpectedResponseException(
                "The AI service returned a response that is not a JSON object (HTTP {$response->getStatusCode()})."
            );
        }

        return $body;
    }

    public function decode(ResponseInterface $response): ?array
    {
        try {
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($body) ? $body : null;
    }

    private function makeApiException(array $body, int $status, GuzzleException $previous, string $kind = 'error'): AiApiException
    {
        $message = $body['message'] ?? null;

        return new AiApiException(
            message: is_string($message) && $message !== '' ? $message : "HTTP {$status} {$kind}.",
            statusCode: $status,
            responseBody: $body,
            previous: $previous,
        );
    }

    private function sleep(): void
    {
        if ($this->retrySleepMs > 0) {
            usleep($this->retrySleepMs * 1_000);
        }
    }
}
