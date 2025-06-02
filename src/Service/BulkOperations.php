<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Service;

use Phillarmonic\AllegroRedisOdmBundle\Client\RedisClientAdapter;
use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\ClassMetadata;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;

/**
 * Provides optimized bulk operations for large Redis datasets
 */
class BulkOperations
{
    /**
     * @var RedisClientAdapter
     */
    private RedisClientAdapter $redisClient;

    /**
     * @var DocumentManager
     */
    private DocumentManager $documentManager;

    /**
     * @var MetadataFactory
     */
    private MetadataFactory $metadataFactory;

    /**
     * @var BatchProcessor
     */
    private BatchProcessor $batchProcessor;

    /**
     * @param RedisClientAdapter $redisClient
     * @param DocumentManager $documentManager
     * @param MetadataFactory $metadataFactory
     * @param BatchProcessor $batchProcessor
     */
    public function __construct(
        RedisClientAdapter $redisClient,
        DocumentManager $documentManager,
        MetadataFactory $metadataFactory,
        BatchProcessor $batchProcessor
    ) {
        $this->redisClient = $redisClient;
        $this->documentManager = $documentManager;
        $this->metadataFactory = $metadataFactory;
        $this->batchProcessor = $batchProcessor;
    }

    /**
     * Delete all documents matching the given criteria
     *
     * @param string $documentClass
     * @param array $criteria
     * @param int $batchSize
     * @param callable|null $progressCallback
     * @return int Number of documents deleted
     */
    public function bulkDelete(
        string $documentClass,
        array $criteria = [],
        int $batchSize = 100,
        ?callable $progressCallback = null
    ): int {
        $metadata = $this->metadataFactory->getMetadataFor($documentClass);
        $repository = $this->documentManager->getRepository($documentClass);
        $deletedCount = 0;

        // Start transaction for better performance
        $this->redisClient->multi();

        $repository->stream(function ($document) use (&$deletedCount) {
            $this->documentManager->remove($document);
            $deletedCount++;
        }, $criteria, $batchSize);

        $this->documentManager->flush();
        $this->redisClient->exec();

        if ($progressCallback) {
            call_user_func($progressCallback, $deletedCount);
        }

        return $deletedCount;
    }

    /**
     * Rename a collection (changes all keys in Redis)
     * Uses SCAN for iterating over keys to avoid blocking Redis.
     *
     * @param string $documentClass
     * @param string $newCollectionName
     * @param bool $updatePrefix Change the prefix as well
     * @param string|null $newPrefix New prefix to use
     * @return int Number of keys renamed
     */
    public function renameCollection(
        string $documentClass,
        string $newCollectionName,
        bool $updatePrefix = false,
        ?string $newPrefix = null
    ): int {
        $metadata = $this->metadataFactory->getMetadataFor($documentClass);
        $oldCollection = $metadata->collection;
        $oldPrefix = $metadata->prefix;

        $renamedCount = 0;
        $scanCount = 1000; // Keys per SCAN iteration

        $this->redisClient->multi();

        // Patterns for old keys
        $oldDocumentPattern = ($oldPrefix ? $oldPrefix . ':' : '') .
            $oldCollection .
            ':*';
        $oldIndexPattern = ($oldPrefix ? $oldPrefix . ':' : '') .
            'idx:' .
            $oldCollection .
            ':*';
        $oldSortedIndexPattern = ($oldPrefix ? $oldPrefix . ':' : '') .
            'zidx:' .
            $oldCollection .
            ':*';

        // Replacements
        $oldDocKeyPart = ($oldPrefix ? $oldPrefix . ':' : '') .
            $oldCollection .
            ':';
        $newDocKeyPart = ($updatePrefix && $newPrefix !== null
                ? $newPrefix . ':'
                : ($oldPrefix ? $oldPrefix . ':' : '')) .
            $newCollectionName .
            ':';

        $oldIdxKeyPart = ($oldPrefix ? $oldPrefix . ':' : '') .
            'idx:' .
            $oldCollection .
            ':';
        $newIdxKeyPart = ($updatePrefix && $newPrefix !== null
                ? $newPrefix . ':'
                : ($oldPrefix ? $oldPrefix . ':' : '')) .
            'idx:' .
            $newCollectionName .
            ':';

        $oldZidxKeyPart = ($oldPrefix ? $oldPrefix . ':' : '') .
            'zidx:' .
            $oldCollection .
            ':';
        $newZidxKeyPart = ($updatePrefix && $newPrefix !== null
                ? $newPrefix . ':'
                : ($oldPrefix ? $oldPrefix . ':' : '')) .
            'zidx:' .
            $newCollectionName .
            ':';

        // Rename document keys
        $cursor = null;
        do {
            [$cursor, $keys] = $this->redisClient->scan(
                $cursor,
                ['match' => $oldDocumentPattern, 'count' => $scanCount]
            );
            foreach ($keys as $oldKey) {
                $newKey = str_replace($oldDocKeyPart, $newDocKeyPart, $oldKey);
                if ($oldKey !== $newKey) {
                    $this->redisClient->rename($oldKey, $newKey);
                    $renamedCount++;
                }
            }
        } while ($cursor != 0);

        // Rename index keys
        $cursor = null;
        do {
            [$cursor, $keys] = $this->redisClient->scan(
                $cursor,
                ['match' => $oldIndexPattern, 'count' => $scanCount]
            );
            foreach ($keys as $oldKey) {
                $newKey = str_replace($oldIdxKeyPart, $newIdxKeyPart, $oldKey);
                if ($oldKey !== $newKey) {
                    $this->redisClient->rename($oldKey, $newKey);
                    $renamedCount++;
                }
            }
        } while ($cursor != 0);

        // Rename sorted index keys
        $cursor = null;
        do {
            [$cursor, $keys] = $this->redisClient->scan(
                $cursor,
                ['match' => $oldSortedIndexPattern, 'count' => $scanCount]
            );
            foreach ($keys as $oldKey) {
                $newKey = str_replace(
                    $oldZidxKeyPart,
                    $newZidxKeyPart,
                    $oldKey
                );
                if ($oldKey !== $newKey) {
                    $this->redisClient->rename($oldKey, $newKey);
                    $renamedCount++;
                }
            }
        } while ($cursor != 0);

        $this->redisClient->exec();
        return $renamedCount;
    }

