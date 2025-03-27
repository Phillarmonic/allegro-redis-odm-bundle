<?php

namespace Phillarmonic\AllegroRedisOdmBundle;

use Phillarmonic\AllegroRedisOdmBundle\Client\RedisClientAdapter;
use Phillarmonic\AllegroRedisOdmBundle\Hydrator\Hydrator;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\ClassMetadata;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use Phillarmonic\AllegroRedisOdmBundle\Repository\DocumentRepository;
use ReflectionProperty;

class DocumentManager
{
    private array $repositories = [];
    private array $identityMap = [];
    private array $unitOfWork = [];
    private array $originalData = []; // Add a new property to store original entity data
    private bool $forceRebuildIndexes = false; // Flag to force index rebuild
    private array $stats = ['reads' => 0, 'writes' => 0, 'deletes' => 0]; // Statistics tracker

    public function __construct(
        private RedisClientAdapter $redisClient,
        private MetadataFactory $metadataFactory,
        private Hydrator $hydrator
    ) {
    }

    /**
     * Get repository for a document class
     *
     * @param string $documentClass Fully qualified document class name
     * @return DocumentRepository
     */
    public function getRepository(string $documentClass): DocumentRepository
    {
        if (!isset($this->repositories[$documentClass])) {
            $metadata = $this->metadataFactory->getMetadataFor($documentClass);

            // Use custom repository class if configured
            if ($metadata->repositoryClass && class_exists($metadata->repositoryClass)) {
                $repositoryClass = $metadata->repositoryClass;
                $this->repositories[$documentClass] = new $repositoryClass($this, $documentClass, $metadata);
            } else {
                $this->repositories[$documentClass] = new DocumentRepository($this, $documentClass, $metadata);
            }
        }

        return $this->repositories[$documentClass];
    }

    /**
     * Enable force rebuilding of all indexes on next flush
     */
    public function enableForceRebuildIndexes(): void
    {
        $this->forceRebuildIndexes = true;
    }

    /**
     * Disable force rebuilding of indexes
     */
    public function disableForceRebuildIndexes(): void
    {
        $this->forceRebuildIndexes = false;
    }

    /**
     * Find a document by ID
     *
     * @param string $documentClass Fully qualified document class name
     * @param string $id Document ID
     * @return object|null The document or null if not found
     */
    public function find(string $documentClass, string $id)
    {
        $metadata = $this->metadataFactory->getMetadataFor($documentClass);
        $key = $metadata->getKeyName($id);

        // Check identity map first
        $mapKey = $documentClass . ':' . $id;
        if (isset($this->identityMap[$mapKey])) {
            return $this->identityMap[$mapKey];
        }

        // Load based on storage type
        $data = null;
        if ($metadata->storageType === 'hash') {
            $data = $this->redisClient->hGetAll($key);
            if (empty($data)) {
                return null;
            }
        } elseif ($metadata->storageType === 'json') {
            $jsonData = $this->redisClient->get($key);
            if (!$jsonData) {
                return null;
            }
            $data = json_decode($jsonData, true);
        }

        // Update stats
        $this->stats['reads']++;

        // Hydrate document
        $document = $this->hydrator->hydrate($documentClass, $data);

        // Set ID value
        $reflProperty = new ReflectionProperty($documentClass, $metadata->idField);
        $reflProperty->setAccessible(true);
        $reflProperty->setValue($document, $id);

        // Add to identity map
        $this->identityMap[$mapKey] = $document;

        // Store original data for later comparison
        $this->originalData[$mapKey] = $data;

        return $document;
    }

    /**
     * Persist a document (new or existing)
     *
     * @param object $document The document to persist
     * @throws \RuntimeException If document ID is empty
     */
    public function persist($document): void
    {
        $className = get_class($document);
        $metadata = $this->metadataFactory->getMetadataFor($className);

        // Get ID value
        $reflProperty = new ReflectionProperty($className, $metadata->idField);
        $reflProperty->setAccessible(true);
        $id = $reflProperty->getValue($document);

        // Generate ID if needed
        if (empty($id) && $metadata->idStrategy === 'auto') {
            $id = uniqid('', true);
            $reflProperty->setValue($document, $id);
        }

        if (empty($id)) {
            throw new \RuntimeException("Document ID cannot be empty.");
        }

        // Add to unit of work
        $this->unitOfWork[$className . ':' . $id] = $document;

        // If this is an existing document not in identity map/original data, fetch it first
        $mapKey = $className . ':' . $id;
        if (!isset($this->originalData[$mapKey]) && !$this->isNewDocument($document)) {
            $this->fetchOriginalData($document, $id, $metadata);
        }
    }

