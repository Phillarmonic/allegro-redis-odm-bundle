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
    private array $originalData = [];
    private bool $forceRebuildIndexes = false;
    private array $stats = ['reads' => 0, 'writes' => 0, 'deletes' => 0];

    public function __construct(
        private RedisClientAdapter $redisClient,
        private MetadataFactory $metadataFactory,
        private Hydrator $hydrator
    ) {
    }

    public function getRepository(string $documentClass): DocumentRepository
    {
        if (!isset($this->repositories[$documentClass])) {
            $metadata = $this->metadataFactory->getMetadataFor($documentClass);
            if ($metadata->repositoryClass && class_exists($metadata->repositoryClass)) {
                $repositoryClass = $metadata->repositoryClass;
                $this->repositories[$documentClass] = new $repositoryClass(
                    $this,
                    $documentClass,
                    $metadata
                );
            } else {
                $this->repositories[$documentClass] = new DocumentRepository(
                    $this,
                    $documentClass,
                    $metadata
                );
            }
        }
        return $this->repositories[$documentClass];
    }

    public function enableForceRebuildIndexes(): void
    {
        $this->forceRebuildIndexes = true;
    }

    public function disableForceRebuildIndexes(): void
    {
        $this->forceRebuildIndexes = false;
    }

    public function find(string $documentClass, string $id)
    {
        $metadata = $this->metadataFactory->getMetadataFor($documentClass);
        $key = $metadata->getKeyName($id);
        $mapKey = $documentClass . ':' . $id;

        if (isset($this->identityMap[$mapKey])) {
            return $this->identityMap[$mapKey];
        }

        $data = null;
        if ($metadata->storageType === 'hash') {
            $data = $this->redisClient->hGetAll($key);
            if (empty($data)) return null;
        } elseif ($metadata->storageType === 'json') {
            $jsonData = $this->redisClient->get($key);
            if (!$jsonData) return null;
            $data = json_decode($jsonData, true);
        }

        $this->stats['reads']++;
        $document = $this->hydrator->hydrate($documentClass, $data);
        $reflProperty = new ReflectionProperty($documentClass, $metadata->idField);
        $reflProperty->setAccessible(true);
        $reflProperty->setValue($document, $id);
        $this->identityMap[$mapKey] = $document;
        $this->originalData[$mapKey] = $data;
        return $document;
    }

    public function persist($document): void
    {
        $className = get_class($document);
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $reflProperty = new ReflectionProperty($className, $metadata->idField);
        $reflProperty->setAccessible(true);
        $id = $reflProperty->getValue($document);

        if (empty($id) && $metadata->idStrategy === 'auto') {
            $id = uniqid('', true);
            $reflProperty->setValue($document, $id);
        }
        if (empty($id)) {
            throw new \RuntimeException("Document ID cannot be empty.");
        }

        $this->unitOfWork[$className . ':' . $id] = $document;
        $mapKey = $className . ':' . $id;
        if (!isset($this->originalData[$mapKey]) && !$this->isNewDocument($document)) {
            $this->fetchOriginalData($document, $id, $metadata);
        }
    }

    private function isNewDocument($document): bool
    {
        $className = get_class($document);
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $reflProperty = new ReflectionProperty($className, $metadata->idField);
        $reflProperty->setAccessible(true);
        $id = $reflProperty->getValue($document);

        if (empty($id)) return true;

        $key = $metadata->getKeyName($id);
        if ($metadata->storageType === 'hash') {
            return empty($this->redisClient->hGetAll($key)); // hExists might be better if only checking existence
        } elseif ($metadata->storageType === 'json') {
            return !$this->redisClient->exists($key);
        }
        return true;
    }

    private function fetchOriginalData(
        $document,
        string $id,
        ClassMetadata $metadata
    ): void {
        $className = get_class($document);
        $key = $metadata->getKeyName($id);
        $mapKey = $className . ':' . $id;

        if ($metadata->storageType === 'hash') {
            $data = $this->redisClient->hGetAll($key);
            if (!empty($data)) $this->originalData[$mapKey] = $data;
        } elseif ($metadata->storageType === 'json') {
            $jsonData = $this->redisClient->get($key);
            if ($jsonData) $this->originalData[$mapKey] = json_decode($jsonData, true);
        }
        $this->stats['reads']++;
    }

    public function remove($document): void
    {
        $className = get_class($document);
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $reflProperty = new ReflectionProperty($className, $metadata->idField);
        $reflProperty->setAccessible(true);
        $id = $reflProperty->getValue($document);

        if (empty($id)) {
            throw new \RuntimeException("Cannot remove document without ID.");
        }
        $this->unitOfWork[$className . ':' . $id] = null;
    }

    public function flush(): void
    {
        $this->redisClient->multi();
        foreach ($this->unitOfWork as $key => $document) {
            [$className, $id] = explode(':', $key, 2);
            $metadata = $this->metadataFactory->getMetadataFor($className);
            $redisKey = $metadata->getKeyName($id);

            if ($document === null) {
                $this->redisClient->del($redisKey);
                $this->cleanupDocumentIndices($className, $id, $metadata, true); // Pass true to indicate deletion context
                unset($this->identityMap[$key]);
                unset($this->originalData[$key]);
                $this->stats['deletes']++;
            } else {
                $data = $this->hydrator->extract($document);
                $originalDocData = $this->originalData[$key] ?? null;

                foreach ($metadata->indices as $propertyName => $indexName) {
                    $reflProperty = new ReflectionProperty($className, $propertyName);
                    $reflProperty->setAccessible(true);
                    $newValue = $reflProperty->getValue($document);
                    $oldValue = null;
                    if ($originalDocData) {
                        $fieldName = $metadata->getFieldName($propertyName);
                        // Hydrate old value to ensure type consistency for comparison
                        $oldValue = $this->hydrator->convertToPhpValue(
                            $originalDocData[$fieldName] ?? null,
                            $metadata->getFieldType($propertyName)
                        );
                    }

                    if ($this->forceRebuildIndexes || $oldValue !== $newValue) {
                        if ($oldValue !== null && !$this->forceRebuildIndexes) {
                            $oldIndexKey = $metadata->getIndexKeyName($indexName, $oldValue);
                            $this->redisClient->sRem($oldIndexKey, $id);
                        }
                        if ($newValue !== null) {
                            $this->updateIndex($metadata, $indexName, $newValue, $id);
                        }
                    }
                }

                foreach ($metadata->sortedIndices as $propertyName => $indexName) {
                    $reflProperty = new ReflectionProperty($className, $propertyName);
                    $reflProperty->setAccessible(true);
                    $newValue = $reflProperty->getValue($document);
                    $oldValue = null;
                    if ($originalDocData) {
                        $fieldName = $metadata->getFieldName($propertyName);
                        $oldValue = $this->hydrator->convertToPhpValue(
                            $originalDocData[$fieldName] ?? null,
                            $metadata->getFieldType($propertyName)
                        );
                    }
                    if ($this->forceRebuildIndexes || $oldValue !== $newValue) {
                        if ($oldValue !== null && !$this->forceRebuildIndexes) {
                            $sortedIndexKey = $metadata->getSortedIndexKeyName($indexName);
                            $this->redisClient->zRem($sortedIndexKey, $id); // zRemMemberByScore or zRemRangeByScore might be needed if score is complex
                        }
                        if ($newValue !== null) {
                            $this->updateSortedIndex($metadata, $indexName, $newValue, $id);
                        }
                    }
                }

                if ($metadata->storageType === 'hash') {
                    $this->redisClient->hMSet($redisKey, $data);
                } elseif ($metadata->storageType === 'json') {
                    $this->redisClient->set($redisKey, json_encode($data));
                }
                if ($metadata->ttl > 0) {
                    $this->redisClient->expire($redisKey, $metadata->ttl);
                }
                $this->identityMap[$key] = $document;
                $this->originalData[$key] = $data; // Store extracted (DB format) data
                $this->stats['writes']++;
            }
        }
        $this->redisClient->exec();
        $this->unitOfWork = [];
        $this->forceRebuildIndexes = false;
    }

    public function clear(): void
    {
        $this->identityMap = [];
        $this->unitOfWork = [];
        $this->originalData = [];
    }

    public function getRedisClient(): RedisClientAdapter
    {
        return $this->redisClient;
    }

    private function updateIndex(
        ClassMetadata $metadata,
        string $indexName,
                      $value,
        string $id
    ): void {
        if ($value === null) return;
        $indexKey = $metadata->getIndexKeyName($indexName, $value);
        $this->redisClient->sAdd($indexKey, $id);
        $ttl = $metadata->getIndexTTL($indexName);
        if ($ttl > 0) $this->redisClient->expire($indexKey, $ttl);
    }

    private function updateSortedIndex(
        ClassMetadata $metadata,
        string $indexName,
                      $value,
        string $id
    ): void {
        if ($value === null) return;
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(
                "Cannot add non-numeric value to sorted index '{$indexName}'. Got: " . gettype($value)
            );
        }
        $indexKey = $metadata->getSortedIndexKeyName($indexName);
        $this->redisClient->zAdd($indexKey, [$id => $value]); // phpredis zAdd syntax
        $ttl = $metadata->getSortedIndexTTL($indexName);
        if ($ttl > 0) $this->redisClient->expire($indexKey, $ttl);
    }

    /**
     * Helper method to clean up all indices for a document.
     * Uses SCAN for iterating index keys if original data is not available.
     * @param bool $isDeletionContext If true, assumes document is being deleted.
     */
    private function cleanupDocumentIndices(
        string $className,
        string $id,
        ClassMetadata $metadata,
        bool $isDeletionContext = false
    ): void {
        // Use originalData if available, as it reflects the state before modification/deletion
        $originalDocData = $this->originalData[$className . ':' . $id] ?? null;

        // If it's not a deletion and we don't have original data, we can't reliably clean old index entries
        // without knowing the old values. This case should ideally not happen if persist() fetches original data.
        if (!$isDeletionContext && !$originalDocData) {
            // Potentially log a warning: cannot clean indexes effectively without old values.
            return;
        }

        // Clean up regular indices
        foreach ($metadata->indices as $propertyName => $indexName) {
            $oldValue = null;
            if ($originalDocData) {
                $fieldName = $metadata->getFieldName($propertyName);
                if (isset($originalDocData[$fieldName])) {
                    $oldValue = $this->hydrator->convertToPhpValue(
                        $originalDocData[$fieldName],
                        $metadata->getFieldType($propertyName)
                    );
                }
            }

            if ($oldValue !== null) {
                $indexKey = $metadata->getIndexKeyName($indexName, $oldValue);
                $this->redisClient->sRem($indexKey, $id);
            } elseif ($isDeletionContext) { // Fallback for deletions if originalData was missing
                $pattern = $metadata->getIndexKeyPattern($indexName);
                $cursor = null;
                do {
                    [$cursor, $keys] = $this->redisClient->scan($cursor, ['match' => $pattern, 'count' => 100]);
                    foreach ($keys as $idxKey) {
                        $this->redisClient->sRem($idxKey, $id);
                    }
                } while ($cursor != 0);
            }
        }

        // Clean up sorted indices
        // For sorted indices, we remove the member by ID, score is not needed for removal here.
        foreach ($metadata->sortedIndices as $propertyName => $indexName) {
            $sortedIndexKey = $metadata->getSortedIndexKeyName($indexName);
            $this->redisClient->zRem($sortedIndexKey, $id);
        }
    }


    public function getStats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = ['reads' => 0, 'writes' => 0, 'deletes' => 0];
    }

    public function findByIds(string $documentClass, array $ids): array
    {
        if (empty($ids)) return [];
        $result = [];
        $this->redisClient->multi();
        $metadata = $this->metadataFactory->getMetadataFor($documentClass);
        $pendingLookups = [];

        foreach ($ids as $id) {
            $mapKey = $documentClass . ':' . (string)$id; // Ensure ID is string for key
            if (isset($this->identityMap[$mapKey])) {
                $result[(string)$id] = $this->identityMap[$mapKey];
            } else {
                $key = $metadata->getKeyName((string)$id);
                $pendingLookups[(string)$id] = $key;
                if ($metadata->storageType === 'hash') {
                    $this->redisClient->hGetAll($key);
                } elseif ($metadata->storageType === 'json') {
                    $this->redisClient->get($key);
                }
            }
        }

        if (!empty($pendingLookups)) {
            $responses = $this->redisClient->exec();
            if (!empty($responses)) {
                $i = 0;
                foreach ($pendingLookups as $id => $key) {
                    $data = $responses[$i++] ?? null;
                    if (empty($data)) continue;
                    if ($metadata->storageType === 'json' && is_string($data)) {
                        $data = json_decode($data, true);
                        if ($data === null) continue; // JSON decode error
                    }

                    $document = $this->hydrator->hydrate($documentClass, $data);
                    $reflProperty = new ReflectionProperty($documentClass, $metadata->idField);
                    $reflProperty->setAccessible(true);
                    $reflProperty->setValue($document, $id);
                    $result[$id] = $document;
                    $this->identityMap[$documentClass . ':' . $id] = $document;
                    $this->originalData[$documentClass . ':' . $id] = $data;
                }
                $this->stats['reads'] += count($pendingLookups);
            }
        }
        // Ensure original order of IDs if possible, or just return values
        $finalResult = [];
        foreach($ids as $id){ // Re-iterate original $ids to maintain order
            if(isset($result[(string)$id])){
                $finalResult[] = $result[(string)$id];
            }
        }
        return $finalResult;
    }

    public function count(string $documentClass, array $criteria = []): int
    {
        $repository = $this->getRepository($documentClass);
        if (empty($criteria)) {
            return $repository->count(); // Uses optimized SCAN count
        }
        // findBy now returns PaginatedResult, get total count from it
        return $repository->findBy($criteria)->getTotalCount();
    }

    public function executeCommand(string $command, ...$args)
    {
        return $this->redisClient->__call($command, $args);
    }

    public function getMetadataFactory(): MetadataFactory
    {
        return $this->metadataFactory;
    }
}