    /**
     * Update multiple documents in bulk
     *
     * @param string $documentClass
     * @param array $criteria Criteria to select documents
     * @param callable $updater Function that receives a document and updates it
     * @param int $batchSize
     * @param callable|null $progressCallback
     * @return int Number of documents updated
     */
    public function bulkUpdate(
        string $documentClass,
        array $criteria,
        callable $updater,
        int $batchSize = 100,
        ?callable $progressCallback = null
    ): int {
        $repository = $this->documentManager->getRepository($documentClass);
        $updatedCount = 0;

        // Use the batch processor for efficient updates
        $this->batchProcessor->processQuery(
            $repository,
            $criteria,
            function ($document) use ($updater, &$updatedCount) {
                $result = $updater($document);

                if ($result) {
                    $updatedCount++;
                    return true; // Indicate document was modified
                }

                return false; // Indicate document was not modified
            },
            $batchSize,
            $progressCallback
        );

        return $updatedCount;
    }

    /**
     * Calculate statistics for a collection.
     * Uses SCAN for iterating over keys.
     *
     * @param string $documentClass
     * @return array Statistics object with counts, memory usage, etc.
     */
    public function getCollectionStats(string $documentClass): array
    {
        $metadata = $this->metadataFactory->getMetadataFor($documentClass);
        $scanCount = 1000; // Keys per SCAN iteration

        // Get document count
        $documentPattern = $metadata->getCollectionKeyPattern();
        $documentCount = 0;
        $cursor = null;
        do {
            [$cursor, $keys] = $this->redisClient->scan(
                $cursor,
                ['match' => $documentPattern, 'count' => $scanCount]
            );
            $documentCount += count($keys);
        } while ($cursor != 0);

        // Get index information
        $indices = $metadata->getIndices();
        $indexStats = [];

        foreach ($indices as $propertyName => $indexName) {
            $pattern = $metadata->getIndexKeyPattern($indexName);
            $indexKeyCount = 0;
            $totalMembers = 0;
            $cursor = null;
            do {
                [$cursor, $keys] = $this->redisClient->scan(
                    $cursor,
                    ['match' => $pattern, 'count' => $scanCount]
                );
                $indexKeyCount += count($keys);
                foreach ($keys as $key) {
                    $members = $this->redisClient->sCard($key);
                    $totalMembers += $members;
                }
            } while ($cursor != 0);

            $indexStats[$indexName] = [
                'key_count' => $indexKeyCount,
                'total_references' => $totalMembers,
                'field' => $propertyName,
            ];
        }

        // Get sorted index information
        $sortedIndices = $metadata->getSortedIndices();
        $sortedIndexStats = [];

        foreach ($sortedIndices as $propertyName => $indexName) {
            $key = $metadata->getSortedIndexKeyName($indexName);
            $cardinality = $this->redisClient->zCard($key);

            $sortedIndexStats[$indexName] = [
                'cardinality' => $cardinality,
                'field' => $propertyName,
            ];
        }

        return [
            'collection' => $metadata->collection,
            'prefix' => $metadata->prefix,
            'document_count' => $documentCount,
            'indices' => $indexStats,
            'sorted_indices' => $sortedIndexStats,
            'storage_type' => $metadata->storageType,
        ];
    }

    /**
     * Perform a full collection scan with optimized memory usage
     * This is useful for operations on very large collections
     *
     * @param string $documentClass
     * @param callable $callback Function that processes each document key
     * @param int $scanBatchSize Number of keys to fetch in each scan iteration
     * @return int Number of keys processed
     */
    public function scanCollection(
        string $documentClass,
        callable $callback,
        int $scanBatchSize = 100
    ): int {
        $metadata = $this->metadataFactory->getMetadataFor($documentClass);
        $pattern = $metadata->getCollectionKeyPattern();

        $cursor = null;
        $processedCount = 0;

        do {
            [$cursor, $keys] = $this->redisClient->scan(
                $cursor,
                ['match' => $pattern, 'count' => $scanBatchSize]
            );

            foreach ($keys as $key) {
                $callback($key);
                $processedCount++;
            }
        } while ($cursor != 0);

        return $processedCount;
    }

    /**
     * Create a Redis pipeline for bulk operations
     * This can be used for custom optimized bulk operations
     *
     * @return RedisClientAdapter
     */
    public function createPipeline(): RedisClientAdapter
    {
        $this->redisClient->multi();
        return $this->redisClient;
    }

    /**
     * Execute a Redis pipeline
     *
     * @return array Results from pipeline execution
     */
    public function executePipeline(): array
    {
        return $this->redisClient->exec();
    }
}