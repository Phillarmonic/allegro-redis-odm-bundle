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

    public array $fields = [];
    public array $indices = [];

    public function __construct(string $className)
    {
        $this->className = $className;
    }

    public function addField(string $propertyName, string $fieldName, string $type, bool $nullable): void
    {
        $this->fields[$propertyName] = [
            'name' => $fieldName,
            'type' => $type,
            'nullable' => $nullable
        ];
    }

    public function addIndex(string $propertyName, string $indexName): void
    {
        $this->indices[$propertyName] = $indexName;
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
            return $this->prefix . ':idx:' . $this->collection . ':' . $indexName . ':' . $value;
        }

        return 'idx:' . $this->collection . ':' . $indexName . ':' . $value;
    }

    /**
     * Get the index key pattern for a specific index
     */
    public function getIndexKeyPattern(string $indexName): string
    {
        if ($this->prefix) {
            return $this->prefix . ':idx:' . $this->collection . ':' . $indexName . ':*';
        }

        return 'idx:' . $this->collection . ':' . $indexName . ':*';
    }

    /**
     * Get all field names mapped to properties
     *
     * @return array Array where keys are field names and values are property names
     */
    public function getFieldNames(): array
    {
        $fieldNames = [];
        foreach ($this->fields as $propertyName => $fieldInfo) {
            $fieldNames[$fieldInfo['name']] = $propertyName;
        }
        return $fieldNames;
    }

    /**
     * Get property name for a field
     */
    public function getPropertyName(string $fieldName): ?string
    {
        foreach ($this->fields as $propertyName => $fieldInfo) {
            if ($fieldInfo['name'] === $fieldName) {
                return $propertyName;
            }
        }
        return null;
    }

    /**
     * Get field name for a property
     */
    public function getFieldName(string $propertyName): ?string
    {
        if (isset($this->fields[$propertyName])) {
            return $this->fields[$propertyName]['name'];
        }
        return null;
    }

    /**
     * Get field type for a property
     */
    public function getFieldType(string $propertyName): ?string
    {
        if (isset($this->fields[$propertyName])) {
            return $this->fields[$propertyName]['type'];
        }
        return null;
    }

    /**
     * Check if a property is nullable
     */
    public function isNullable(string $propertyName): bool
    {
        if (isset($this->fields[$propertyName])) {
            return $this->fields[$propertyName]['nullable'];
        }
        return false;
    }

    /**
     * Check if a property is indexed
     */
    public function isIndexed(string $propertyName): bool
    {
        return isset($this->indices[$propertyName]);
    }

    /**
     * Get the collection name without prefix
     */
    public function getCollectionName(): string
    {
        return $this->collection;
    }

    /**
     * Get all indices as property name => index name mapping
     */
    public function getIndices(): array
    {
        return $this->indices;
    }
}