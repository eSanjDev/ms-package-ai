<?php

namespace Esanj\AiClient\Enums;

/**
 * How a workflow answers a run.
 */
enum ResultType: string
{
    /** A JSON object built from the workflow's JSON structure. */
    case Json = 'json';

    /** A rendered HTML page built from the workflow's template. */
    case Template = 'template';

    public function isJson(): bool
    {
        return $this === self::Json;
    }

    public function isTemplate(): bool
    {
        return $this === self::Template;
    }
}
