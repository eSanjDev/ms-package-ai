<?php

namespace Esanj\AiClient\Exceptions;

/**
 * The service answered, but not with the response this client expects (for example an HTML page
 * from a proxy, or a body missing required fields).
 */
class AiUnexpectedResponseException extends AiException {}
