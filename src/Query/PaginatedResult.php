<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Query;

/**
 * Represents a paginated result set
 */
class PaginatedResult implements \Countable, \IteratorAggregate
{
    /**
     * @param array $results The current page of results
     * @param int $totalCount Total count of all results (not just current page)
     * @param int $limit Number of items per page (0 means no limit)
     * @param int $offset Starting position
     */
    public function __construct(
        private array $results,
        private int $totalCount,
        private int $limit = 0,
        private int $offset = 0
    ) {
    }

    /**
     * Get the result items for the current page
     *
     * @return array
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get total number of results across all pages
     *
     * @return int
     */
    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    /**
     * Get number of items per page
     *
     * @return int
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get current page offset
     *
     * @return int
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * Get current page number (1-based)
     *
     * @return int
     */
    public function getCurrentPage(): int
    {
        if ($this->limit <= 0) {
            return 1;
        }

        return floor($this->offset / $this->limit) + 1;
    }

    /**
     * Get total number of pages
     *
     * @return int
     */
    public function getTotalPages(): int
    {
        if ($this->limit <= 0) {
            return 1;
        }

        return (int) ceil($this->totalCount / $this->limit);
    }

    /**
     * Check if there is a next page
     *
     * @return bool
     */
    public function hasNextPage(): bool
    {
        if ($this->limit <= 0) {
            return false;
        }

        return ($this->offset + $this->limit) < $this->totalCount;
    }

    /**
     * Check if there is a previous page
     *
     * @return bool
     */
    public function hasPreviousPage(): bool
    {
        return $this->offset > 0;
    }

    /**
     * Get next page offset
     *
     * @return int|null
     */
    public function getNextPageOffset(): ?int
    {
        if (!$this->hasNextPage()) {
            return null;
        }

        return $this->offset + $this->limit;
    }

    /**
     * Get previous page offset
     *
     * @return int|null
     */
    public function getPreviousPageOffset(): ?int
    {
        if (!$this->hasPreviousPage()) {
            return null;
        }

        return max(0, $this->offset - $this->limit);
    }

    /**
     * Check if the result set is empty
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->results);
    }

    /**
     * Get the first result or null if empty
     *
     * @return mixed
     */
    public function getFirst()
    {
        return $this->results[0] ?? null;
    }

    /**
     * Implement Countable interface
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->results);
    }

    /**
     * Implement IteratorAggregate interface
     *
     * @return \ArrayIterator
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->results);
    }

    /**
     * Convert to array with pagination metadata
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'results' => $this->results,
            'pagination' => [
                'total_count' => $this->totalCount,
                'offset' => $this->offset,
                'limit' => $this->limit,
                'current_page' => $this->getCurrentPage(),
                'total_pages' => $this->getTotalPages(),
                'has_next_page' => $this->hasNextPage(),
                'has_previous_page' => $this->hasPreviousPage(),
            ]
        ];
    }
}