    /**
     * Check if a document is new (not yet stored in Redis)
     *
     * @param object $document The document to check
     * @return bool True if document is new
     */
    private function isNewDocument($document): bool
    {
        $className = get_class($document);
        $metadata = $this->metadataFactory->getMetadataFor($className);

        // Get ID value
        $reflProperty = new ReflectionProperty($className, $metadata->idField);
        $reflProperty->setAccessible(true);
        $id = $reflProperty->getValue($document);

        // If no ID or auto-generated ID that hasn't been persisted yet
        if (empty($id)) {
            return true;
        }

        // Check if document exists in Redis
        $key = $metadata->getKeyName($id);
        if ($metadata->storageType === 'hash') {
            $data = $this->redisClient->hGetAll($key);
            return empty($data);
        } elseif ($metadata->storageType === 'json') {
            return !$this->redisClient->exists($key);
        }

        return true;
    }

    /**
     * Fetch original data for an existing document
     */
    private function fetchOriginalData($document, string $id, ClassMetadata $metadata): void
    {
        $className = get_class($document);
        $key = $metadata->getKeyName($id);
        $mapKey = $className . ':' . $id;

        if ($metadata->storageType === 'hash') {
            $data = $this->redisClient->hGetAll($key);
            if (!empty($data)) {
                $this->originalData[$mapKey] = $data;
            }
        } elseif ($metadata->storageType === 'json') {
            $jsonData = $this->redisClient->get($key);
            if ($jsonData) {
                $this->originalData[$mapKey] = json_decode($jsonData, true);
            }
        }

        // Update stats
        $this->stats['reads']++;
    }

    /**
     * Mark a document for removal
     *
     * @param object $document The document to remove
     * @throws \RuntimeException If document ID is empty
     */
    public function remove($document): void
    {
        $className = get_class($document);
        $metadata = $this->metadataFactory->getMetadataFor($className);

        // Get ID value
        $reflProperty = new ReflectionProperty($className, $metadata->idField);
        $reflProperty->setAccessible(true);
        $id = $reflProperty->getValue($document);

        if (empty($id)) {
            throw new \RuntimeException("Cannot remove document without ID.");
        }

        // Mark for deletion
        $this->unitOfWork[$className . ':' . $id] = null;
    }

    /**
     * Flush all pending changes to Redis
     * This writes all persisted documents and removes all deleted documents
     *
     * @throws \ReflectionException
     */
    public function flush(): void
    {
        // Start a Redis pipeline for better performance
        $this->redisClient->multi();

        foreach ($this->unitOfWork as $key => $document) {
            [$className, $id] = explode(':', $key, 2);
            $metadata = $this->metadataFactory->getMetadataFor($className);
            $redisKey = $metadata->getKeyName($id);

            if ($document === null) {
                // Remove document
                $this->redisClient->del($redisKey);

                // Remove any indices
                $this->cleanupDocumentIndices($className, $id, $metadata);

                // Remove from identity map and original data
                unset($this->identityMap[$key]);
                unset($this->originalData[$key]);

                // Update stats
                $this->stats['deletes']++;
            } else {
                // Extract data from document
                $data = $this->hydrator->extract($document);

                // For each indexed property, update index
                foreach ($metadata->indices as $propertyName => $indexName) {
                    // Get the new value
                    $reflProperty = new ReflectionProperty($className, $propertyName);
                    $reflProperty->setAccessible(true);
                    $newValue = $reflProperty->getValue($document);

                    // Get the old value if there was one
                    $oldValue = null;
                    if (isset($this->originalData[$key])) {
                        $fieldName = $metadata->getFieldName($propertyName);
                        $oldValue = $this->originalData[$key][$fieldName] ?? null;

                        // Convert to appropriate PHP type if needed
                        $fieldType = $metadata->getFieldType($propertyName);
                        if ($fieldType && $oldValue !== null) {
                            switch ($fieldType) {
                                case 'boolean':
                                    $oldValue = (bool) $oldValue;
                                    break;
                                case 'integer':
                                    $oldValue = (int) $oldValue;
                                    break;
                                case 'float':
                                    $oldValue = (float) $oldValue;
                                    break;
                                // Add other type conversions as needed
                            }
                        }
                    }

                    // Check if we need to update the index
                    $shouldUpdateIndex = false;

                    // Update index if:
                    // 1. We're forcing a rebuild of all indexes, or
                    // 2. The values are different
                    if ($this->forceRebuildIndexes || $oldValue !== $newValue) {
                        // Always remove old index in case it existed
                        if ($oldValue !== null && !$this->forceRebuildIndexes) {
                            $oldIndexKey = $metadata->getIndexKeyName($indexName, $oldValue);
                            $this->redisClient->sRem($oldIndexKey, $id);
                        }

                        // Add new index if value exists
                        if ($newValue !== null) {
                            $this->updateIndex($metadata, $indexName, $newValue, $id);
                        }
                    }
                }

                // Process sorted indices
                foreach ($metadata->sortedIndices as $propertyName => $indexName) {
                    // Get the new value
                    $reflProperty = new ReflectionProperty($className, $propertyName);
                    $reflProperty->setAccessible(true);
                    $newValue = $reflProperty->getValue($document);

                    // Get the old value if there was one
                    $oldValue = null;
                    if (isset($this->originalData[$key])) {
                        $fieldName = $metadata->getFieldName($propertyName);
                        $oldValue = $this->originalData[$key][$fieldName] ?? null;

                        // Convert to appropriate PHP type if needed
                        $fieldType = $metadata->getFieldType($propertyName);
                        if ($fieldType && $oldValue !== null) {
                            switch ($fieldType) {
                                case 'integer':
                                    $oldValue = (int) $oldValue;
                                    break;
                                case 'float':
                                    $oldValue = (float) $oldValue;
                                    break;
                            }
                        }
                    }

                    // Update the sorted index if the value changed or we're forcing rebuild
                    if ($this->forceRebuildIndexes || $oldValue !== $newValue) {
                        // Remove old value from sorted index if it existed
                        if ($oldValue !== null && !$this->forceRebuildIndexes) {
                            $sortedIndexKey = $metadata->getSortedIndexKeyName($indexName);
                            $this->redisClient->zRem($sortedIndexKey, $id);
                        }

                        // Add new value to sorted index
                        if ($newValue !== null) {
                            $this->updateSortedIndex($metadata, $indexName, $newValue, $id);
                        }
                    }
                }

                // Save based on storage type
                if ($metadata->storageType === 'hash') {
                    $this->redisClient->hMSet($redisKey, $data);
                } elseif ($metadata->storageType === 'json') {
                    $this->redisClient->set($redisKey, json_encode($data));
                }

                // Set expiration if needed
                if ($metadata->ttl > 0) {
                    $this->redisClient->expire($redisKey, $metadata->ttl);
                }

                // Update identity map and original data
                $this->identityMap[$key] = $document;
                $this->originalData[$key] = $data;

                // Update stats
                $this->stats['writes']++;
            }
        }

        // Execute pipeline
        $this->redisClient->exec();

        // Clear unit of work
        $this->unitOfWork = [];

        // Reset the force rebuild flag after flushing
        $this->forceRebuildIndexes = false;
    }

