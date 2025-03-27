<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Repository;

use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\ClassMetadata;
use Phillarmonic\AllegroRedisOdmBundle\Query\Criteria;
use Phillarmonic\AllegroRedisOdmBundle\Query\PaginatedResult;

class DocumentRepository
{
    public function __construct(
        protected DocumentManager $documentManager,
        protected string $documentClass,
        protected ClassMetadata $metadata
    ) {
    }

    /**
     * Find a document by its ID
     *
     * @param string $id
     * @return object|null
     */
    public function find(string $id)
    {
        return $this->documentManager->find($this->documentClass, $id);
    }

    /**
     * Find multiple documents by their IDs
     *
     * @param array $ids
     * @return array
     */
    public function findByIds(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $document = $this->documentManager->find($this->documentClass, $id);
            if ($document) {
                $result[] = $document;
            }
        }
        return $result;
    }

    /**
     * Find all documents, with optional pagination
     *
     * @param int|null $limit
     * @param int|null $offset
     * @param string|null $pattern Additional pattern to match (supports wildcards)
     * @return PaginatedResult
     */
    public function findAll(?int $limit = null, ?int $offset = null, ?string $pattern = null): PaginatedResult
    {
        $redisClient = $this->documentManager->getRedisClient();
        $basePattern = $this->metadata->getCollectionKeyPattern();

        // If additional pattern is provided, append it to the base pattern
        $searchPattern = $pattern
            ? str_replace('*', $pattern, $basePattern)
            : $basePattern;

        // Use scan instead of keys for better performance with large datasets
        $cursor = null;
        $keys = [];
        $scanCount = min(($limit ?? 1000), 1000); // Set a reasonable scan count

        do {
            // FIXED: Use options array for phpredis compatibility
            $options = [
                'match' => $searchPattern,
                'count' => $scanCount
            ];
            [$cursor, $scanKeys] = $redisClient->scan($cursor ?? null, ['match' => $searchPattern, 'count' => $scanCount]);

            if (!empty($scanKeys)) {
                // OPTIMIZATION: Avoid array_merge in a loop by directly appending keys
                foreach ($scanKeys as $key) {
                    $keys[] = $key;
                }
            }

            // Stop if we have enough keys for the requested limit + offset
            if ($limit !== null && $offset !== null && count($keys) >= ($limit + $offset)) {
                break;
            }
        } while ($cursor != 0);

        // Get total count for pagination info (if no pattern, use faster count method)
        $totalCount = $pattern ? count($keys) : $this->count();

        // Apply offset and limit
        if ($offset !== null || $limit !== null) {
            $keys = array_slice($keys, $offset ?? 0, $limit);
        }

        $result = [];
        foreach ($keys as $key) {
            // Extract ID from key
            $parts = explode(':', $key);
            $id = end($parts);

            $document = $this->find($id);
            if ($document) {
                $result[] = $document;
            }
        }

        return new PaginatedResult(
            $result,
            $totalCount,
            $limit ?? 0,
            $offset ?? 0
        );
    }

    /**
     * Find documents by criteria with optional sorting and pagination
     *
     * @param array $criteria
     * @param array|null $orderBy
     * @param int|null $limit
     * @param int|null $offset
     * @return PaginatedResult
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): PaginatedResult
    {
        $totalCount = 0;
        $documentsToProcess = [];
        $redisClient = $this->documentManager->getRedisClient();

        // Optimize for single indexed field queries
        if (count($criteria) === 1) {
            $field = key($criteria);
            $value = current($criteria);

            // Check if field is indexed
            if (isset($this->metadata->indices[$field])) {
                $indexName = $this->metadata->indices[$field];
                $indexKey = $this->metadata->getIndexKeyName($indexName, $value);

                // Get total count for pagination info
                $totalCount = $redisClient->sCard($indexKey);

                // Get all IDs or just a slice for pagination
                if ($offset !== null || $limit !== null) {
                    // For pagination with indices, we need to get all IDs first and then slice
                    // A better approach would be using Sorted Sets, but this works with the current implementation
                    $allIds = $redisClient->sMembers($indexKey);
                    $ids = array_slice($allIds, $offset ?? 0, $limit);
                } else {
                    $ids = $redisClient->sMembers($indexKey);
                }

                $documents = $this->findByIds($ids);

                // Apply sorting if needed
                if ($orderBy) {
                    $documents = $this->applySorting($documents, $orderBy);
                }

                return new PaginatedResult(
                    $documents,
                    $totalCount,
                    $limit ?? 0,
                    $offset ?? 0
                );
            }
        }

        // For multi-field criteria or non-indexed fields, we need a more complex approach

        // If we have at least one indexed field in the criteria, start with that to reduce the initial result set
        $initialIds = null;
        foreach ($criteria as $field => $value) {
            if (isset($this->metadata->indices[$field])) {
                $indexName = $this->metadata->indices[$field];
                $indexKey = $this->metadata->getIndexKeyName($indexName, $value);
                $ids = $redisClient->sMembers($indexKey);

                if ($initialIds === null) {
                    $initialIds = $ids;
                } else {
                    // Intersect with previous results - we want documents that match ALL criteria
                    $initialIds = array_intersect($initialIds, $ids);
                }

                // Remove this field from criteria as we've already handled it
                unset($criteria[$field]);

                // If no matches, we can return early
                if (empty($initialIds)) {
                    return new PaginatedResult([], 0, $limit ?? 0, $offset ?? 0);
                }
            }
        }

        // If we found initial IDs from indexed fields, use those
        if ($initialIds !== null) {
            $documentsToProcess = $this->findByIds($initialIds);
            $totalCount = count($documentsToProcess);
        } else {
            // Otherwise, we need to scan all documents
            $allDocuments = $this->findAll()->getResults();
            $documentsToProcess = $allDocuments;
            $totalCount = count($allDocuments);
        }

        // Apply remaining criteria
        if (!empty($criteria)) {
            $filteredDocuments = [];

            foreach ($documentsToProcess as $document) {
                $match = true;

                foreach ($criteria as $field => $value) {
                    $getter = 'get' . ucfirst($field);

                    if (method_exists($document, $getter)) {
                        if ($document->$getter() != $value) {
                            $match = false;
                            break;
                        }
                    } else {
                        // Try to access property directly
                        try {
                            $reflProperty = new \ReflectionProperty($this->documentClass, $field);
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
                    $filteredDocuments[] = $document;
                }
            }

            $documentsToProcess = $filteredDocuments;
            $totalCount = count($filteredDocuments);
        }

        // Apply sorting if specified
        if ($orderBy) {
            $documentsToProcess = $this->applySorting($documentsToProcess, $orderBy);
        }

        // Apply pagination
        $result = $documentsToProcess;
        if ($offset !== null || $limit !== null) {
            $result = array_slice($documentsToProcess, $offset ?? 0, $limit);
        }

        return new PaginatedResult(
            $result,
            $totalCount,
            $limit ?? 0,
            $offset ?? 0
        );
    }

    /**
     * Find one document by criteria
     *
     * @param array $criteria
     * @return object|null
     */
    public function findOneBy(array $criteria): ?object
    {
        $results = $this->findBy($criteria, null, 1)->getResults();
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Count all documents in the collection
     *
     * @return int
     */
    public function count(): int
    {
        $redisClient = $this->documentManager->getRedisClient();
        $pattern = $this->metadata->getCollectionKeyPattern();

        // For small datasets, using keys() is fine
        if ($this->isSmallDataset()) {
            $keys = $redisClient->keys($pattern);
            return count($keys);
        }

        // For large datasets, we need to use scan and count
        $cursor = null;
        $count = 0;

        do {
            // FIX: Pass pattern as string instead of array
            [$cursor, $keys] = $redisClient->scan($cursor ?? null, ['match' => $pattern, 'count' => 1000]);
            $count += count($keys);
        } while ($cursor != 0);

        return $count;
    }

    /**
     * Stream documents with a callback for memory-efficient processing of large datasets
     *
     * @param callable $callback Function to process each document
     * @param array $criteria Optional criteria to filter documents
     * @param int $batchSize Number of documents to process in each batch
     * @return int Number of documents processed
     */
    public function stream(callable $callback, array $criteria = [], int $batchSize = 100): int
    {
        $redisClient = $this->documentManager->getRedisClient();
        $pattern = $this->metadata->getCollectionKeyPattern();
        $cursor = null;
        $processed = 0;

        // Apply indexed criteria if available to reduce the dataset
        $initialIds = null;

        foreach ($criteria as $field => $value) {
            if (isset($this->metadata->indices[$field])) {
                $indexName = $this->metadata->indices[$field];
                $indexKey = $this->metadata->getIndexKeyName($indexName, $value);
                $ids = $redisClient->sMembers($indexKey);

                if ($initialIds === null) {
                    $initialIds = $ids;
                } else {
                    $initialIds = array_intersect($initialIds, $ids);
                }

                unset($criteria[$field]);

                if (empty($initialIds)) {
                    return 0; // No matches for indexed criteria
                }
            }
        }

        // If we have initial IDs from indexed fields, use those
        if ($initialIds !== null) {
            $batch = [];
            $count = 0;

            foreach ($initialIds as $id) {
                $document = $this->find($id);

                if ($document) {
                    // Apply remaining non-indexed criteria
                    $match = true;

                    foreach ($criteria as $field => $value) {
                        $getter = 'get' . ucfirst($field);

                        if (method_exists($document, $getter)) {
                            if ($document->$getter() != $value) {
                                $match = false;
                                break;
                            }
                        } else {
                            // Try to access property directly
                            try {
                                $reflProperty = new \ReflectionProperty($this->documentClass, $field);
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
                        $batch[] = $document;
                        $count++;

                        if (count($batch) >= $batchSize) {
                            foreach ($batch as $doc) {
                                $callback($doc);
                                $processed++;
                            }

                            $batch = [];
                            $this->documentManager->clear(); // Clear identity map to free memory
                        }
                    }
                }
            }

            // Process any remaining documents
            foreach ($batch as $doc) {
                $callback($doc);
                $processed++;
            }

            return $processed;
        }

        // Otherwise, scan through all documents
        do {
            [$cursor, $keys] = $redisClient->scan($cursor ?? null, ['match' => $pattern, 'count' => $batchSize]);

            $batch = [];

            foreach ($keys as $key) {
                $parts = explode(':', $key);
                $id = end($parts);

                $document = $this->find($id);

                if ($document) {
                    // Apply criteria if any
                    $match = true;

                    foreach ($criteria as $field => $value) {
                        $getter = 'get' . ucfirst($field);

                        if (method_exists($document, $getter)) {
                            if ($document->$getter() != $value) {
                                $match = false;
                                break;
                            }
                        } else {
                            // Try to access property directly
                            try {
                                $reflProperty = new \ReflectionProperty($this->documentClass, $field);
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
                        $batch[] = $document;
                    }
                }
            }

            // Process batch
            foreach ($batch as $doc) {
                $callback($doc);
                $processed++;
            }

            // Clear identity map to free memory
            $this->documentManager->clear();

        } while ($cursor != 0);

        return $processed;
    }

    /**
     * Execute a query with Redis SCAN for better memory usage
     *
     * @param Criteria $criteria Query criteria
     * @return PaginatedResult
     */
    public function createQuery(Criteria $criteria): PaginatedResult
    {
        return $criteria->execute($this);
    }

    /**
     * Helper method to sort documents
     *
     * @param array $documents
     * @param array $orderBy
     * @return array
     */
    protected function applySorting(array $documents, array $orderBy): array
    {
        usort($documents, function($a, $b) use ($orderBy) {
            foreach ($orderBy as $field => $direction) {
                $getter = 'get' . ucfirst($field);

                if (method_exists($a, $getter) && method_exists($b, $getter)) {
                    $valueA = $a->$getter();
                    $valueB = $b->$getter();
                } else {
                    // Try to access property directly
                    try {
                        $reflProperty = new \ReflectionProperty($this->documentClass, $field);
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

        return $documents;
    }

    /**
     * Determine if we're working with a small dataset
     * This allows optimization for count operations
     *
     * @return bool
     */
    protected function isSmallDataset(): bool
    {
        // You can implement a more sophisticated check based on your use case
        // For example, checking a counter in Redis or using a configuration setting
        return false; // Default to assuming large dataset for safety
    }
}