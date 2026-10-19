<?php

namespace Esanj\AiClient;

use Esanj\AiClient\Auth\AuthBridgeTokenProvider;
use Esanj\AiClient\Contracts\AiClientInterface;
use Esanj\AiClient\Contracts\ExecutionClientInterface;
use Esanj\AiClient\Contracts\TokenProviderInterface;
use Esanj\AiClient\Contracts\WorkflowClientInterface;
use Esanj\AiClient\Http\ApiClient;
use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class AiClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfig();

        $this->app->singleton(TokenProviderInterface::class, function ($app) {
            $config = $app['config']['esanj']['ai'];

            return new AuthBridgeTokenProvider(
                credentials:  $app->make(ClientCredentialsServiceInterface::class),
                clientId:     (string) $config['client_id'],
                clientSecret: (string) $config['client_secret'],
                scope:        $config['scope'] ?? null,
            );
        });

        $this->app->singleton(ApiClient::class, function ($app) {
            $config = $app['config']['esanj']['ai'];

            $logChannel = $config['logging']['channel'] ?? null;
            $logger = $logChannel
                ? $app['log']->channel($logChannel)
                : $app[LoggerInterface::class];

            return new ApiClient(
                httpClient:    new Client(['timeout' => $config['timeout'], 'connect_timeout' => 10]),
                tokenProvider: $app[TokenProviderInterface::class],
                logger:        $logger,
                baseUrl:       $config['base_url'],
                retryAttempts: max(1, (int) $config['retry']['attempts']),
                retrySleepMs:  (int) $config['retry']['sleep_ms'],
            );
        });

        $this->app->singleton(WorkflowClientInterface::class, fn ($app) => new WorkflowClient($app[ApiClient::class]));
        $this->app->singleton(ExecutionClientInterface::class, fn ($app) => new ExecutionClient($app[ApiClient::class]));

        $this->app->singleton(AiClientInterface::class, fn ($app) => new AiClient(
            $app[WorkflowClientInterface::class],
            $app[ExecutionClientInterface::class],
        ));

        $this->app->alias(WorkflowClientInterface::class, WorkflowClient::class);
        $this->app->alias(ExecutionClientInterface::class, ExecutionClient::class);
        $this->app->alias(AiClientInterface::class, AiClient::class);
    }

    /**
     * Unlike mergeConfigFrom(), keeps the defaults of nested keys the app leaves out (e.g. retry.sleep_ms).
     */
    private function mergeConfig(): void
    {
        if ($this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');

        $config->set('esanj.ai', array_replace_recursive(
            require __DIR__ . '/../config/ai.php',
            $config->get('esanj.ai', []),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/ai.php' => config_path('esanj/ai.php'),
            ], 'ai-config');
        }
    }
}
