<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Service;

use Phillarmonic\AllegroRedisOdmBundle\Client\RedisClientAdapter;
use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use Phillarmonic\AllegroRedisOdmBundle\Repository\DocumentRepository;

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

        $repository->stream(function($document) use (&$deletedCount) {
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

        // Calculate new and old patterns
        $oldDocumentPattern = ($oldPrefix ? $oldPrefix . ':' : '') . $oldCollection . ':*';
        $newDocumentPattern = ($updatePrefix && $newPrefix !== null ? $newPrefix . ':' : ($oldPrefix ? $oldPrefix . ':' : '')) . $newCollectionName . ':*';

        // Get all keys matching the old pattern
        $documentKeys = $this->redisClient->keys($oldDocumentPattern);

        // Also handle index keys
        $oldIndexPattern = ($oldPrefix ? $oldPrefix . ':' : '') . 'idx:' . $oldCollection . ':*';
        $newIndexPattern = ($updatePrefix && $newPrefix !== null ? $newPrefix . ':' : ($oldPrefix ? $oldPrefix . ':' : '')) . 'idx:' . $newCollectionName . ':*';

        $indexKeys = $this->redisClient->keys($oldIndexPattern);

        // Also handle sorted index keys
        $oldSortedIndexPattern = ($oldPrefix ? $oldPrefix . ':' : '') . 'zidx:' . $oldCollection . ':*';
        $newSortedIndexPattern = ($updatePrefix && $newPrefix !== null ? $newPrefix . ':' : ($oldPrefix ? $oldPrefix . ':' : '')) . 'zidx:' . $newCollectionName . ':*';

        $sortedIndexKeys = $this->redisClient->keys($oldSortedIndexPattern);

        // Start transaction
        $this->redisClient->multi();

        $renamedCount = 0;

        // Rename document keys
        foreach ($documentKeys as $oldKey) {
            $newKey = str_replace(
                ($oldPrefix ? $oldPrefix . ':' : '') . $oldCollection . ':',
                ($updatePrefix && $newPrefix !== null ? $newPrefix . ':' : ($oldPrefix ? $oldPrefix . ':' : '')) . $newCollectionName . ':',
                $oldKey
            );

            $this->redisClient->rename($oldKey, $newKey);
            $renamedCount++;
        }

        // Rename index keys
        foreach ($indexKeys as $oldKey) {
            $newKey = str_replace(
                ($oldPrefix ? $oldPrefix . ':' : '') . 'idx:' . $oldCollection . ':',
                ($updatePrefix && $newPrefix !== null ? $newPrefix . ':' : ($oldPrefix ? $oldPrefix . ':' : '')) . 'idx:' . $newCollectionName . ':',
                $oldKey
            );

            $this->redisClient->rename($oldKey, $newKey);
            $renamedCount++;
        }

        // Rename sorted index keys
        foreach ($sortedIndexKeys as $oldKey) {
            $newKey = str_replace(
                ($oldPrefix ? $oldPrefix . ':' : '') . 'zidx:' . $oldCollection . ':',
                ($updatePrefix && $newPrefix !== null ? $newPrefix . ':' : ($oldPrefix ? $oldPrefix . ':' : '')) . 'zidx:' . $newCollectionName . ':',
                $oldKey
            );

            $this->redisClient->rename($oldKey, $newKey);
            $renamedCount++;
        }

        // Execute transaction
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
            function($document) use ($updater, &$updatedCount) {
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
     * Calculate statistics for a collection
     *
     * @param string $documentClass
     * @return array Statistics object with counts, memory usage, etc.
     */
    public function getCollectionStats(string $documentClass): array
    {
        $metadata = $this->metadataFactory->getMetadataFor($documentClass);
        $repository = $this->documentManager->getRepository($documentClass);

        // Get document keys
        $documentPattern = $metadata->getCollectionKeyPattern();
        $documentKeys = $this->redisClient->keys($documentPattern);
        $documentCount = count($documentKeys);

        // Get index information
        $indices = $metadata->getIndices();
        $indexStats = [];

        foreach ($indices as $propertyName => $indexName) {
            $pattern = $metadata->getIndexKeyPattern($indexName);
            $keys = $this->redisClient->keys($pattern);

            $totalMembers = 0;
            foreach ($keys as $key) {
                $members = $this->redisClient->sCard($key);
                $totalMembers += $members;
            }

            $indexStats[$indexName] = [
                'key_count' => count($keys),
                'total_references' => $totalMembers,
                'field' => $propertyName
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
                'field' => $propertyName
            ];
        }

        return [
            'collection' => $metadata->collection,
            'prefix' => $metadata->prefix,
            'document_count' => $documentCount,
            'indices' => $indexStats,
            'sorted_indices' => $sortedIndexStats,
            'storage_type' => $metadata->storageType
        ];
    }

    /**
     * Perform a full collection scan with optimized memory usage
     * This is useful for operations on very large collections
     *
     * @param string $documentClass
     * @param callable $callback Function that processes each document key
     * @param int $scanCount Number of keys to fetch in each scan
     * @return int Number of keys processed
     */
    public function scanCollection(
        string $documentClass,
        callable $callback,
        int $scanCount = 100
    ): int {
        $metadata = $this->metadataFactory->getMetadataFor($documentClass);
        $pattern = $metadata->getCollectionKeyPattern();

        $cursor = null;
        $processedCount = 0;

        do {
            [$cursor, $keys] = $this->redisClient->scan($cursor ?? 0, [
                'match' => $pattern,
                'count' => $scanCount
            ]);

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