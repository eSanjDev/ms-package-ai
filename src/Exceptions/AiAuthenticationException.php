<?php

namespace Esanj\AiClient\Exceptions;

use Throwable;

class AiAuthenticationException extends AiException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 401, $previous);
    }
}
