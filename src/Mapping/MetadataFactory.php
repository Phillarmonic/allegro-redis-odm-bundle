<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class MetadataFactory
{
    private array $metadata = [];
    private array $mappings = [];
    private ?array $documentClasses = null;
    private ?ParameterBagInterface $parameterBag = null;

    /**
     * Constructor accepts either direct mappings array or a parameter bag to fetch from container
     *
     * @param array|ParameterBagInterface $mappingsOrParams Either mappings array or container parameters
     */
    public function __construct($mappingsOrParams = [])
    {
        // If we received a parameter bag, store it for later use
        if ($mappingsOrParams instanceof ParameterBagInterface) {
            $this->parameterBag = $mappingsOrParams;
            try {
                $this->mappings = $mappingsOrParams->get('allegro_redis_odm.mappings');
//                error_log("MetadataFactory initialized with mappings from parameter bag: " . print_r($this->mappings, true));
            } catch (\Exception $e) {
                error_log("Error retrieving mappings from parameter bag: " . $e->getMessage());
                $this->mappings = [];
            }
        }
        // Otherwise, use the provided mappings directly
        else if (is_array($mappingsOrParams)) {
            $this->mappings = $mappingsOrParams;
            error_log("MetadataFactory initialized with mappings array: " . print_r($this->mappings, true));
        }
    }

    public function getMetadataFor(string $className): ClassMetadata
    {
        // Ensure we have the latest mappings from the parameter bag
        $this->refreshMappings();

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
        // Ensure we have the latest mappings from the parameter bag
        $this->refreshMappings();

        // Return cached results if available
        if ($this->documentClasses !== null) {
            return $this->documentClasses;
        }

        $this->documentClasses = [];

        // If no mappings are configured, return empty array
        if (empty($this->mappings)) {
            error_log("No mappings configured in MetadataFactory");
            return [];
        }

        // Scan each mapping directory for document classes
        foreach ($this->mappings as $mappingName => $mapping) {
            error_log("Processing mapping: " . $mappingName);

            if (!isset($mapping['dir'], $mapping['namespace'])) {
                error_log("Missing dir or namespace in mapping {$mappingName}");
                continue;
            }

            $dir = $mapping['dir'];
            $namespace = $mapping['namespace'];

            if (!is_dir($dir)) {
                error_log("Warning: Mapping directory '{$dir}' does not exist");

                // Try to resolve relative to project root if we have access to the parameter bag
                if ($this->parameterBag !== null && $this->parameterBag->has('kernel.project_dir')) {
                    $projectDir = $this->parameterBag->get('kernel.project_dir');
                    $resolvedDir = $projectDir . '/' . $dir;

                    if (is_dir($resolvedDir)) {
                        $dir = $resolvedDir;
                        error_log("Resolved directory to '{$dir}'");
                    } else {
                        error_log("Could not resolve directory even with project root: '{$resolvedDir}'");
                        continue;
                    }
                } else {
                    continue;
                }
            }

            try {
                $this->scanDirectoryForDocuments($dir, $namespace);
            } catch (\Exception $e) {
                error_log('Error scanning directory ' . $dir . ': ' . $e->getMessage());
            }
        }

        return $this->documentClasses;
    }

    /**
     * Refresh mappings from parameter bag if available
     */
    private function refreshMappings(): void
    {
        if ($this->parameterBag !== null && $this->parameterBag->has('allegro_redis_odm.mappings')) {
            $mappings = $this->parameterBag->get('allegro_redis_odm.mappings');
            if ($mappings !== $this->mappings) {
                error_log("Refreshing mappings from parameter bag - found different mappings");
                $this->mappings = $mappings;
                // Reset document classes cache to force re-scanning
                $this->documentClasses = null;
            }
        }
    }

    /**
     * Scan a directory for document classes
     *
     * @param string $dir Directory to scan
     * @param string $namespace Base namespace for documents
     */
    private function scanDirectoryForDocuments(string $dir, string $namespace): void
    {
        $directoryIterator = new \RecursiveDirectoryIterator(
            $dir,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );

        $iterator = new \RecursiveIteratorIterator(
            $directoryIterator,
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $dirLength = strlen($dir);

        foreach ($iterator as $file) {
            // Skip non-PHP files
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, $dirLength);
            // Remove leading directory separator if present
            $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);
            // Replace directory separators with namespace separators
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
            // Remove .php extension
            $relativeClass = substr($relativePath, 0, -4);
            // Construct full class name
            $className = $namespace . '\\' . $relativeClass;

            error_log("Found potential class: {$className}");

            // Check if class exists (will trigger autoloading)
            if (!class_exists($className)) {
                error_log("Class {$className} does not exist or cannot be autoloaded");
                continue;
            }

            // Check if it's a document class
            if ($this->isDocument($className)) {
                error_log("Found document class: {$className}");
                $this->documentClasses[] = $className;
            } else {
                error_log("Class {$className} exists but is not a document (no Document attribute found)");
            }
        }
    }

    public function getMappings(): array
    {
        // Ensure we have the latest mappings from the parameter bag
        $this->refreshMappings();
        return $this->mappings;
    }
}