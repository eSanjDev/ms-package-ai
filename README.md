# Esanj AI Client

A Laravel client package for the **Esanj AI Microservice**. It authenticates with client credentials through the [`esanj/auth-bridge`](../ms-package-accounting-bridge) package, then lets you list the **workflows** your service has access to, start **executions** of them and run them — single requests or multi-message conversations — with typed responses and structured error handling.

## Installation

```bash
composer require esanj/ai-client
```

The service provider and the `Ai` facade are auto-discovered.

## Configuration

Publish the config (optional — it is merged automatically):

```bash
php artisan vendor:publish --tag=ai-config
```

Set the environment variables:

```dotenv
# AI microservice
AI_SERVICE_URL=https://ai.esanj.io
AI_CLIENT_ID=your-service-client-id
AI_CLIENT_SECRET=your-service-client-secret

# Optional
AI_SCOPE=*
AI_TIMEOUT=120
AI_RETRY_ATTEMPTS=3
AI_RETRY_SLEEP_MS=1000
AI_LOG_CHANNEL=

# Required by esanj/auth-bridge — the OAuth server that issues the token
ACCOUNTING_BRIDGE_BASE_URL=https://accounting.esanj.io
```

> The access token is issued by the OAuth server configured for `esanj/auth-bridge`
> (`ACCOUNTING_BRIDGE_BASE_URL`) using this service's `AI_CLIENT_ID` / `AI_CLIENT_SECRET`,
> then sent as a short-lived `Bearer` token to the AI service. Tokens are cached and refreshed
> automatically by auth-bridge.

Your service must be registered in the AI service and granted `workflows.all` or `workflows.{key}`
for each workflow it uses.

## Usage

### Dependency injection (recommended)

```php
use Esanj\AiClient\Contracts\AiClientInterface;

class SummaryController
{
    public function __construct(private AiClientInterface $ai) {}

    public function store()
    {
        $output = $this->ai->run('summarize', ['text' => request('text')]);

        return $output->data;
    }
}
```

You can also inject `WorkflowClientInterface` / `ExecutionClientInterface` directly.

### Facade

```php
use Esanj\AiClient\Facades\Ai;   // Ai::workflows() / Ai::executions() / Ai::run()
```

### How it fits together

```
workflows()->start('key')  ──▶  execution uuid  ──▶  executions()->run(uuid, input)  ──▶  output
                                    │                     (again and again for a conversation)
                                    └──▶ or hand executions()->runUrl(uuid) to the browser
```

Starting an execution runs nothing; each `run()` sends one message. A **single** workflow runs from
start to end on every message. A **conversation** workflow runs fully on the first message and then
continues with its last text agent, keeping the history.

### One-shot request

```php
$output = Ai::run('summarize', ['text' => $article]);   // start + run

$output->isJson();        // the workflow's result type is JSON
$output->data;            // the workflow's JSON structure, resolved
$output->executionUuid;   // the execution that answered
```

### Conversation

```php
use Esanj\AiClient\Facades\Ai;

$execution = Ai::workflows()->start('support-chat');
$execution->uuid;                                   // store it with the user's chat

$output = Ai::executions()->run($execution->uuid, ['message' => 'Hi, my order is late']);
$output->lastReply();                               // the agent's answer
$output->messages;                                  // ConversationMessage[] — the whole history

Ai::executions()->run($execution->uuid, ['message' => 'Order #1234']);   // continues the chat
```

### Template workflows (HTML)

A workflow whose result type is *template* answers with a rendered HTML page:

```php
$output = Ai::executions()->run($uuid, ['message' => 'Hello']);

if ($output->isTemplate()) {
    return response($output->html);
}
```

To let the user talk to the workflow directly from the browser, hand them the run URL. It needs no
token — the execution uuid is the credential:

```php
$url = Ai::executions()->runUrl($execution->uuid);
// POST {"input": {"message": "..."}}, or a template's form posts its fields there
```

### Listing and inspecting

```php
$page = Ai::workflows()->list(search: 'chat', page: 1, perPage: 30);   // PaginatedResult<WorkflowResource>
foreach ($page as $workflow) {
    $workflow->key; $workflow->name; $workflow->type; $workflow->resultType; $workflow->isActive;
}

$executions = Ai::executions()->list();           // newest first
$execution  = Ai::executions()->show($uuid);      // with its runs
$log = $execution->currentLog();                  // ExecutionLogResource
$log->inputParams; $log->outputParams; $log->errorMessage; $log->isFailed();

Ai::executions()->output($uuid, $log->uuid);      // render a past run again
Ai::executions()->retry($uuid, $log->uuid);       // run the workflow again with that run's input
```

## Error Handling

Every failure throws a subclass of `Esanj\AiClient\Exceptions\AiException`:

| Exception | When |
| --- | --- |
| `AiAuthenticationException` | Client credentials missing/invalid; the OAuth server rejected the token request. |
| `AiApiException` | The AI service returned an error response. Carries `statusCode`, `responseBody`, `getErrors()`. |
| `AiOutcomeUnknownException` | A `run` or `retry` hit a server/connection error and may have run (extends `AiApiException`). |
| `AiUnexpectedResponseException` | The service answered with something other than the expected JSON or HTML (e.g. a proxy page). |

```php
use Esanj\AiClient\Exceptions\AiApiException;
use Esanj\AiClient\Exceptions\AiAuthenticationException;
use Esanj\AiClient\Exceptions\AiOutcomeUnknownException;

try {
    $output = Ai::executions()->run($uuid, ['message' => $text]);
} catch (AiOutcomeUnknownException $e) {
    // the workflow failed or the connection dropped mid-run; check executions()->show($uuid)
} catch (AiApiException $e) {
    if ($e->isForbidden())             { /* workflow inactive, service inactive, or no access */ }
    elseif ($e->isNotFound())          { /* unknown workflow key or execution uuid */ }
    elseif ($e->isValidationError())   { $errors = $e->getErrors(); }   // 422
    elseif ($e->isTooManyRequests())   { /* 60 requests/minute */ }
    else                               { report($e); }
} catch (AiAuthenticationException $e) {
    // credentials / OAuth problem
}
```

A rejected token (401) is invalidated, re-fetched and the call retried. Server (5xx), connection and
interrupted-transfer failures are retried according to the `retry` config for `list`, `show`,
`output` and `start` (a repeated `start` at worst leaves an extra execution that never runs).
`run` and `retry` are never repeated automatically: they cost AI usage and add a message to a
conversation.

## API surface

| Method | HTTP | Endpoint |
| --- | --- | --- |
| `workflows()->list(?string $search, int $page, int $perPage)` | GET | `/api/v1/application/workflows` |
| `workflows()->start(string $key)` | POST | `/api/v1/application/workflows/{key}/executions` |
| `executions()->list(?string $search, int $page, int $perPage)` | GET | `/api/v1/application/executions` |
| `executions()->show(string $uuid)` | GET | `/api/v1/application/executions/{uuid}` |
| `executions()->retry(string $uuid, string $logUuid)` | POST | `/api/v1/application/executions/{uuid}/logs/{log}/retry` |
| `executions()->output(string $uuid, string $logUuid)` | GET | `/api/v1/application/executions/{uuid}/logs/{log}/interface` |
| `executions()->run(string $uuid, array $input)` | POST | `/api/v1/client/executions/{uuid}/run` (no token) |
| `run(string $workflowKey, array $input)` | — | `start` + `executions()->run` |

See [`docs/GUIDE.md`](docs/GUIDE.md) for a full walkthrough.

## License

MIT
