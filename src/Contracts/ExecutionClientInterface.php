<?php

namespace Esanj\AiClient\Contracts;

use Esanj\AiClient\Resources\ExecutionOutput;
use Esanj\AiClient\Resources\ExecutionResource;
use Esanj\AiClient\Resources\PaginatedResult;

interface ExecutionClientInterface
{
    /**
     * The executions the calling service started, newest first. $search matches part of the uuid.
     *
     * @return PaginatedResult<ExecutionResource>
     */
    public function list(?string $search = null, int $page = 1, int $perPage = 30): PaginatedResult;

    /**
     * Fetch an execution with its runs.
     */
    public function show(string $uuid): ExecutionResource;

    /**
     * Send a message to an execution. The first message runs the whole workflow; in a
     * conversation the next ones continue with its last text agent. Not retried on a server or
     * connection error, which surfaces as an AiOutcomeUnknownException.
     *
     * @param string                 $accessToken The execution's token, from start() or issueToken().
     * @param array<string, ?string> $input       The workflow's input fields.
     */
    public function run(string $uuid, string $accessToken, array $input): ExecutionOutput;

    /**
     * Run the whole workflow again with the input of one of the execution's runs; the new run
     * becomes the current one. Not retried on a server or connection error.
     */
    public function retry(string $uuid, string $logUuid): ExecutionResource;

    /**
     * The output of one of the execution's runs, rendered the way the run answered it.
     */
    public function output(string $uuid, string $logUuid): ExecutionOutput;

    /**
     * The public URL that runs the execution, for handing to a browser: a template's form posts
     * its fields there, and API callers post {"input": {...}}. Hand the access token over
     * separately: it goes in an "Authorization: Bearer" header, or an "access_token" form field.
     * Never put it in the URL, where it leaks into history, logs and the Referer header.
     */
    public function runUrl(string $uuid): string;
}
