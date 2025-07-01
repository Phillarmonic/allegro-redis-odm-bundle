<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Query;

use Phillarmonic\AllegroRedisOdmBundle\Repository\DocumentRepository;

/**
 * Range query for sorted indices.
 * Optimized for pagination using Redis LIMIT and efficient filtering.
 */
class RangeQuery
{
    /**
     * @var string The field to query (must have a SortedIndex)
     */
    private string $field;

    /**
     * @var float|null Minimum value (inclusive by default)
     */
    private ?float $min = null;

    /**
     * @var float|null Maximum value (inclusive by default)
     */
    private ?float $max = null;

    /**
     * @var array Additional filter criteria
     */
    private array $criteria = [];

    /**
     * @var array|null Ordering criteria (applied after fetching from Redis)
     */
    private ?array $orderBy = null;

    /**
     * @var int|null Maximum number of results (for Redis LIMIT)
     */
    private ?int $limit = null;

    /**
     * @var int|null Offset for pagination (for Redis LIMIT)
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
     * Add a field criteria (applied after fetching from Redis)
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
     * Add ordering (applied after fetching from Redis)
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
        $documentManager = $repository->getDocumentManager();
        $metadata = $repository->getMetadata();
        $redisClient = $documentManager->getRedisClient();

        if (!isset($metadata->sortedIndices[$this->field])) {
            throw new \InvalidArgumentException(
                "Field '{$this->field}' does not have a SortedIndex. " .
                "Range queries require a SortedIndex attribute."
            );
        }

        $indexName = $metadata->sortedIndices[$this->field];
        $indexKey = $metadata->getSortedIndexKeyName($indexName);

        $minValue = $this->min !== null
            ? ($this->includeMin ? (string) $this->min : '(' . $this->min)
            : '-inf';
        $maxValue = $this->max !== null
            ? ($this->includeMax ? (string) $this->max : '(' . $this->max)
            : '+inf';

        // Get total count for the range (without limit/offset for pagination info)
        $totalCountInRange = $redisClient->zCount($indexKey, $minValue, $maxValue);

        $options = [];
        if ($this->limit !== null) {
            $options['limit'] = [$this->offset ?? 0, $this->limit];
        }

        // Fetch IDs using ZRANGEBYSCORE with LIMIT
        $resultIds = $redisClient->zRangeByScore(
            $indexKey,
            $minValue,
            $maxValue,
            $options
        );

        $documents = [];
        if (!empty($resultIds)) {
            if (!empty($this->criteria)) {
                // Fetch documents and apply additional criteria
                // For very large $resultIds after pagination, consider batching findByIds
                $candidateDocs = $documentManager->findByIds(
                    $repository->getDocumentClass(),
                    $resultIds
                );
                foreach ($candidateDocs as $document) {
                    $match = true;
                    foreach ($this->criteria as $critField => $critValue) {
                        $getter = 'get' . ucfirst($critField);
                        $actualValue = null;
                        if (method_exists($document, $getter)) {
                            $actualValue = $document->$getter();
                        } else {
                            try {
                                $reflProp = new \ReflectionProperty(
                                    get_class($document),
                                    $critField
                                );
                                $reflProp->setAccessible(true);
                                $actualValue = $reflProp->getValue($document);
                            } catch (\ReflectionException $e) {
                                $match = false;
                                break;
                            }
                        }
                        if ($actualValue != $critValue) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $documents[] = $document;
                    }
                }
                // Note: $totalCountInRange is for the raw range.
                // If criteria reduce the count, pagination might be off.
                // For accurate pagination with criteria, count after filtering.
                // This example prioritizes Redis-side pagination for the range.
                // A more complex solution would re-count after PHP filtering if strict pagination on filtered set is needed.
            } else {
                // No additional criteria, just load the documents
                $documents = $documentManager->findByIds(
                    $repository->getDocumentClass(),
                    $resultIds
                );
            }
        }

        if ($this->orderBy && count($documents) > 1) {
            $documents = $repository->applySorting($documents, $this->orderBy);
        }

        return new PaginatedResult(
            $documents,
            $totalCountInRange, // This is the count of items in the score range before PHP criteria
            $this->limit ?? 0,
            $this->offset ?? 0
        );
    }
}