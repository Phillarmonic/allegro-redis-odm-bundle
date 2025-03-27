<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Query;

use Phillarmonic\AllegroRedisOdmBundle\Repository\DocumentRepository;

/**
 * Range query for sorted indices
 */
class RangeQuery
{
    /**
     * @var string The field to query (must have a SortedIndex)
     */
    private string $field;

    /**
     * @var float|null Minimum value (inclusive)
     */
    private ?float $min = null;

    /**
     * @var float|null Maximum value (inclusive)
     */
    private ?float $max = null;

    /**
     * @var array Additional filter criteria
     */
    private array $criteria = [];

    /**
     * @var array|null Ordering criteria
     */
    private ?array $orderBy = null;

    /**
     * @var int|null Maximum number of results
     */
    private ?int $limit = null;

    /**
     * @var int|null Offset for pagination
     */
    private ?int $offset = null;

    /**
     * @var bool Whether to include documents that match min value
     */
    private bool $includeMin = true;

    /**
     * @var bool Whether to include documents that match max value
     */
    private bool $includeMax = true;

    /**
     * Create a new RangeQuery for a specific field
     *
     * @param string $field Field with a SortedIndex
     * @return self
     */
    public static function create(string $field): self
    {
        $query = new self();
        $query->field = $field;
        return $query;
    }

    /**
     * Set the minimum value for the range
     *
     * @param float $min Minimum value
     * @param bool $inclusive Whether to include values equal to min
     * @return self
     */
    public function min(float $min, bool $inclusive = true): self
    {
        $this->min = $min;
        $this->includeMin = $inclusive;
        return $this;
    }

    /**
     * Set the maximum value for the range
     *
     * @param float $max Maximum value
     * @param bool $inclusive Whether to include values equal to max
     * @return self
     */
    public function max(float $max, bool $inclusive = true): self
    {
        $this->max = $max;
        $this->includeMax = $inclusive;
        return $this;
    }

    /**
     * Add a field criteria
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @return self
     */
    public function andWhere(string $field, $value): self
    {
        $this->criteria[$field] = $value;
        return $this;
    }

    /**
     * Add ordering
     *
     * @param string $field Field name
     * @param string $direction 'ASC' or 'DESC'
     * @return self
     */
    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $this->orderBy = $this->orderBy ?? [];
        $this->orderBy[$field] = $direction;
        return $this;
    }

    /**
     * Set maximum number of results
     *
     * @param int $limit
     * @return self
     */
    public function setMaxResults(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the first result offset
     *
     * @param int $offset
     * @return self
     */
    public function setFirstResult(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Execute the range query
     *
     * @param DocumentRepository $repository
     * @return PaginatedResult
     */
    public function execute(DocumentRepository $repository): PaginatedResult
    {
        // Get access to the document manager and metadata
        $documentManager = $repository->getDocumentManager();
        $metadata = $repository->getMetadata();
        $redisClient = $documentManager->getRedisClient();

        // Check if the field has a sorted index
        if (!isset($metadata->sortedIndices[$this->field])) {
            throw new \InvalidArgumentException(
                "Field '{$this->field}' does not have a SortedIndex. " .
                "Range queries require a SortedIndex attribute."
            );
        }

        $indexName = $metadata->sortedIndices[$this->field];
        $indexKey = $metadata->getSortedIndexKeyName($indexName);

        // Prepare min/max values for Redis
        $minValue = $this->min !== null ? ($this->includeMin ? $this->min : '(' . $this->min) : '-inf';
        $maxValue = $this->max !== null ? ($this->includeMax ? $this->max : '(' . $this->max) : '+inf';

        // Use ZRANGEBYSCORE to get matching document IDs
        $resultIds = $redisClient->zRangeByScore($indexKey, $minValue, $maxValue);

        // If we have additional criteria, filter the results
        if (!empty($this->criteria)) {
            $filteredIds = [];

            foreach ($resultIds as $id) {
                $document = $documentManager->find($repository->getDocumentClass(), $id);

                if (!$document) {
                    continue;
                }

                $match = true;

                foreach ($this->criteria as $field => $value) {
                    $getter = 'get' . ucfirst($field);

                    if (method_exists($document, $getter)) {
                        if ($document->$getter() != $value) {
                            $match = false;
                            break;
                        }
                    } else {
                        // Try to access property directly
                        try {
                            $reflProperty = new \ReflectionProperty($repository->getDocumentClass(), $field);
                            $reflProperty->setAccessible(true);

                            if ($reflProperty->getValue($document) != $value) {
                                $match = false;
                                break;
                            }
                        } catch (\ReflectionException $e) {
                            $match = false;
                            break;
                        }
                    }
                }

                if ($match) {
                    $filteredIds[] = $id;
                }
            }

            $resultIds = $filteredIds;
        }

        // Get total count for pagination
        $totalCount = count($resultIds);

        // Apply offset and limit
        if ($this->offset !== null || $this->limit !== null) {
            $resultIds = array_slice($resultIds, $this->offset ?? 0, $this->limit);
        }

        // Load the documents
        $documents = [];
        foreach ($resultIds as $id) {
            $document = $documentManager->find($repository->getDocumentClass(), $id);
            if ($document) {
                $documents[] = $document;
            }
        }

        // Apply sorting if needed
        if ($this->orderBy && count($documents) > 1) {
            usort($documents, function($a, $b) {
                foreach ($this->orderBy as $field => $direction) {
                    $getter = 'get' . ucfirst($field);

                    if (method_exists($a, $getter) && method_exists($b, $getter)) {
                        $valueA = $a->$getter();
                        $valueB = $b->$getter();
                    } else {
                        // Try to access property directly
                        try {
                            $reflProperty = new \ReflectionProperty(get_class($a), $field);
                            $reflProperty->setAccessible(true);
                            $valueA = $reflProperty->getValue($a);
                            $valueB = $reflProperty->getValue($b);
                        } catch (\ReflectionException $e) {
                            continue; // Skip this field
                        }
                    }

                    if ($valueA == $valueB) {
                        continue;
                    }

                    $comparison = $valueA <=> $valueB;
                    return strtoupper($direction) === 'DESC' ? -$comparison : $comparison;
                }

                return 0;
            });
        }

        return new PaginatedResult(
            $documents,
            $totalCount,
            $this->limit ?? 0,
            $this->offset ?? 0
        );
    }
}