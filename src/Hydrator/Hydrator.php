<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Hydrator;

use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use DateTime;
use ReflectionProperty;

class Hydrator
{
    public function __construct(private MetadataFactory $metadataFactory)
    {
    }

    public function hydrate(string $className, array $data)
    {
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $document = new $className();

        foreach ($metadata->fields as $propertyName => $fieldInfo) {
            $fieldName = $fieldInfo['name'];

            // Skip if field doesn't exist in data and is nullable
            if (!isset($data[$fieldName]) && $fieldInfo['nullable']) {
                continue;
            }

            // Get raw value
            $value = $data[$fieldName] ?? null;

            // Convert to appropriate PHP type
            $value = $this->convertToPhpValue($value, $fieldInfo['type']);

            // Set property value
            $reflProperty = new ReflectionProperty($className, $propertyName);
            $reflProperty->setAccessible(true);
            $reflProperty->setValue($document, $value);
        }

        return $document;
    }

    public function extract($document): array
    {
        $className = get_class($document);
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $data = [];

        foreach ($metadata->fields as $propertyName => $fieldInfo) {
            $fieldName = $fieldInfo['name'];

            // Get property value
            $reflProperty = new ReflectionProperty($className, $propertyName);
            $reflProperty->setAccessible(true);
            $value = $reflProperty->getValue($document);

            // Skip null values if field is nullable
            if ($value === null && $fieldInfo['nullable']) {
                continue;
            }

            // Convert to Redis value
            $data[$fieldName] = $this->convertToDatabaseValue($value, $fieldInfo['type']);
        }

        return $data;
    }

    private function convertToPhpValue($value, string $type)
    {
        if ($value === null) {
            return null;
        }

        switch ($type) {
            case 'string':
                return (string) $value;
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'boolean':
                return (bool) $value;
            case 'datetime':
                // Check if value is empty or invalid before creating DateTime
                if (empty($value) || !is_numeric($value)) {
                    return null;
                }
                return new DateTime('@' . $value);
            case 'json':
                return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            default:
                return $value;
        }
    }

    private function convertToDatabaseValue($value, string $type)
    {
        if ($value === null) {
            return null;
        }

        switch ($type) {
            case 'string':
                return (string) $value;
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'boolean':
                return (int) $value;
            case 'datetime':
                if ($value instanceof DateTime) {
                    return $value->getTimestamp();
                }
                return (int) $value;
            case 'json':
                return json_encode($value, JSON_THROW_ON_ERROR);
            default:
                return (string) $value;
        }
    }
}