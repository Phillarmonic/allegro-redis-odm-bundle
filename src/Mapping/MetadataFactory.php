<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

use ReflectionClass;
use ReflectionProperty;

class MetadataFactory
{
    private array $metadata = [];
    private array $mappings = [];
    private ?array $documentClasses = null;

    public function __construct(array $mappings = [])
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
                    $metadata->addIndex($property->getName(), $indexName, $indexAttribute->ttl);
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

        return null;
    }

    private function getPropertyAttribute(ReflectionProperty $property, string $attributeClass): ?object
    {
        $attributes = $property->getAttributes($attributeClass);
        if (!empty($attributes)) {
            return $attributes[0]->newInstance();
        }

        return null;
    }


    /**
     * Get all document classes from configured mappings
     *
     * @return array Array of fully qualified class names that are documents
     */
    public function getAllDocumentClasses(): array
    {
        // Return cached results if available
        if ($this->documentClasses !== null) {
            error_log("Using cached document classes: " . count($this->documentClasses));
            return $this->documentClasses;
        }

        $this->documentClasses = [];
        error_log("Scanning for document classes...");

        // Scan each mapping directory for document classes
        foreach ($this->mappings as $mappingName => $mapping) {
            error_log("Processing mapping: " . $mappingName);

            if (!isset($mapping['dir'], $mapping['namespace'])) {
                error_log("Missing dir or namespace in mapping");
                continue;
            }

            $dir = $mapping['dir'];
            $namespace = $mapping['namespace'];
            error_log("Scanning directory: " . $dir);
            error_log("Using namespace: " . $namespace);

            if (!is_dir($dir)) {
                error_log("Warning: Mapping directory '$dir' does not exist");
                continue;
            }

            try {
                // Find PHP files in the directory and subdirectories
                $finder = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                $fileCount = 0;
                foreach ($finder as $file) {
                    $fileCount++;
                    // Skip directories and non-PHP files
                    if ($file->isDir() || $file->getExtension() !== 'php') {
                        continue;
                    }

                    error_log("Found PHP file: " . $file->getPathname());

                    // Get relative path from the mapping directory
                    $relativePath = str_replace($dir . DIRECTORY_SEPARATOR, '', $file->getRealPath());
                    $relativePath = str_replace('\\', '/', $relativePath); // Normalize directory separators
                    error_log("Relative path: " . $relativePath);

                    // Convert file path to class name
                    $relativeClass = str_replace('/', '\\', substr($relativePath, 0, -4)); // Remove .php
                    $className = $namespace . '\\' . $relativeClass;
                    error_log("Checking class: " . $className);

                    // Check if class exists and is a document
                    if (class_exists($className)) {
                        error_log("Class exists");
                        if ($this->isDocument($className)) {
                            error_log("Class is a document: " . $className);
                            $this->documentClasses[] = $className;
                        } else {
                            error_log("Class is not a document");
                        }
                    } else {
                        error_log("Class does not exist: " . $className);
                    }
                }
                error_log("Processed $fileCount files in $dir");
            } catch (\Exception $e) {
                error_log('Error scanning directory ' . $dir . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
        }

        error_log("Found " . count($this->documentClasses) . " document classes");
        return $this->documentClasses;
    }

    public function getMappings(): array
    {
        return $this->mappings;
    }
}