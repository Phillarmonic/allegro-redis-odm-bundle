<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

use Doctrine\Common\Annotations\Reader;
use ReflectionClass;
use ReflectionProperty;

class MetadataFactory
{
    private array $metadata = [];
    private array $mappings = [];

    public function __construct(private ?Reader $reader = null, array $mappings = [])
    {
        $this->mappings = $mappings;
    }

    public function getMetadataFor(string $className): ClassMetadata
    {
        if (isset($this->metadata[$className])) {
            return $this->metadata[$className];
        }

        $metadata = new ClassMetadata($className);
        $reflClass = new ReflectionClass($className);

        // Process class-level attributes
        $documentAttribute = $this->getClassAttribute($reflClass, Document::class);

        if ($documentAttribute === null) {
            throw new \InvalidArgumentException("Class '$className' is not a valid document. Did you forget to add the #[Document] attribute?");
        }

        $metadata->prefix = $documentAttribute->prefix;
        $metadata->collection = $documentAttribute->collection ?: $this->getDefaultCollectionName($reflClass);
        $metadata->repositoryClass = $documentAttribute->repository;

        // Check for storage type
        $redisHashAttribute = $this->getClassAttribute($reflClass, RedisHash::class);
        if ($redisHashAttribute) {
            $metadata->storageType = 'hash';
            if ($redisHashAttribute->prefix) {
                $metadata->prefix = $redisHashAttribute->prefix;
            }
        }

        $redisJsonAttribute = $this->getClassAttribute($reflClass, RedisJson::class);
        if ($redisJsonAttribute) {
            $metadata->storageType = 'json';
            if ($redisJsonAttribute->prefix) {
                $metadata->prefix = $redisJsonAttribute->prefix;
            }
        }

        // Process expiration if set
        $expirationAttribute = $this->getClassAttribute($reflClass, Expiration::class);
        if ($expirationAttribute) {
            $metadata->ttl = $expirationAttribute->ttl;
        }

        // Process properties
        foreach ($reflClass->getProperties() as $property) {
            // Process ID field
            $idAttribute = $this->getPropertyAttribute($property, Id::class);
            if ($idAttribute) {
                $metadata->idField = $property->getName();
                $metadata->idStrategy = $idAttribute->strategy;
                continue;
            }

            // Process regular fields
            $fieldAttribute = $this->getPropertyAttribute($property, Field::class);
            if ($fieldAttribute) {
                $fieldName = $fieldAttribute->name ?: $property->getName();
                $metadata->addField(
                    $property->getName(),
                    $fieldName,
                    $fieldAttribute->type,
                    $fieldAttribute->nullable
                );

                // Check for index
                $indexAttribute = $this->getPropertyAttribute($property, Index::class);
                if ($indexAttribute) {
                    $indexName = $indexAttribute->name ?: $fieldName;
                    $metadata->addIndex($property->getName(), $indexName);
                }
            }
        }

        $this->metadata[$className] = $metadata;
        return $metadata;
    }

    /**
     * Check if a class is a valid document
     */
    public function isDocument(string $className): bool
    {
        try {
            $reflClass = new ReflectionClass($className);
            return $this->getClassAttribute($reflClass, Document::class) !== null;
        } catch (\ReflectionException $e) {
            return false;
        }
    }

    private function getDefaultCollectionName(ReflectionClass $reflClass): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $reflClass->getShortName()));
    }

    private function getClassAttribute(ReflectionClass $class, string $attributeClass): ?object
    {
        $attributes = $class->getAttributes($attributeClass);
        if (!empty($attributes)) {
            return $attributes[0]->newInstance();
        }

        // Legacy annotation support
        if ($this->reader !== null) {
            $annotation = $this->reader->getClassAnnotation($class, $attributeClass);
            if ($annotation) {
                return $annotation;
            }
        }

        return null;
    }

    private function getPropertyAttribute(ReflectionProperty $property, string $attributeClass): ?object
    {
        $attributes = $property->getAttributes($attributeClass);
        if (!empty($attributes)) {
            return $attributes[0]->newInstance();
        }

        // Legacy annotation support
        if ($this->reader !== null) {
            $annotation = $this->reader->getPropertyAnnotation($property, $attributeClass);
            if ($annotation) {
                return $annotation;
            }
        }

        return null;
    }
}