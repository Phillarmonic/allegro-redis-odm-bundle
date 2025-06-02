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
        // Uses DocumentManager::findByIds which is already pipelined
        return $this->documentManager->findByIds($this->documentClass, $ids);
    }

    /**
     * Find all documents, with optional pagination.
     * Uses SCAN for iterating keys.
     *
     * @param int|null $limit
     * @param int|null $offset
     * @param string|null $pattern Additional pattern to match (supports wildcards)
     * @return PaginatedResult
     */
    public function findAll(
        ?int $limit = null,
        ?int $offset = null,
        ?string $pattern = null
    ): PaginatedResult {
        $redisClient = $this->documentManager->getRedisClient();
        $basePattern = $this->metadata->getCollectionKeyPattern();

        $searchPattern = $pattern
            ? str_replace('*', $pattern, $basePattern)
            : $basePattern;

        $allKeys = [];
        $cursor = null;
        $scanBatchSize = 1000; // How many keys to fetch per SCAN iteration

        do {
            [$cursor, $scanKeys] = $redisClient->scan(
                $cursor,
                ['match' => $searchPattern, 'count' => $scanBatchSize]
            );
            if (!empty($scanKeys)) {
                $allKeys = array_merge($allKeys, $scanKeys);
            }
        } while ($cursor != 0);

        $totalCount = count($allKeys);
        $keysToFetch = $allKeys;

        if ($offset !== null || $limit !== null) {
            $keysToFetch = array_slice(
                $allKeys,
                $offset ?? 0,
                $limit ?? $totalCount
            );
        }

        $idsToLoad = [];
        foreach ($keysToFetch as $key) {
            $parts = explode(':', $key);
            $idsToLoad[] = end($parts);
        }

        $result = $this->findByIds($idsToLoad);

        return new PaginatedResult(
            $result,
            $totalCount,
            $limit ?? 0,
            $offset ?? 0
        );
    }

    /**
     * Find documents by criteria with optional sorting and pagination.
     * Optimized for multi-indexed queries using SINTERSTORE.
     *
     * @param array $criteria
     * @param array|null $orderBy
     * @param int|null $limit
     * @param int|null $offset
     * @return PaginatedResult
     */
    public function findBy(
        array $criteria,
        ?array $orderBy = null,
        ?int $limit = null,
        ?int $offset = null
    ): PaginatedResult {
        $redisClient = $this->documentManager->getRedisClient();
        $indexedCriteriaKeys = [];
        $nonIndexedCriteria = $criteria;

        // Separate indexed criteria
        foreach ($criteria as $field => $value) {
            if (isset($this->metadata->indices[$field])) {
                $indexName = $this->metadata->indices[$field];
                $indexedCriteriaKeys[] = $this->metadata->getIndexKeyName(
                    $indexName,
                    $value
                );
                unset($nonIndexedCriteria[$field]);
            }
        }

        $resultIds = null;

        if (!empty($indexedCriteriaKeys)) {
            if (count($indexedCriteriaKeys) > 1) {
                // Use SINTERSTORE for multiple indexed fields
                $tempIntersectionKey = $this->metadata->getKeyName(
                    'temp_intersect:' . bin2hex(random_bytes(8))
                );
                $sinterStoreResult = $redisClient->sInterStore(
                       $tempIntersectionKey,
                    ...$indexedCriteriaKeys
                );
                // Set a short TTL for the temporary key
                $redisClient->expire($tempIntersectionKey, 60); // 1 minute TTL

                if ($sinterStoreResult !== false && $sinterStoreResult > 0) {
                    $resultIds = $redisClient->sMembers($tempIntersectionKey);
                } else {
                    $resultIds = [];
                }
                $redisClient->del($tempIntersectionKey); // Clean up
            } else {
                // Single indexed field
                $resultIds = $redisClient->sMembers($indexedCriteriaKeys[0]);
            }

            if (empty($resultIds)) {
                return new PaginatedResult([], 0, $limit ?? 0, $offset ?? 0);
            }
        }

        // If no indexed criteria were used, or if we need to fetch all initially
        if ($resultIds === null) {
            // Fallback: scan all document keys if no indexed criteria could be used
            // This is expensive and should be avoided by using indexes
            $allDocKeys = [];
            $cursor = null;
            $pattern = $this->metadata->getCollectionKeyPattern();
            do {
                [$cursor, $keys] = $redisClient->scan(
                    $cursor,
                    ['match' => $pattern, 'count' => 1000]
                );
                $allDocKeys = array_merge($allDocKeys, $keys);
            } while ($cursor != 0);

            $resultIds = array_map(function ($key) {
                $parts = explode(':', $key);
                return end($parts);
            }, $allDocKeys);
        }

        // Filter by non-indexed criteria if any
        $documentsToProcess = [];
        if (!empty($nonIndexedCriteria) && !empty($resultIds)) {
            // Fetch documents for IDs and filter
            // For very large $resultIds, consider streaming/batching this part
            $candidateDocs = $this->findByIds($resultIds);
            foreach ($candidateDocs as $document) {
                if ($this->matchNonIndexedCriteria($document, $nonIndexedCriteria)) {
                    $documentsToProcess[] = $document;
                }
            }
        } elseif (!empty($resultIds)) {
            // No non-indexed criteria, just load the documents
            $documentsToProcess = $this->findByIds($resultIds);
        }

        $totalCount = count($documentsToProcess);

        if ($orderBy) {
            $documentsToProcess = $this->applySorting(
                $documentsToProcess,
                $orderBy
            );
        }

        $paginatedDocuments = $documentsToProcess;
        if ($offset !== null || $limit !== null) {
            $paginatedDocuments = array_slice(
                $documentsToProcess,
                $offset ?? 0,
                $limit ?? $totalCount
            );
        }

        return new PaginatedResult(
            $paginatedDocuments,
            $totalCount,
            $limit ?? 0,
            $offset ?? 0
        );
    }

    private function matchNonIndexedCriteria(
        object $document,
        array $criteria
    ): bool {
        foreach ($criteria as $field => $value) {
            $getter = 'get' . ucfirst($field);
            $actualValue = null;

            if (method_exists($document, $getter)) {
                $actualValue = $document->$getter();
            } else {
                try {
                    $reflProperty = new \ReflectionProperty(
                        $this->documentClass,
                        $field
                    );
                    $reflProperty->setAccessible(true);
                    $actualValue = $reflProperty->getValue($document);
                } catch (\ReflectionException $e) {
                    return false; // Field doesn't exist
                }
            }
            if ($actualValue != $value) {
                return false;
            }
        }
        return true;
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
     * Count all documents in the collection.
     * Uses SCAN for large datasets.
     *
     * @return int
     */
    public function count(): int
    {
        $redisClient = $this->documentManager->getRedisClient();
        $pattern = $this->metadata->getCollectionKeyPattern();
        $count = 0;
        $cursor = null;

        do {
            [$cursor, $keys] = $redisClient->scan(
                $cursor,
                ['match' => $pattern, 'count' => 1000]
            ); // Adjust count as needed
            $count += count($keys);
        } while ($cursor != 0);

        return $count;
    }

    /**
     * Stream documents with a callback for memory-efficient processing of large datasets
     *
     * @param callable $callback Function to process each document
     * @param array $criteria Optional criteria to filter documents
     * @param int $batchSize Number of documents to process in each batch (for loading from Redis)
     * @return int Number of documents processed
     */
    public function stream(
        callable $callback,
        array $criteria = [],
        int $batchSize = 100
    ): int {
        $redisClient = $this->documentManager->getRedisClient();
        $processed = 0;

        // Attempt to get initial IDs if there are indexed criteria
        $indexedCriteriaKeys = [];
        $nonIndexedCriteria = $criteria;
        foreach ($criteria as $field => $value) {
            if (isset($this->metadata->indices[$field])) {
                $indexName = $this->metadata->indices[$field];
                $indexedCriteriaKeys[] = $this->metadata->getIndexKeyName(
                    $indexName,
                    $value
                );
                unset($nonIndexedCriteria[$field]);
            }
        }

        $idsToStream = null;
        if (!empty($indexedCriteriaKeys)) {
            if (count($indexedCriteriaKeys) > 1) {
                $tempIntersectionKey = $this->metadata->getKeyName(
                    'temp_stream_intersect:' . bin2hex(random_bytes(8))
                );
                $sinterStoreResult = $redisClient->sInterStore(
                       $tempIntersectionKey,
                    ...$indexedCriteriaKeys
                );
                $redisClient->expire($tempIntersectionKey, 60);
                if ($sinterStoreResult !== false && $sinterStoreResult > 0) {
                    // Use SSCAN for iterating over the temporary set
                    $idsCursor = null;
                    $idsToStream = [];
                    do {
                        [$idsCursor, $scannedIds] = $redisClient->sScan(
                            $tempIntersectionKey,
                            $idsCursor,
                            ['count' => $batchSize]
                        );
                        $idsToStream = array_merge($idsToStream, $scannedIds);
                    } while ($idsCursor != 0);
                } else {
                    $idsToStream = [];
                }
                $redisClient->del($tempIntersectionKey);
            } else {
                // Use SSCAN for single index
                $idsCursor = null;
                $idsToStream = [];
                do {
                    [$idsCursor, $scannedIds] = $redisClient->sScan(
                        $indexedCriteriaKeys[0],
                        $idsCursor,
                        ['count' => $batchSize]
                    );
                    $idsToStream = array_merge($idsToStream, $scannedIds);
                } while ($idsCursor != 0);
            }

            if (empty($idsToStream)) return 0;

            // Stream from these specific IDs
            foreach (array_chunk($idsToStream, $batchSize) as $idChunk) {
                $documents = $this->findByIds($idChunk);
                foreach ($documents as $document) {
                    if (empty($nonIndexedCriteria) || $this->matchNonIndexedCriteria($document, $nonIndexedCriteria)) {
                        $callback($document);
                        $processed++;
                    }
                }
                $this->documentManager->clear(); // Clear identity map periodically
            }
            return $processed;
        }

        // No indexed criteria or failed to get initial IDs, stream all and filter
        $pattern = $this->metadata->getCollectionKeyPattern();
        $cursor = null;
        do {
            [$cursor, $keys] = $redisClient->scan(
                $cursor,
                ['match' => $pattern, 'count' => $batchSize]
            );
            $idsInBatch = [];
            foreach ($keys as $key) {
                $parts = explode(':', $key);
                $idsInBatch[] = end($parts);
            }

            if (!empty($idsInBatch)) {
                $documents = $this->findByIds($idsInBatch);
                foreach ($documents as $document) {
                    if (empty($criteria) || $this->matchNonIndexedCriteria($document, $criteria)) {
                        $callback($document);
                        $processed++;
                    }
                }
            }
            $this->documentManager->clear(); // Clear identity map periodically
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
    public function applySorting(array $documents, array $orderBy): array
    {
        usort($documents, function ($a, $b) use ($orderBy) {
            foreach ($orderBy as $field => $direction) {
                $getter = 'get' . ucfirst($field);
                $valueA = null;
                $valueB = null;

                if (method_exists($a, $getter) && method_exists($b, $getter)) {
                    $valueA = $a->$getter();
                    $valueB = $b->$getter();
                } else {
                    try {
                        $reflProperty = new \ReflectionProperty(
                            $this->documentClass,
                            $field
                        );
                        $reflProperty->setAccessible(true);
                        $valueA = $reflProperty->getValue($a);
                        $valueB = $reflProperty->getValue($b);
                    } catch (\ReflectionException $e) {
                        continue;
                    }
                }

                if ($valueA == $valueB) {
                    continue;
                }
                $comparison = $valueA <=> $valueB;
                return strtoupper($direction) === 'DESC'
                    ? -$comparison
                    : $comparison;
            }
            return 0;
        });
        return $documents;
    }

    /**
     * Get the DocumentManager instance.
     *
     * @return DocumentManager
     */
    public function getDocumentManager(): DocumentManager
    {
        return $this->documentManager;
    }

    /**
     * Get the ClassMetadata instance.
     *
     * @return ClassMetadata
     */
    public function getMetadata(): ClassMetadata
    {
        return $this->metadata;
    }

    /**
     * Get the document class name.
     * @return string
     */
    public function getDocumentClass(): string
    {
        return $this->documentClass;
    }
}