    /**
     * Clear the identity map
     * This removes all loaded documents from memory
     */
    public function clear(): void
    {
        $this->identityMap = [];
        $this->unitOfWork = [];
        $this->originalData = [];
    }

    /**
     * Get the Redis client adapter
     *
     * @return RedisClientAdapter
     */
    public function getRedisClient(): RedisClientAdapter
    {
        return $this->redisClient;
    }

    /**
     * Updates or creates an index entry for a document with optional TTL
     */
    private function updateIndex(ClassMetadata $metadata, string $indexName, $value, string $id): void
    {
        if ($value === null) {
            return;
        }

        $indexKey = $metadata->getIndexKeyName($indexName, $value);
        $this->redisClient->sAdd($indexKey, $id);

        // Apply TTL if configured
        $ttl = $metadata->getIndexTTL($indexName);
        if ($ttl > 0) {
            $this->redisClient->expire($indexKey, $ttl);
        }
    }

    /**
     * Updates or creates a sorted index entry for a document with optional TTL
     */
    private function updateSortedIndex(ClassMetadata $metadata, string $indexName, $value, string $id): void
    {
        if ($value === null) {
            return;
        }

        // Ensure the value is numeric
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(
                "Cannot add non-numeric value to sorted index '{$indexName}'. Got: " . gettype($value)
            );
        }

        $indexKey = $metadata->getSortedIndexKeyName($indexName);
        $this->redisClient->zAdd($indexKey, $value, $id);

