<?php

namespace Phillarmonic\AllegroRedisOdmBundle;

use Phillarmonic\AllegroRedisOdmBundle\Client\RedisClientAdapter;
use Phillarmonic\AllegroRedisOdmBundle\Hydrator\Hydrator;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\ClassMetadata;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use Phillarmonic\AllegroRedisOdmBundle\Repository\DocumentRepository;
use Phillarmonic\AllegroRedisOdmBundle\Repository\RepositoryFactory;
use ReflectionProperty;

class DocumentManager
{
    private array $repositories = [];
    private array $identityMap = [];
    private array $unitOfWork = [];

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

        // Hydrate document
        $document = $this->hydrator->hydrate($documentClass, $data);

        // Set ID value
        $reflProperty = new ReflectionProperty($documentClass, $metadata->idField);
        $reflProperty->setAccessible(true);
        $reflProperty->setValue($document, $id);

        // Add to identity map
        $this->identityMap[$mapKey] = $document;

        return $document;
    }

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
    }

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
                foreach ($metadata->indices as $propertyName => $indexName) {
                    // We need the old field value to remove from index
                    if (isset($this->identityMap[$key])) {
                        $oldDoc = $this->identityMap[$key];
                        $reflProperty = new ReflectionProperty($className, $propertyName);
                        $reflProperty->setAccessible(true);
                        $value = $reflProperty->getValue($oldDoc);

                        if ($value !== null) {
                            $indexKey = $metadata->getIndexKeyName($indexName, $value);
                            $this->redisClient->sRem($indexKey, $id);
                        }
                    }
                }

                // Remove from identity map
                unset($this->identityMap[$key]);
            } else {
                // Extract data from document
                $data = $this->hydrator->extract($document);

                // Handle indices
                foreach ($metadata->indices as $propertyName => $indexName) {
                    $reflProperty = new ReflectionProperty($className, $propertyName);
                    $reflProperty->setAccessible(true);
                    $value = $reflProperty->getValue($document);

                    if ($value !== null) {
                        $indexKey = $metadata->getIndexKeyName($indexName, $value);
                        $this->redisClient->sAdd($indexKey, $id);
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

                // Add to identity map
                $this->identityMap[$key] = $document;
            }
        }

        // Execute pipeline
        $this->redisClient->exec();

        // Clear unit of work
        $this->unitOfWork = [];
    }

    public function clear(): void
    {
        $this->identityMap = [];
        $this->unitOfWork = [];
    }

    public function getRedisClient(): RedisClientAdapter
    {
        return $this->redisClient;
    }
}