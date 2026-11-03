# 📚 Esanj AI Client — Complete Guide

A step-by-step guide to running **AI workflows** of the Esanj AI microservice — one-shot requests
and multi-message conversations — from any Laravel application.

## Table of Contents

1. [What this package does](#1-what-this-package-does)
2. [How it works (the big picture)](#2-how-it-works-the-big-picture)
3. [Installation](#3-installation)
4. [Configuration & `.env`](#4-configuration--env)
5. [Three ways to call the client](#5-three-ways-to-call-the-client)
6. [Workflows and executions](#6-workflows-and-executions)
7. [Single workflows: one-shot requests](#7-single-workflows-one-shot-requests)
8. [Conversation workflows](#8-conversation-workflows)
9. [JSON and template results](#9-json-and-template-results)
10. [Running from the browser](#10-running-from-the-browser)
11. [Inspecting, re-rendering and retrying runs](#11-inspecting-re-rendering-and-retrying-runs)
12. [Handling errors safely](#12-handling-errors-safely)
13. [Configuration reference](#13-configuration-reference)
14. [API endpoints used](#14-api-endpoints-used)
15. [Troubleshooting](#15-troubleshooting)

---

## 1. What this package does

It is a thin, typed HTTP client for the AI microservice. You call PHP methods; it authenticates,
sends the request, and hands you back typed response objects instead of raw arrays. You never build
URLs, attach bearer tokens, or `json_decode` responses yourself.

The AI service is where admins build **workflows**: chains of AI agents, modules and formulas that
take a few input fields and produce a result. Your application doesn't know or care what is inside a
workflow — it knows the workflow's **key**, the input fields it expects, and the shape of its result.

The service exposes two resources, as two sub-clients:

- **Workflows** — list the workflows your service may use, and start executions of them.
- **Executions** — run an execution (send it a message), inspect its runs, re-render or retry one.

## 2. How it works (the big picture)

```
Your app ──────────▶ Ai::workflows() / Ai::executions()
                                      │
                     ┌────────────────┴───────────────────┐
                     ▼                                     ▼
             AuthBridgeTokenProvider                    ApiClient (Guzzle)
             (esanj/auth-bridge)                        + Authorization: Bearer <jwt>
                     │                                     │
    client_credentials grant                   /api/v1/application/...
                     ▼                                     ▼
        OAuth server (ACCOUNTING_BRIDGE_BASE_URL)     AI microservice
        issues a signed JWT  ───────────────────▶  validates the JWT and responds
```

1. **Token** — `AuthBridgeTokenProvider` asks `esanj/auth-bridge` for an access token using the
   `client_credentials` grant with your `AI_CLIENT_ID` / `AI_CLIENT_SECRET`.
2. **Call** — `ApiClient` sends the request to the AI service with that token as a `Bearer` header.
   The service validates the JWT and derives your service identity from it (you never send a
   service id yourself). Only the run endpoint is different: it takes the execution's own access
   token, which `start()` returns, instead of the service token.
3. **Response** — the raw JSON (or HTML, for template workflows) is mapped into a typed object.

### The access token is short-lived — and renewed for you

- `esanj/auth-bridge` **caches** the token keyed by client id + scope, treating it as expired ~60s
  early. While it is valid, every call reuses it.
- When the cached token **expires**, the next call transparently obtains a fresh one.
- If the AI service ever **rejects** a token (HTTP 401), `ApiClient` invalidates the cached token
  and retries the request with a freshly minted one. This is safe for every call: the service
  checks the token before it handles the request.

## 3. Installation

```bash
composer require esanj/ai-client
```

`esanj/auth-bridge` is a dependency and is installed with it.

Your service must also be **registered in the AI service** (by its OAuth client id) and granted a
permission per workflow — `workflows.{key}` — or `workflows.all` for every workflow. Without it,
`start` and `run` fail with 403 and `workflows()->list()` comes back empty.

## 4. Configuration & `.env`

```dotenv
AI_SERVICE_URL=https://ai.esanj.io
AI_CLIENT_ID=your-service-client-id
AI_CLIENT_SECRET=your-service-client-secret

# Optional
AI_SCOPE=*
AI_TIMEOUT=120
AI_RETRY_ATTEMPTS=3
AI_RETRY_SLEEP_MS=1000
AI_LOG_CHANNEL=

# auth-bridge (the OAuth server that issues the token)
ACCOUNTING_BRIDGE_BASE_URL=https://accounting.esanj.io
```

Publish the config file if you want to edit defaults directly:

```bash
php artisan vendor:publish --tag=ai-config   # → config/esanj/ai.php
```

> A run waits for every AI agent of the workflow to answer — often many seconds. Keep `AI_TIMEOUT`
> above your slowest workflow, and call long workflows from a queued job rather than a web request.

## 5. Three ways to call the client

All three resolve the same singletons.

**The aggregate client** — one dependency for everything:

```php
use Esanj\AiClient\Contracts\AiClientInterface;

public function __construct(private AiClientInterface $ai) {}

$this->ai->workflows()->start('summarize');
$this->ai->executions()->run($uuid, $token, ['text' => $text]);
$this->ai->run('summarize', ['text' => $text]);   // start + run
```

**A focused client** — when a class only needs one resource:

```php
use Esanj\AiClient\Contracts\ExecutionClientInterface;
use Esanj\AiClient\Contracts\WorkflowClientInterface;
```

**The facade**:

```php
use Esanj\AiClient\Facades\Ai;

Ai::workflows()->list();
Ai::executions()->show($uuid);
Ai::run('summarize', ['text' => $text]);
```

## 6. Workflows and executions

```
workflows()->start('key')  ──▶  ExecutionResource { uuid, accessToken }
                                       │
             executions()->run(uuid, token, input)  ──▶  ExecutionOutput     ← one message
             executions()->run(uuid, token, input)  ──▶  ExecutionOutput     ← next message (conversations)
```

- A **workflow** is identified by its `key`. `workflows()->list()` returns those your service may use:

  ```php
  foreach (Ai::workflows()->list() as $workflow) {   // PaginatedResult<WorkflowResource>
      $workflow->key;          // 'support-chat'
      $workflow->name;         // 'Support chat'
      $workflow->type;         // WorkflowType::Single | WorkflowType::Conversation
      $workflow->resultType;   // ResultType::Json | ResultType::Template
      $workflow->isActive;
  }
  ```

- An **execution** is one use of a workflow, owned by your service. `start()` only opens it —
  nothing runs and no AI usage is spent until the first `run()`.
- Each `run()` is one **message**, recorded on the execution as a run (an `ExecutionLogResource`).
- `start()` returns the execution's **access token** once, in `accessToken`, with its expiry in
  `accessTokenExpiresAt` (`EXECUTION_ACCESS_TOKEN_TTL` on the service, a day by default). Every
  `run()` needs it. The service stores only its hash, so `show()` and `list()` never return it:

  ```php
  $execution = Ai::executions()->issueToken($uuid);   // a new token; the previous one stops working
  $execution->accessToken;

  Ai::executions()->revokeToken($uuid);               // no more messages until a new token is issued
  ```

The input is a flat map of the workflow's input fields to strings (up to 100 fields, each up to
50,000 characters). HTML tags are stripped by the service.

## 7. Single workflows: one-shot requests

For a workflow that answers each request on its own, `run()` on the aggregate client starts an
execution and sends the input in one call:

```php
$output = Ai::run('summarize', ['text' => $article]);

$output->data;            // e.g. ['summary' => '…', 'keywords' => ['…']]
$output->executionUuid;   // keep it if you want to look the run up later
$output->accessToken;     // with this, to send the execution another message
```

Sending another message to the same execution of a single workflow runs the whole workflow again
with the new input.

## 8. Conversation workflows

A conversation workflow runs fully on the first message, then continues with its last text agent,
which remembers the conversation. Start the execution once, store its uuid and access token with
your own conversation record (the token is a secret: use an `encrypted` cast), and send each message
to it:

```php
$execution = Ai::workflows()->start('support-chat');
$chat->update(['ai_execution' => $execution->uuid, 'ai_token' => $execution->accessToken]);

$output = Ai::executions()->run($chat->ai_execution, $chat->ai_token, ['message' => 'Hi, my order is late']);
$output->lastReply();     // 'Sorry to hear that — what is your order number?'

$output = Ai::executions()->run($chat->ai_execution, $chat->ai_token, ['message' => '#1234']);
foreach ($output->messages as $message) {   // ConversationMessage[], oldest first
    $message->input;      // ['message' => '…']
    $message->reply;      // the agent's answer
}
```

Failed messages are left out of `messages`.

When the token expires, `run()` fails with a 401 (`isUnauthorized()`); issue a new one and store it:

```php
$chat->update(['ai_token' => Ai::executions()->issueToken($chat->ai_execution)->accessToken]);
```

## 9. JSON and template results

A workflow's `resultType` decides how it answers:

| Result type | `ExecutionOutput` |
| --- | --- |
| `ResultType::Json` | `data` — the workflow's JSON structure with its placeholders resolved; `messages` for conversations. |
| `ResultType::Template` | `html` — a full HTML page rendered from the workflow's template. |

```php
$output = Ai::executions()->run($uuid, $token, $input);

if ($output->isTemplate()) {
    return response($output->html);
}

return response()->json($output->data);
```

## 10. Running from the browser

The run endpoint needs no service token: whoever holds an execution's uuid **and** its access token
can send it messages. That lets your front end talk to a workflow directly, without going through
your server. Hand the URL and the token over as separate values:

```php
$execution = Ai::workflows()->start('support-chat');

return view('chat', [
    'runUrl'   => Ai::executions()->runUrl($execution->uuid),
    'runToken' => $execution->accessToken,
]);
```

- API callers `POST` `{"input": {"message": "…"}}` to the URL with an `Authorization: Bearer <token>`
  header and get the JSON or HTML back.
- A template's own form posts its fields there with the token in an `access_token` field, so a
  template page works on its own once loaded.

Never append the token to the URL's query string: it would leak into the browser history, server
logs and the `Referer` header. Hand it only to the user the execution belongs to, and
`revokeToken()` it when they no longer need it.

> The run endpoint is throttled per execution (`EXECUTION_RATE_LIMIT` on the service, 10/minute by
> default) and to **60 requests/minute per IP**. When your server calls `run()` for many users, they
> all share your server's IP — call from the browser, or spread the load, when you expect more than that.

## 11. Inspecting, re-rendering and retrying runs

```php
$page = Ai::executions()->list(search: '9d0c', page: 1, perPage: 30);   // newest first

$execution = Ai::executions()->show($uuid);
$execution->workflow;          // the workflow's name
$execution->logs;              // ExecutionLogResource[], oldest first

$log = $execution->currentLog();
$log->inputParams;             // what was sent
$log->outputParams;            // what each step produced, keyed by step id; null until completed
$log->errorMessage;            // why it failed
$log->isFailed(); $log->isCompleted(); $log->isRunning();

// Render a past run again, the way it answered (JSON or HTML):
$output = Ai::executions()->output($uuid, $log->uuid);

// Run the whole workflow again with that run's input; the new run becomes the current one:
$execution = Ai::executions()->retry($uuid, $log->uuid);
```

## 12. Handling errors safely

Every failure throws a subclass of `Esanj\AiClient\Exceptions\AiException`:

| Exception | When |
| --- | --- |
| `AiAuthenticationException` | Client credentials missing/invalid; the OAuth server rejected the token request. |
| `AiApiException` | The AI service returned an error response. Carries `statusCode`, `responseBody`, `getErrors()`. |
| `AiOutcomeUnknownException` | A `run` or `retry` hit a server/connection error and may have run (extends `AiApiException`). |
| `AiUnexpectedResponseException` | The service answered with something other than the expected JSON or HTML. |

```php
try {
    $output = Ai::executions()->run($uuid, $token, ['message' => $text]);
} catch (AiOutcomeUnknownException $e) {
    // A workflow step threw (the service answers 500), or the connection dropped mid-run.
    $log = Ai::executions()->show($uuid)->currentLog();
    $log?->errorMessage;
} catch (AiApiException $e) {
    match (true) {
        $e->isUnauthorized()    => /* the execution's token expired or was revoked — issueToken() */,
        $e->isForbidden()       => /* workflow or service inactive, or no access — see getMessage() */,
        $e->isNotFound()        => /* unknown workflow key or execution uuid */,
        $e->isValidationError() => /* $e->getErrors(), e.g. ['input' => ['…']] */,
        $e->isTooManyRequests() => /* rate limit */,
        default                 => report($e),
    };
}
```

### What is retried

| Call | On 401 | On 5xx / connection error |
| --- | --- | --- |
| `workflows()->list()`, `executions()->list()`, `show()`, `output()` | refresh token, retry | retry |
| `workflows()->start()` | refresh token, retry | retry — at worst an extra execution that never runs |
| `executions()->issueToken()`, `revokeToken()` | refresh token, retry | retry — a repeated issue replaces the token again |
| `executions()->retry()` | refresh token, retry | **not retried** → `AiOutcomeUnknownException` |
| `executions()->run()` | **not retried** — renew the execution token with `issueToken()` | **not retried** → `AiOutcomeUnknownException` |

`run` and `retry` cost AI usage and add a message to a conversation, so the client never repeats
them on its own. Don't wrap them in your own retry loop without checking the execution first.

## 13. Configuration reference

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `base_url` | `AI_SERVICE_URL` | `http://localhost` | AI service base URL. |
| `client_id` | `AI_CLIENT_ID` | — | Service OAuth client id. |
| `client_secret` | `AI_CLIENT_SECRET` | — | Service OAuth client secret. |
| `scope` | `AI_SCOPE` | `*` | OAuth scope requested. |
| `timeout` | `AI_TIMEOUT` | `120` | HTTP timeout (seconds). |
| `retry.attempts` | `AI_RETRY_ATTEMPTS` | `3` | Total attempts for calls safe to repeat (1 = no retry). |
| `retry.sleep_ms` | `AI_RETRY_SLEEP_MS` | `1000` | Delay between retries (ms). |
| `logging.channel` | `AI_LOG_CHANNEL` | app default | Log channel for the client. |

The OAuth server URL itself comes from the auth-bridge config (`ACCOUNTING_BRIDGE_BASE_URL`).
A published config may override only some nested keys (e.g. just `retry.attempts`); the others keep
their defaults.

## 14. API endpoints used

| Client method | HTTP | Path | Body / query |
| --- | --- | --- | --- |
| `workflows()->list()` | GET | `/api/v1/application/workflows` | `?search&page&per_page` |
| `workflows()->start()` | POST | `/api/v1/application/workflows/{key}/executions` | — |
| `executions()->list()` | GET | `/api/v1/application/executions` | `?search&page&per_page` |
| `executions()->show()` | GET | `/api/v1/application/executions/{uuid}` | — |
| `executions()->retry()` | POST | `/api/v1/application/executions/{uuid}/logs/{log}/retry` | — |
| `executions()->output()` | GET | `/api/v1/application/executions/{uuid}/logs/{log}/interface` | — |
| `executions()->issueToken()` | POST | `/api/v1/application/executions/{uuid}/token` | — |
| `executions()->revokeToken()` | DELETE | `/api/v1/application/executions/{uuid}/token` | — |
| `executions()->run()` | POST | `/api/v1/client/executions/{uuid}/run` | `{ input: { field: value } }` |

The `application` endpoints require the service's `Bearer` token and are throttled to 60
requests/minute per service; the `client` run endpoint requires the execution's access token as a
`Bearer` header (or an `access_token` form field) and is throttled per execution and per IP.

## 15. Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `AiAuthenticationException: credentials are not configured` | `AI_CLIENT_ID` / `AI_CLIENT_SECRET` not set. |
| `AiAuthenticationException` on every call | Wrong credentials, or `ACCOUNTING_BRIDGE_BASE_URL` points to the wrong OAuth server. |
| `AiApiException` with 401 after retries | The JWT is rejected by the AI service (clock skew, wrong signing key, wrong audience). |
| `AiApiException` with 401 on `run` | The execution's access token expired or was revoked; get a new one with `issueToken()`. |
| 403 "The calling service is not active." | Your service isn't registered in the AI service, or is deactivated there. |
| 403 "The service has no access to this workflow." | Grant `workflows.{key}` or `workflows.all` to your service in the AI service. |
| 403 "Workflow is not active." | An admin deactivated the workflow. |
| `workflows()->list()` is empty | Your service has no `workflows.*` permission. |
| `isValidationError()` (422) on `input` | Empty input, more than 100 fields, or a value that isn't a string. |
| `AiOutcomeUnknownException` (500) on `run` | A workflow step failed; `show($uuid)->currentLog()->errorMessage` says why. |
| `AiOutcomeUnknownException` (status 0) on `run` | The connection dropped or the workflow took longer than `AI_TIMEOUT`; it may still finish on the service. |
| `AiUnexpectedResponseException` | `AI_SERVICE_URL` points at something other than the service, or a proxy answered with a page. |
