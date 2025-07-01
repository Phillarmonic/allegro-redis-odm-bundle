<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Query;

use Countable;
use IteratorAggregate;
use Phillarmonic\AllegroRedisOdmBundle\Repository\DocumentRepository;

/**
 * Represents a paginated result set.
 * Now supports lazy hydration and plucking specific fields.
 */
class PaginatedResult implements Countable, IteratorAggregate
{
    private ?array $hydratedResults = null;

    /**
     * @param array $resultIds The current page of document IDs
     * @param int $totalCount Total count of all results
     * @param int $limit Number of items per page
     * @param int $offset Starting position
     * @param DocumentRepository $repository The repository that created this result
     */
    public function __construct(
        private array $resultIds,
        private int $totalCount,
        private int $limit,
        private int $offset,
        private DocumentRepository $repository
    ) {
    }

    /**
     * Get the result items for the current page.
     * Hydrates the documents on the first call.
     *
     * @return array
     */
    public function getResults(): array
    {
        if ($this->hydratedResults === null) {
            if (empty($this->resultIds)) {
                $this->hydratedResults = [];
            } else {
                $this->hydratedResults = $this->repository->findByIds(
                    $this->resultIds
                );
            }
        }
        return $this->hydratedResults;
    }

    /**
     * Fetch only specific fields for the documents in the result set.
     * This is a memory-efficient operation that bypasses full object hydration.
     *
     * @param array $fields An array of property names to fetch.
     * @return array An array of associative arrays, each containing the plucked data.
     */
    public function pluck(array $fields): array
    {
        if (empty($this->resultIds)) {
            return [];
        }

        $metadata = $this->repository->getMetadata();
        $documentManager = $this->repository->getDocumentManager();
        $redisClient = $documentManager->getRedisClient();

        // Ensure the ID field is always available for context
        $idProperty = $metadata->idField;
        if (!in_array($idProperty, $fields)) {
            $fields[] = $idProperty;
        }

        $pluckedData = [];

        if ($metadata->storageType === 'hash') {
            // Highly efficient for hash storage using HMGET
            $redisFields = [];
            foreach ($fields as $property) {
                if ($property === $idProperty) continue;
                $fieldName = $metadata->getFieldName($property);
                if ($fieldName) {
                    $redisFields[$property] = $fieldName;
                }
            }

            $redisClient->multi();
            foreach ($this->resultIds as $id) {
                $key = $metadata->getKeyName($id);
                $redisClient->hMGet($key, array_values($redisFields));
            }
            $responses = $redisClient->exec();

            foreach ($this->resultIds as $index => $id) {
                $rowData = [$idProperty => $id];
                $redisValues = $responses[$index] ?? [];
                $i = 0;
                foreach ($redisFields as $prop => $redisField) {
                    // hMGet returns false for a non-existent field in the hash
                    $rowData[$prop] = $redisValues[$i] !== false ? $redisValues[$i] : null;
                    $i++;
                }
                $pluckedData[] = $rowData;
            }
        } elseif ($metadata->storageType === 'json') {
            // Less efficient for JSON, but still saves PHP memory by avoiding hydration
            $redisClient->multi();
            foreach ($this->resultIds as $id) {
                $key = $metadata->getKeyName($id);
                $redisClient->get($key);
            }
            $responses = $redisClient->exec();

            foreach ($this->resultIds as $index => $id) {
                $rowData = [$idProperty => $id];
                $jsonString = $responses[$index] ?? null;
                if ($jsonString) {
                    $decodedData = json_decode($jsonString, true);
                    foreach ($fields as $property) {
                        if ($property === $idProperty) continue;
                        $fieldName = $metadata->getFieldName($property);
                        if ($fieldName && isset($decodedData[$fieldName])) {
                            $rowData[$property] = $decodedData[$fieldName];
                        } else {
                            $rowData[$property] = null;
                        }
                    }
                }
                $pluckedData[] = $rowData;
            }
        }

        return $pluckedData;
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
        return empty($this->resultIds);
    }

    /**
     * Get the first result or null if empty
     *
     * @return mixed
     */
    public function getFirst()
    {
        return $this->getResults()[0] ?? null;
    }

    /**
     * Implement Countable interface
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->resultIds);
    }

    /**
     * Implement IteratorAggregate interface
     *
     * @return \ArrayIterator
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->getResults());
    }

    /**
     * Convert to array with pagination metadata
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'results' => $this->getResults(),
            'pagination' => [
                'total_count' => $this->totalCount,
                'offset' => $this->offset,
                'limit' => $this->limit,
                'current_page' => $this->getCurrentPage(),
                'total_pages' => $this->getTotalPages(),
                'has_next_page' => $this->hasNextPage(),
                'has_previous_page' => $this->hasPreviousPage(),
            ],
        ];
    }
}