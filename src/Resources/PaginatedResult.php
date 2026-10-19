<?php

namespace Esanj\AiClient\Resources;

use ArrayIterator;
use Countable;
use Esanj\AiClient\Exceptions\AiUnexpectedResponseException;
use IteratorAggregate;
use Traversable;

/**
 * One page of a list endpoint.
 *
 * @template T
 * @implements IteratorAggregate<int, T>
 */
final class PaginatedResult implements IteratorAggregate, Countable
{
    /**
     * @param T[] $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
        public readonly int $lastPage,
    ) {}

    /**
     * @template R
     * @param callable(mixed): R $itemMapper Maps one raw item to its resource.
     * @return self<R>
     */
    public static function fromArray(array $response, callable $itemMapper): self
    {
        $items = $response['data'] ?? null;

        if (!is_array($items) || !array_is_list($items)) {
            throw new AiUnexpectedResponseException('The AI service response has no "data" list.');
        }

        $meta = ResponsePayload::of($response['meta'] ?? null);

        return new self(
            items:       array_map($itemMapper, $items),
            total:       $meta->nullableInt('total') ?? count($items),
            perPage:     $meta->nullableInt('per_page') ?? count($items),
            currentPage: $meta->nullableInt('current_page') ?? 1,
            lastPage:    $meta->nullableInt('last_page') ?? 1,
        );
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    public function nextPage(): ?int
    {
        return $this->hasMorePages() ? $this->currentPage + 1 : null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
