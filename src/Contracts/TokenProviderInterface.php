<?php

namespace Esanj\AiClient\Contracts;

use Esanj\AiClient\Exceptions\AiAuthenticationException;

interface TokenProviderInterface
{
    /**
     * Return the "Bearer xxx" Authorization header for the AI service.
     *
     * @throws AiAuthenticationException
     */
    public function authorizationHeader(): string;

    /**
     * Drop the currently cached token so the next call fetches a fresh one.
     */
    public function invalidate(): void;
}