        // Apply TTL if configured
        $ttl = $metadata->getSortedIndexTTL($indexName);
        if ($ttl > 0) {
            $this->redisClient->expire($indexKey, $ttl);
        }
    }

    /**
     * Helper method to clean up all indices for a document
     * @throws \ReflectionException
     */
    private function cleanupDocumentIndices(string $className, string $id, ClassMetadata $metadata): void
    {
        $oldDoc = $this->identityMap[$className . ':' . $id] ?? null;
        $originalData = $this->originalData[$className . ':' . $id] ?? null;

        if ($oldDoc || $originalData) {
            // If we have the old document in identity map or original data, remove specific indices

            // Clean up regular indices
            foreach ($metadata->indices as $propertyName => $indexName) {
                $oldValue = null;

                if ($oldDoc) {
                    $reflProperty = new ReflectionProperty($className, $propertyName);
                    $reflProperty->setAccessible(true);
                    $oldValue = $reflProperty->getValue($oldDoc);
                } elseif ($originalData) {
                    $fieldName = $metadata->getFieldName($propertyName);
                    $oldValue = $originalData[$fieldName] ?? null;
                }

                if ($oldValue !== null) {
                    $indexKey = $metadata->getIndexKeyName($indexName, $oldValue);
                    $this->redisClient->sRem($indexKey, $id);
                }
            }

            // Clean up sorted indices
            foreach ($metadata->sortedIndices as $propertyName => $indexName) {
                $sortedIndexKey = $metadata->getSortedIndexKeyName($indexName);
                $this->redisClient->zRem($sortedIndexKey, $id);
            }
        } else {
            // Otherwise scan for any potential indices (less efficient but complete)

            // Clean up regular indices
            foreach ($metadata->indices as $propertyName => $indexName) {
                $pattern = $metadata->getIndexKeyPattern($indexName);
                $keys = $this->redisClient->keys($pattern);

                foreach ($keys as $indexKey) {
                    $this->redisClient->sRem($indexKey, $id);
                }
            }

            // Clean up sorted indices
            foreach ($metadata->sortedIndices as $propertyName => $indexName) {
                $sortedIndexKey = $metadata->getSortedIndexKeyName($indexName);
                $this->redisClient->zRem($sortedIndexKey, $id);
            }
        }
    }

    /**
     * Get statistics about operations performed since last reset
     *
     * @return array Operation statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Reset statistics counters
     */
    public function resetStats(): void
    {
        $this->stats = ['reads' => 0, 'writes' => 0, 'deletes' => 0];
    }

    /**
     * Find multiple documents by their IDs
     *
     * @param string $documentClass
     * @param array $ids
     * @return array
     */
    public function findByIds(string $documentClass, array $ids): array
    {
        $result = [];

        // Use pipelining for better performance
        $this->redisClient->multi();

        $metadata = $this->metadataFactory->getMetadataFor($documentClass);
        $pendingLookups = [];

        // First check identity map
        foreach ($ids as $id) {
            $mapKey = $documentClass . ':' . $id;

            if (isset($this->identityMap[$mapKey])) {
                $result[$id] = $this->identityMap[$mapKey];
            } else {
                $key = $metadata->getKeyName($id);
                $pendingLookups[$id] = $key;

                // Queue Redis command based on storage type
                if ($metadata->storageType === 'hash') {
                    $this->redisClient->hGetAll($key);
                } elseif ($metadata->storageType === 'json') {
                    $this->redisClient->get($key);
                }
            }
        }

        // If we have pending lookups, execute the pipeline
        if (!empty($pendingLookups)) {
            $responses = $this->redisClient->exec();

            if (!empty($responses)) {
                $i = 0;
                foreach ($pendingLookups as $id => $key) {
                    $data = $responses[$i++];

                    // Skip if no data found
                    if (empty($data)) {
                        continue;
                    }

                    // If JSON storage, decode the data
                    if ($metadata->storageType === 'json' && is_string($data)) {
                        $data = json_decode($data, true);
                    }

                    // Hydrate the document
                    $document = $this->hydrator->hydrate($documentClass, $data);

                    // Set ID value
                    $reflProperty = new ReflectionProperty($documentClass, $metadata->idField);
                    $reflProperty->setAccessible(true);
                    $reflProperty->setValue($document, $id);

                    // Add to result
                    $result[$id] = $document;

                    // Add to identity map
                    $mapKey = $documentClass . ':' . $id;
                    $this->identityMap[$mapKey] = $document;
                    $this->originalData[$mapKey] = $data;
                }

                // Update stats
                $this->stats['reads'] += count($pendingLookups);
            }
        }

        // Reindex to ensure consistent return format
        return array_values($result);
    }

    /**
     * Get document count by criteria
     *
     * @param string $documentClass
     * @param array $criteria
     * @return int
     */
    public function count(string $documentClass, array $criteria = []): int
    {
        $repository = $this->getRepository($documentClass);

        if (empty($criteria)) {
            return $repository->count();
        }

        return $repository->findBy($criteria)->getTotalCount();
    }

    /**
     * Execute a raw Redis command on the client
     *
     * @param string $command The command name
     * @param mixed ...$args Command arguments
     * @return mixed Command result
     */
    public function executeCommand(string $command, ...$args)
    {
        return $this->redisClient->__call($command, $args);
    }

    /**
     * Get metadata factory
     *
     * @return MetadataFactory
     */
    public function getMetadataFactory(): MetadataFactory
    {
        return $this->metadataFactory;
    }
}