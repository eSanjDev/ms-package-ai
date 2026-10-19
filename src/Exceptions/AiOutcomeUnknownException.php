<?php

namespace Esanj\AiClient\Exceptions;

/**
 * A call that changes state failed with a server or connection error after it may have reached
 * the service, so it may or may not have been applied. It is not retried automatically: running a
 * workflow costs AI usage and adds a message to a conversation, so check the execution (for example
 * with executions()->show()) before repeating it.
 */
class AiOutcomeUnknownException extends AiApiException {}
