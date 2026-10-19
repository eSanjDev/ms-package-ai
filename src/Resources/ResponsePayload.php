<?php

namespace Esanj\AiClient\Resources;

use BackedEnum;
use Esanj\AiClient\Exceptions\AiUnexpectedResponseException;

/**
 * Reads one object of a service response, rejecting fields that are missing or of the wrong type
 * instead of casting them to a default.
 *
 * @internal
 */
final class ResponsePayload
{
    private function __construct(private readonly array $fields) {}

    public static function of(mixed $payload): self
    {
        if (!is_array($payload)) {
            throw new AiUnexpectedResponseException('The AI service response has no data object.');
        }

        return new self($payload);
    }

    public function string(string $key): string
    {
        $value = $this->nullableString($key);

        return $value === null || $value === '' ? throw $this->invalid($key) : $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->fields[$key] ?? null;

        return $value === null || is_string($value) ? $value : throw $this->invalid($key);
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->fields[$key] ?? null;

        return match (true) {
            $value === null, is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => throw $this->invalid($key),
        };
    }

    public function bool(string $key): bool
    {
        $value = $this->fields[$key] ?? null;

        return is_bool($value) ? $value : throw $this->invalid($key);
    }

    /**
     * @template T of BackedEnum
     * @param class-string<T> $enum
     * @return T
     */
    public function enum(string $key, string $enum): BackedEnum
    {
        return $enum::tryFrom($this->string($key)) ?? throw $this->invalid($key);
    }

    /**
     * An optional array; a missing or null field is an empty one.
     */
    public function array(string $key): array
    {
        return $this->nullableArray($key) ?? [];
    }

    public function nullableArray(string $key): ?array
    {
        $value = $this->fields[$key] ?? null;

        return $value === null || is_array($value) ? $value : throw $this->invalid($key);
    }

    private function invalid(string $key): AiUnexpectedResponseException
    {
        return new AiUnexpectedResponseException(
            "The AI service response has a missing or invalid \"{$key}\" field."
        );
    }
}
