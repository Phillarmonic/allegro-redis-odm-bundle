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

    public function __construct($mappingsOrParams = [])
    {
        if ($mappingsOrParams instanceof ParameterBagInterface) {
            $this->parameterBag = $mappingsOrParams;
            try {
                $this->mappings = $mappingsOrParams->get(
                    'allegro_redis_odm.mappings'
                );
            } catch (\Exception $e) {
                // error_log("Error retrieving mappings from parameter bag: " . $e->getMessage());
                $this->mappings = [];
            }
        } elseif (is_array($mappingsOrParams)) {
            $this->mappings = $mappingsOrParams;
        }
    }

    public function getMetadataFor(string $className): ClassMetadata
    {
        $this->refreshMappings();

        if (isset($this->metadata[$className])) {
            return $this->metadata[$className];
        }

        $metadata = new ClassMetadata($className);
        $reflClass = new ReflectionClass($className);

        $documentAttribute = $this->getClassAttribute(
            $reflClass,
            Document::class
        );
        if ($documentAttribute === null) {
            throw new \InvalidArgumentException(
                "Class '$className' is not a valid document. Did you forget to add the #[Document] attribute?"
            );
        }
        $metadata->prefix = $documentAttribute->prefix;
        $metadata->collection = $documentAttribute->collection ?:
            $this->getDefaultCollectionName($reflClass);
        $metadata->repositoryClass = $documentAttribute->repository;

        if ($this->getClassAttribute($reflClass, RedisHash::class)) {
            $metadata->storageType = 'hash';
        }
        if ($this->getClassAttribute($reflClass, RedisJson::class)) {
            $metadata->storageType = 'json';
        }
        if ($expirationAttribute = $this->getClassAttribute(
            $reflClass,
            Expiration::class
        )) {
            $metadata->ttl = $expirationAttribute->ttl;
        }

        foreach ($reflClass->getProperties() as $property) {
            if ($idAttribute = $this->getPropertyAttribute(
                $property,
                Id::class
            )) {
                $metadata->idField = $property->getName();
                $metadata->idStrategy = $idAttribute->strategy;
                continue;
            }

            if ($fieldAttribute = $this->getPropertyAttribute(
                $property,
                Field::class
            )) {
                $fieldName = $fieldAttribute->name ?: $property->getName();
                $metadata->addField(
                    $property->getName(),
                    $fieldName,
                    $fieldAttribute->type,
                    $fieldAttribute->nullable,
                    $fieldAttribute->unique // Pass the unique flag
                );

                if ($indexAttribute = $this->getPropertyAttribute(
                    $property,
                    Index::class
                )) {
                    $indexName = $indexAttribute->name ?: $fieldName;
                    $metadata->addIndex(
                        $property->getName(),
                        $indexName,
                        $indexAttribute->ttl
                    );
                }
                if ($sortedIndexAttribute = $this->getPropertyAttribute(
                    $property,
                    SortedIndex::class
                )) {
                    if (
                        !in_array($fieldAttribute->type, ['integer', 'float'])
                    ) {
                        throw new \InvalidArgumentException(
                            "Property '{$property->getName()}' in class '{$className}' has a SortedIndex attribute but is not a numeric type. " .
                            "SortedIndex can only be used with 'integer' or 'float' field types."
                        );
                    }
                    $indexName = $sortedIndexAttribute->name ?: $fieldName;
                    $metadata->addSortedIndex(
                        $property->getName(),
                        $indexName,
                        $sortedIndexAttribute->ttl
                    );
                }
            }
        }
        $this->metadata[$className] = $metadata;
        return $metadata;
    }

    public function isDocument(string $className): bool
    {
        try {
            $reflClass = new ReflectionClass($className);
            return $this->getClassAttribute($reflClass, Document::class) !==
                null;
        } catch (\ReflectionException $e) {
            return false;
        }
    }

    private function getDefaultCollectionName(
        ReflectionClass $reflClass
    ): string {
        return strtolower(
            preg_replace('/([a-z])([A-Z])/', '$1_$2', $reflClass->getShortName())
        );
    }

    private function getClassAttribute(
        ReflectionClass $class,
        string $attributeClass
    ): ?object {
        $attributes = $class->getAttributes($attributeClass);
        return !empty($attributes) ? $attributes[0]->newInstance() : null;
    }

    private function getPropertyAttribute(
        ReflectionProperty $property,
        string $attributeClass
    ): ?object {
        $attributes = $property->getAttributes($attributeClass);
        return !empty($attributes) ? $attributes[0]->newInstance() : null;
    }

    public function getAllDocumentClasses(): array
    {
        $this->refreshMappings();
        if ($this->documentClasses !== null) {
            return $this->documentClasses;
        }
        $this->documentClasses = [];
        if (empty($this->mappings)) {
            return [];
        }

        foreach ($this->mappings as $mappingName => $mapping) {
            if (!isset($mapping['dir'], $mapping['namespace'])) {
                continue;
            }
            $dir = $mapping['dir'];
            $namespace = $mapping['namespace'];
            if (!is_dir($dir)) {
                if (
                    $this->parameterBag !== null &&
                    $this->parameterBag->has('kernel.project_dir')
                ) {
                    $projectDir = $this->parameterBag->get(
                        'kernel.project_dir'
                    );
                    $resolvedDir = $projectDir . '/' . $dir;
                    if (is_dir($resolvedDir)) {
                        $dir = $resolvedDir;
                    } else {
                        continue;
                    }
                } else {
                    continue;
                }
            }
            try {
                $this->scanDirectoryForDocuments($dir, $namespace);
            } catch (\Exception $e) {
                // error_log('Error scanning directory ' . $dir . ': ' . $e->getMessage());
            }
        }
        return $this->documentClasses;
    }

    private function refreshMappings(): void
    {
        if (
            $this->parameterBag !== null &&
            $this->parameterBag->has('allegro_redis_odm.mappings')
        ) {
            $mappings = $this->parameterBag->get('allegro_redis_odm.mappings');
            if ($mappings !== $this->mappings) {
                $this->mappings = $mappings;
                $this->documentClasses = null;
            }
        }
    }

    private function scanDirectoryForDocuments(
        string $dir,
        string $namespace
    ): void {
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
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, $dirLength);
            $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);
            $relativePath = str_replace(
                DIRECTORY_SEPARATOR,
                '\\',
                $relativePath
            );
            $relativeClass = substr($relativePath, 0, -4);
            $className = $namespace . '\\' . $relativeClass;

            if (!class_exists($className)) {
                continue;
            }
            if ($this->isDocument($className)) {
                $this->documentClasses[] = $className;
            }
        }
    }

    public function getMappings(): array
    {
        $this->refreshMappings();
        return $this->mappings;
    }

    public function clearMetadataCache(?string $className = null): void
    {
        if ($className !== null) {
            unset($this->metadata[$className]);
        } else {
            $this->metadata = [];
            $this->documentClasses = null;
        }
    }
}