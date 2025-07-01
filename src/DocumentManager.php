<?php

namespace Phillarmonic\AllegroRedisOdmBundle;

use Carbon\CarbonImmutable;
use Phillarmonic\AllegroRedisOdmBundle\Client\RedisClientAdapter;
use Phillarmonic\AllegroRedisOdmBundle\Exception\DuplicateDocumentIdException;
use Phillarmonic\AllegroRedisOdmBundle\Exception\ImmutableIdException;
use Phillarmonic\AllegroRedisOdmBundle\Exception\UniqueConstraintViolationException;
use Phillarmonic\AllegroRedisOdmBundle\Hydrator\Hydrator;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\ClassMetadata;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\TimestampableTrait;
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
    private array $uniqueConstraintOps = [];

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
            $repositoryClass =
                $metadata->repositoryClass ?: DocumentRepository::class;
            $this->repositories[$documentClass] = new $repositoryClass(
                $this,
                $documentClass,
                $metadata
            );
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

        if ($data === null) {
            return null;
        }

        $this->stats['reads']++;
        $document = $this->hydrator->hydrate($documentClass, $data);
        $reflProperty = new ReflectionProperty(
            $documentClass,
            $metadata->idField
        );
        $reflProperty->setAccessible(true);
        $reflProperty->setValue($document, $id); // Ensure ID on object matches fetched ID
        $this->identityMap[$mapKey] = $document;
        $this->originalData[$mapKey] = $data;
        return $document;
    }

    public function persist($document): void
    {
        $className = get_class($document);
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $idFieldRefl = new ReflectionProperty($className, $metadata->idField);
        $idFieldRefl->setAccessible(true);
        $currentIdOnObject = $idFieldRefl->getValue($document);

        $mapKeyPrefix = $className . ':';
        $isManaged = false;

        if (empty($currentIdOnObject) && $metadata->idStrategy === 'auto') {
            $currentIdOnObject = uniqid('', true);
            $idFieldRefl->setValue($document, $currentIdOnObject);
        }

        if (empty($currentIdOnObject)) {
            throw new \RuntimeException(
                'Document ID cannot be empty for persist operation.'
            );
        }

        $idStr = (string) $currentIdOnObject;
        $mapKey = $mapKeyPrefix . $idStr;
        $isManaged =
            isset($this->identityMap[$mapKey]) &&
            $this->identityMap[$mapKey] === $document; // Check if THIS instance is managed

        if (!$isManaged) {
            // This instance is not currently managed by this ID.
            // Check if an *entirely different* document already exists in Redis with this ID.
            $redisKeyForId = $metadata->getKeyName($idStr);
            $existsInRedis = false;
            if ($metadata->storageType === 'hash') {
                $existsInRedis = $this->redisClient->hLen($redisKeyForId) > 0;
            } elseif ($metadata->storageType === 'json') {
                $existsInRedis = $this->redisClient->exists($redisKeyForId);
            }

            if ($existsInRedis) {
                throw new DuplicateDocumentIdException(
                    sprintf(
                        "Cannot persist document of class '%s' with ID '%s'. A document with this ID already exists in Redis. Load and update the existing document if you intend to change it, or use a different ID for this new document.",
                        $className,
                        $idStr
                    )
                );
            }
        }

        $this->unitOfWork[$mapKey] = $document;
        $this->identityMap[$mapKey] = $document; // Ensure it's in identity map

        // If the document is new to the DocumentManager's tracking for this flush cycle
        if (!isset($this->originalData[$mapKey])) {
            if ($isManaged) {
                // This should ideally not happen if $isManaged is true,
                // as originalData should have been populated when it was first managed/loaded.
                // But as a safeguard, fetch if missing.
                $this->fetchOriginalData($document, $idStr, $metadata);
            } else {
                // It's a truly new document (passed the duplicate ID check above)
                // or an unmanaged instance that didn't exist in Redis.
                // For unique constraint checks, its "original" field values are all null/empty.
                $this->originalData[$mapKey] = [];
            }
        }
    }

    private function isNewDocument($document): bool // Used by old persist, less relevant now
    {
        $className = get_class($document);
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $reflProperty = new ReflectionProperty(
            $className,
            $metadata->idField
        );
        $reflProperty->setAccessible(true);
        $id = $reflProperty->getValue($document);

        if (empty($id)) {
            return true;
        }
        $key = $metadata->getKeyName((string) $id);
        if ($metadata->storageType === 'hash') {
            return $this->redisClient->hLen($key) === 0;
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

        $dataFetched = null;
        if ($metadata->storageType === 'hash') {
            $dataFetched = $this->redisClient->hGetAll($key);
        } elseif ($metadata->storageType === 'json') {
            $jsonData = $this->redisClient->get($key);
            if ($jsonData) {
                $dataFetched = json_decode($jsonData, true);
            }
        }
        $this->originalData[$mapKey] = !empty($dataFetched) ? $dataFetched : [];

        if (!empty($this->originalData[$mapKey])) {
            $this->stats['reads']++;
        }
    }

    public function remove($document): void
    {
        $className = get_class($document);
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $reflProperty = new ReflectionProperty(
            $className,
            $metadata->idField
        );
        $reflProperty->setAccessible(true);
        $id = $reflProperty->getValue($document);

        if (empty($id)) {
            // Document was never persisted or has no ID, cannot remove.
            // Or, if you want to remove it from UoW if it was added without ID:
            // unset($this->unitOfWork[spl_object_hash($document)]); // Example if using object hash as key for un-IDed
            return; // Or throw exception
        }
        $this->unitOfWork[$className . ':' . (string) $id] = null; // Mark for deletion
    }

    public function flush(): void
    {
        $pendingUniqueChecks = [];
        $this->uniqueConstraintOps = [];

        // Phase 1: Plan unique constraint changes and check for violations
        foreach ($this->unitOfWork as $uowKey => $documentInUow) {
            [$className, $originalIdFromUowKey] = explode(':', $uowKey, 2);
            $metadata = $this->metadataFactory->getMetadataFor($className);

            if ($documentInUow !== null) {
                // Document is being created or updated
                $idFieldRefl = new ReflectionProperty(
                    $className,
                    $metadata->idField
                );
                $idFieldRefl->setAccessible(true);
                $currentIdOnObject = (string) $idFieldRefl->getValue(
                    $documentInUow
                );

                if ($currentIdOnObject !== $originalIdFromUowKey) {
                    throw new ImmutableIdException(
                        sprintf(
                            "Attempted to change the ID of document '%s' from '%s' to '%s'. Document IDs are immutable after initial persistence.",
                            $className,
                            $originalIdFromUowKey,
                            $currentIdOnObject
                        )
                    );
                }
            }

            // Use $originalIdFromUowKey for all operations related to this UoW entry
            $currentDocumentIdForOps = $originalIdFromUowKey;
            $originalDocData = $this->originalData[$uowKey] ?? [];

            if ($documentInUow === null) {
                // Document is being deleted
                foreach ($metadata->fields as $propName => $fieldInfo) {
                    if ($fieldInfo['unique']) {
                        $fieldName = $fieldInfo['name'];
                        if (isset($originalDocData[$fieldName])) {
                            $oldValue = $this->hydrator->convertToPhpValue(
                                $originalDocData[$fieldName],
                                $fieldInfo['type']
                            );
                            if ($oldValue !== null) {
                                $uniqueKey = $metadata->getUniqueConstraintKey(
                                    $fieldName,
                                    $oldValue,
                                    $fieldInfo['type']
                                );
                                $this->uniqueConstraintOps[] = [
                                    'op' => 'del',
                                    'key' => $uniqueKey,
                                ];
                            }
                        }
                    }
                }
            } else {
                // Document is being created or updated
                foreach ($metadata->fields as $propName => $fieldInfo) {
                    if ($fieldInfo['unique']) {
                        $fieldName = $fieldInfo['name'];
                        $reflProp = new ReflectionProperty(
                            $className,
                            $propName
                        );
                        $reflProp->setAccessible(true);
                        $newValue = $reflProp->getValue($documentInUow);

                        $oldValue = null;
                        if (isset($originalDocData[$fieldName])) {
                            $oldValue = $this->hydrator->convertToPhpValue(
                                $originalDocData[$fieldName],
                                $fieldInfo['type']
                            );
                        }

                        if ($newValue !== $oldValue) {
                            if ($oldValue !== null) {
                                $oldUniqueKey =
                                    $metadata->getUniqueConstraintKey(
                                        $fieldName,
                                        $oldValue,
                                        $fieldInfo['type']
                                    );
                                $this->uniqueConstraintOps[] = [
                                    'op' => 'del',
                                    'key' => $oldUniqueKey,
                                ];
                            }
                            if ($newValue !== null) {
                                $newUniqueKey =
                                    $metadata->getUniqueConstraintKey(
                                        $fieldName,
                                        $newValue,
                                        $fieldInfo['type']
                                    );
                                $pendingUniqueChecks[] = [
                                    'key' => $newUniqueKey,
                                    'docId' => $currentDocumentIdForOps,
                                    'field' => $propName,
                                    'value' => $newValue,
                                ];
                                $this->uniqueConstraintOps[] = [
                                    'op' => 'set',
                                    'key' => $newUniqueKey,
                                    'docId' => $currentDocumentIdForOps,
                                ];
                            }
                        }
                    }
                }
            }
        }

        foreach ($pendingUniqueChecks as $check) {
            $existingHolderId = $this->redisClient->get($check['key']);
            if (
                $existingHolderId !== false &&
                $existingHolderId !== null &&
                $existingHolderId !== $check['docId']
            ) {
                $this->uniqueConstraintOps = [];
                throw new UniqueConstraintViolationException(
                    sprintf(
                        "Unique constraint violation for field '%s' with value '%s'. Document ID '%s' already holds this value for key '%s'.",
                        $check['field'],
                        is_scalar($check['value'])
                            ? (string) $check['value']
                            : gettype($check['value']),
                        $existingHolderId,
                        $check['key']
                    )
                );
            }
        }

        // Phase 2: Execute Redis operations
        $this->redisClient->multi();

        foreach ($this->uniqueConstraintOps as $opDetail) {
            if ($opDetail['op'] === 'set') {
                $this->redisClient->set($opDetail['key'], $opDetail['docId']);
            } elseif ($opDetail['op'] === 'del') {
                $this->redisClient->del($opDetail['key']);
            }
        }

        foreach ($this->unitOfWork as $uowKey => $documentInUow) {
            [$className, $originalIdFromUowKey] = explode(':', $uowKey, 2);
            $metadata = $this->metadataFactory->getMetadataFor($className);
            $redisDocKey = $metadata->getKeyName($originalIdFromUowKey);
            $originalDocDataForIndexes = $this->originalData[$uowKey] ?? [];

            if ($documentInUow === null) {
                // Deletion
                $this->redisClient->del($redisDocKey);
                $this->cleanupDocumentIndices(
                    $className,
                    $originalIdFromUowKey,
                    $metadata,
                    true,
                    $originalDocDataForIndexes
                );
                unset($this->identityMap[$uowKey]);
                unset($this->originalData[$uowKey]);
                $this->stats['deletes']++;
            } else {
                // Create or Update

                // START: Timestampable Trait Logic
                $isTimestampable = in_array(
                    TimestampableTrait::class,
                    class_uses($documentInUow)
                );

                if ($isTimestampable) {
                    $now = new CarbonImmutable();
                    // Always set updated_at on persist
                    $documentInUow->setUpdatedAt($now);

                    // Set created_at only if it's a new document
                    if (empty($originalDocDataForIndexes)) {
                        $documentInUow->setCreatedAt($now);
                    }
                }
                // END: Timestampable Trait Logic

                $dataToStore = $this->hydrator->extract($documentInUow);

                // Regular Index updates
                foreach ($metadata->indices as $propName => $indexName) {
                    $reflProp = new ReflectionProperty(
                        $className,
                        $propName
                    );
                    $reflProp->setAccessible(true);
                    $newValueIdx = $reflProp->getValue($documentInUow);
                    $oldValueIdx = null;
                    if (
                        isset(
                            $originalDocDataForIndexes[
                            $metadata->getFieldName($propName)
                            ]
                        )
                    ) {
                        $oldValueIdx = $this->hydrator->convertToPhpValue(
                            $originalDocDataForIndexes[
                            $metadata->getFieldName($propName)
                            ],
                            $metadata->getFieldType($propName)
                        );
                    }
                    if (
                        $this->forceRebuildIndexes ||
                        $oldValueIdx !== $newValueIdx
                    ) {
                        if (
                            $oldValueIdx !== null &&
                            !$this->forceRebuildIndexes
                        ) {
                            $oldIdxKey = $metadata->getIndexKeyName(
                                $indexName,
                                $oldValueIdx
                            );
                            $this->redisClient->sRem(
                                $oldIdxKey,
                                $originalIdFromUowKey
                            );
                        }
                        if ($newValueIdx !== null) {
                            $this->updateIndex(
                                $metadata,
                                $indexName,
                                $newValueIdx,
                                $originalIdFromUowKey
                            );
                        }
                    }
                }
                // Sorted Index updates
                foreach ($metadata->sortedIndices as $propName => $indexName) {
                    $reflProp = new ReflectionProperty(
                        $className,
                        $propName
                    );
                    $reflProp->setAccessible(true);
                    $newValSortedIdx = $reflProp->getValue($documentInUow);
                    $oldValSortedIdx = null;
                    if (
                        isset(
                            $originalDocDataForIndexes[
                            $metadata->getFieldName($propName)
                            ]
                        )
                    ) {
                        $oldValSortedIdx = $this->hydrator->convertToPhpValue(
                            $originalDocDataForIndexes[
                            $metadata->getFieldName($propName)
                            ],
                            $metadata->getFieldType($propName)
                        );
                    }
                    if (
                        $this->forceRebuildIndexes ||
                        $oldValSortedIdx !== $newValSortedIdx
                    ) {
                        if (
                            $oldValSortedIdx !== null &&
                            !$this->forceRebuildIndexes
                        ) {
                            $sortedIdxKey = $metadata->getSortedIndexKeyName(
                                $indexName
                            );
                            $this->redisClient->zRem(
                                $sortedIdxKey,
                                $originalIdFromUowKey
                            );
                        }
                        if ($newValSortedIdx !== null) {
                            $this->updateSortedIndex(
                                $metadata,
                                $indexName,
                                $newValSortedIdx,
                                $originalIdFromUowKey
                            );
                        }
                    }
                }

                if ($metadata->storageType === 'hash') {
                    $this->redisClient->hMSet($redisDocKey, $dataToStore);
                } elseif ($metadata->storageType === 'json') {
                    $this->redisClient->set(
                        $redisDocKey,
                        json_encode($dataToStore)
                    );
                }
                if ($metadata->ttl > 0) {
                    $this->redisClient->expire($redisDocKey, $metadata->ttl);
                }
                // $this->identityMap[$uowKey] is already set by persist
                $this->originalData[$uowKey] = $dataToStore; // Update originalData to reflect new state
                $this->stats['writes']++;
            }
        }
        $this->redisClient->exec();
        $this->unitOfWork = [];
        $this->uniqueConstraintOps = [];
        $this->forceRebuildIndexes = false;
    }

    public function clear(): void
    {
        $this->identityMap = [];
        $this->unitOfWork = [];
        $this->originalData = [];
        $this->uniqueConstraintOps = [];
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
        if ($value === null) {
            return;
        }
        $indexKey = $metadata->getIndexKeyName($indexName, $value);
        $this->redisClient->sAdd($indexKey, $id);
        $ttl = $metadata->getIndexTTL($indexName);
        if ($ttl > 0) {
            $this->redisClient->expire($indexKey, $ttl);
        }
    }

    private function updateSortedIndex(
        ClassMetadata $metadata,
        string $indexName,
                      $value,
        string $id
    ): void {
        if ($value === null) {
            return;
        }
        if (!is_numeric($value) && !$value instanceof \DateTimeInterface) {
            throw new \InvalidArgumentException(
                "Cannot add non-numeric or non-DateTime value to sorted index '{$indexName}'. Got: " .
                gettype($value)
            );
        }
        $score =
            $value instanceof \DateTimeInterface
                ? $value->getTimestamp()
                : $value;
        $indexKey = $metadata->getSortedIndexKeyName($indexName);
        $this->redisClient->zAdd($indexKey, [$id => $score]);
        $ttl = $metadata->getSortedIndexTTL($indexName);
        if ($ttl > 0) {
            $this->redisClient->expire($indexKey, $ttl);
        }
    }

    private function cleanupDocumentIndices(
        string $className,
        string $id,
        ClassMetadata $metadata,
        bool $isDeletionContext = false,
        ?array $originalDocDataForCleanup = null // Now passed explicitly
    ): void {
        $docData = $originalDocDataForCleanup ?? [];

        foreach ($metadata->indices as $propertyName => $indexName) {
            $oldValue = null;
            $fieldName = $metadata->getFieldName($propertyName);
            if (isset($docData[$fieldName])) {
                $oldValue = $this->hydrator->convertToPhpValue(
                    $docData[$fieldName],
                    $metadata->getFieldType($propertyName)
                );
            }

            if ($oldValue !== null) {
                $indexKey = $metadata->getIndexKeyName($indexName, $oldValue);
                $this->redisClient->sRem($indexKey, $id);
            } elseif ($isDeletionContext && empty($docData)) {
                // Fallback for deletions if originalData was missing
                $pattern = $metadata->getIndexKeyPattern($indexName);
                $cursor = null;
                do {
                    [$cursor, $keys] = $this->redisClient->scan($cursor, [
                        'match' => $pattern,
                        'count' => 100,
                    ]);
                    foreach ($keys as $idxKey) {
                        $this->redisClient->sRem($idxKey, $id);
                    }
                } while ($cursor != 0);
            }
        }

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
        if (empty($ids)) {
            return [];
        }
        $result = [];
        $metadata = $this->metadataFactory->getMetadataFor($documentClass);
        $pendingLookups = [];
        $keysToFetchInRedis = [];

        foreach ($ids as $id) {
            $idStr = (string) $id;
            $mapKey = $documentClass . ':' . $idStr;
            if (isset($this->identityMap[$mapKey])) {
                $result[$idStr] = $this->identityMap[$mapKey];
            } else {
                $redisKey = $metadata->getKeyName($idStr);
                $pendingLookups[$idStr] = $redisKey; // Store original ID against Redis key
                $keysToFetchInRedis[] = $redisKey; // Store Redis key for fetching
            }
        }

        if (!empty($pendingLookups)) {
            $this->redisClient->multi();
            foreach ($pendingLookups as $idStr => $redisKey) {
                if ($metadata->storageType === 'hash') {
                    $this->redisClient->hGetAll($redisKey);
                } elseif ($metadata->storageType === 'json') {
                    $this->redisClient->get($redisKey);
                }
            }
            $responses = $this->redisClient->exec();

            if (!empty($responses)) {
                $i = 0;
                // Iterate based on the order of pendingLookups to map responses correctly
                foreach ($pendingLookups as $idStr => $redisKey) {
                    $data = $responses[$i++] ?? null;
                    if (empty($data)) {
                        continue;
                    }
                    if (
                        $metadata->storageType === 'json' && is_string($data)
                    ) {
                        $data = json_decode($data, true);
                        if ($data === null) {
                            continue;
                        }
                    }
                    $document = $this->hydrator->hydrate(
                        $documentClass,
                        $data
                    );
                    $reflProp = new ReflectionProperty(
                        $documentClass,
                        $metadata->idField
                    );
                    $reflProp->setAccessible(true);
                    $reflProp->setValue($document, $idStr); // Use $idStr (original ID)
                    $result[$idStr] = $document;
                    $this->identityMap[$documentClass . ':' . $idStr] = $document;
                    $this->originalData[$documentClass . ':' . $idStr] = $data;
                }
                $this->stats['reads'] += count($pendingLookups);
            }
        }
        $finalResult = [];
        foreach ($ids as $id) {
            $idStr = (string) $id;
            if (isset($result[$idStr])) {
                $finalResult[] = $result[$idStr];
            }
        }
        return $finalResult;
    }

    public function count(string $documentClass, array $criteria = []): int
    {
        $repository = $this->getRepository($documentClass);
        if (empty($criteria)) {
            return $repository->count();
        }
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