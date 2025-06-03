<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Mapping;

class ClassMetadata
{
    public string $className;
    public string $prefix = '';
    public string $collection;
    public ?string $repositoryClass = null;
    public string $idField;
    public string $idStrategy = 'auto';
    public string $storageType = 'hash';
    public int $ttl = 0;

    public array $fields = []; // Item: ['name', 'type', 'nullable', 'unique']
    public array $indices = [];
    public array $indicesTTL = [];
    public array $sortedIndices = [];
    public array $sortedIndicesTTL = [];

    public function __construct(string $className)
    {
        $this->className = $className;
    }

    public function addField(
        string $propertyName,
        string $fieldName,
        string $type,
        bool $nullable,
        bool $unique = false
    ): void {
        $this->fields[$propertyName] = [
            'name' => $fieldName,
            'type' => $type,
            'nullable' => $nullable,
            'unique' => $unique, // Store unique flag
        ];
    }

    public function addIndex(
        string $propertyName,
        string $indexName,
        int $ttl = 0
    ): void {
        $this->indices[$propertyName] = $indexName;
        $this->indicesTTL[$indexName] = $ttl;
    }

    public function addSortedIndex(
        string $propertyName,
        string $indexName,
        int $ttl = 0
    ): void {
        $this->sortedIndices[$propertyName] = $indexName;
        $this->sortedIndicesTTL[$indexName] = $ttl;
    }

    public function getKeyName(string $id): string
    {
        if ($this->prefix) {
            return $this->prefix . ':' . $this->collection . ':' . $id;
        }
        return $this->collection . ':' . $id;
    }

    public function getCollectionKeyPattern(): string
    {
        if ($this->prefix) {
            return $this->prefix . ':' . $this->collection . ':*';
        }
        return $this->collection . ':*';
    }

    public function getIndexKeyName(string $indexName, $value): string
    {
        if ($this->prefix) {
            return $this->prefix .
                ':idx:' .
                $this->collection .
                ':' .
                $indexName .
                ':' .
                $value;
        }
        return 'idx:' . $this->collection . ':' . $indexName . ':' . $value;
    }

    public function getIndexKeyPattern(string $indexName): string
    {
        if ($this->prefix) {
            return $this->prefix .
                ':idx:' .
                $this->collection .
                ':' .
                $indexName .
                ':*';
        }
        return 'idx:' . $this->collection . ':' . $indexName . ':*';
    }

    public function getIndexTTL(string $indexName): int
    {
        return $this->indicesTTL[$indexName] ?? 0;
    }

    public function getSortedIndexKeyName(string $indexName): string
    {
        if ($this->prefix) {
            return $this->prefix . ':zidx:' . $this->collection . ':' . $indexName;
        }
        return 'zidx:' . $this->collection . ':' . $indexName;
    }

    public function getSortedIndexTTL(string $indexName): int
    {
        return $this->sortedIndicesTTL[$indexName] ?? 0;
    }

    public function getFieldNames(): array
    {
        $fieldNames = [];
        foreach ($this->fields as $propertyName => $fieldInfo) {
            $fieldNames[$fieldInfo['name']] = $propertyName;
        }
        return $fieldNames;
    }

    public function getPropertyName(string $fieldName): ?string
    {
        foreach ($this->fields as $propertyName => $fieldInfo) {
            if ($fieldInfo['name'] === $fieldName) {
                return $propertyName;
            }
        }
        return null;
    }

    public function getFieldName(string $propertyName): ?string
    {
        return $this->fields[$propertyName]['name'] ?? null;
    }

    public function getFieldType(string $propertyName): ?string
    {
        return $this->fields[$propertyName]['type'] ?? null;
    }

    public function isNullable(string $propertyName): bool
    {
        return $this->fields[$propertyName]['nullable'] ?? false;
    }

    public function isIndexed(string $propertyName): bool
    {
        return isset($this->indices[$propertyName]);
    }

    public function getCollectionName(): string
    {
        return $this->collection;
    }

    public function getIndices(): array
    {
        return $this->indices;
    }

    public function getSortedIndices(): array
    {
        return $this->sortedIndices;
    }

    public function getIndicesTTL(): array
    {
        return $this->indicesTTL;
    }

    public function getSortedIndicesTTL(): array
    {
        return $this->sortedIndicesTTL;
    }

    // New method to check if a property has a unique constraint
    public function isUnique(string $propertyName): bool
    {
        return $this->fields[$propertyName]['unique'] ?? false;
    }

    // New method to generate the Redis key for a unique constraint
    public function getUniqueConstraintKey(
        string $fieldName,
               $phpValue,
        string $fieldType
    ): string {
        $valueForKey = '';
        switch ($fieldType) {
            case 'datetime':
                if ($phpValue instanceof \DateTimeInterface) {
                    $valueForKey = $phpValue->format(
                        \DateTimeInterface::RFC3339_EXTENDED
                    );
                } elseif (is_numeric($phpValue)) {
                    $valueForKey = (new \DateTime('@' . $phpValue))->format(
                        \DateTimeInterface::RFC3339_EXTENDED
                    );
                } else {
                    $valueForKey = (string) $phpValue;
                }
                break;
            case 'boolean':
                $valueForKey = $phpValue ? '1' : '0';
                break;
            default:
                if (is_scalar($phpValue)) {
                    $valueForKey = (string) $phpValue;
                } elseif (
                    is_object($phpValue) &&
                    method_exists($phpValue, '__toString')
                ) {
                    $valueForKey = (string) $phpValue;
                } else {
                    $valueForKey = md5(serialize($phpValue)); // Fallback for complex types
                }
                break;
        }

        // Basic sanitization for the value part of the key
        $valueForKey = str_replace(':', '_', $valueForKey);
        $valueForKey = preg_replace('/\s+/', '_', $valueForKey);

        $key = 'unq:' . $this->collection . ':' . $fieldName . ':' . $valueForKey;
        if ($this->prefix) {
            return $this->prefix . ':' . $key;
        }
        return $key;
    }
}