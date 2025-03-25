<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Repository;

use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\ClassMetadata;

class DocumentRepository
{
    public function __construct(
        protected DocumentManager $documentManager,
        protected string $documentClass,
        protected ClassMetadata $metadata
    ) {
    }

    public function find(string $id)
    {
        return $this->documentManager->find($this->documentClass, $id);
    }

    public function findAll(): array
    {
        $redisClient = $this->documentManager->getRedisClient();
        $pattern = $this->metadata->getCollectionKeyPattern();
        $keys = $redisClient->keys($pattern);

        $result = [];
        foreach ($keys as $key) {
            // Extract ID from key
            $parts = explode(':', $key);
            $id = end($parts);

            $document = $this->find($id);
            if ($document) {
                $result[] = $document;
            }
        }

        return $result;
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        // For indexed fields, use the index to find documents
        if (count($criteria) === 1) {
            $field = key($criteria);
            $value = current($criteria);

            // Check if field is indexed
            if (isset($this->metadata->indices[$field])) {
                $indexName = $this->metadata->indices[$field];
                $indexKey = $this->metadata->getIndexKeyName($indexName, $value);

                $redisClient = $this->documentManager->getRedisClient();
                $ids = $redisClient->sMembers($indexKey);

                $result = [];
                foreach ($ids as $id) {
                    $document = $this->find($id);
                    if ($document) {
                        $result[] = $document;
                    }
                }

                return $result;
            }
        }

        // Fallback to loading all documents and filtering in PHP
        $documents = $this->findAll();
        $result = [];

        foreach ($documents as $document) {
            $match = true;
            foreach ($criteria as $field => $value) {
                $getter = 'get' . ucfirst($field);
                if (method_exists($document, $getter)) {
                    if ($document->$getter() != $value) {
                        $match = false;
                        break;
                    }
                } else {
                    // Try to access property directly
                    $reflProperty = new \ReflectionProperty($this->documentClass, $field);
                    $reflProperty->setAccessible(true);
                    if ($reflProperty->getValue($document) != $value) {
                        $match = false;
                        break;
                    }
                }
            }

            if ($match) {
                $result[] = $document;
            }
        }

        // Apply ordering if specified
        if ($orderBy) {
            usort($result, function($a, $b) use ($orderBy) {
                foreach ($orderBy as $field => $direction) {
                    $getter = 'get' . ucfirst($field);

                    if (method_exists($a, $getter) && method_exists($b, $getter)) {
                        $valueA = $a->$getter();
                        $valueB = $b->$getter();
                    } else {
                        // Try to access property directly
                        $reflProperty = new \ReflectionProperty($this->documentClass, $field);
                        $reflProperty->setAccessible(true);
                        $valueA = $reflProperty->getValue($a);
                        $valueB = $reflProperty->getValue($b);
                    }

                    if ($valueA == $valueB) {
                        continue;
                    }

                    $comparison = $valueA <=> $valueB;
                    return strtoupper($direction) === 'DESC' ? -$comparison : $comparison;
                }

                return 0;
            });
        }

        // Apply limit and offset
        if ($offset !== null || $limit !== null) {
            $result = array_slice($result, $offset ?? 0, $limit);
        }

        return $result;
    }

    public function findOneBy(array $criteria): ?object
    {
        $results = $this->findBy($criteria, null, 1);
        return !empty($results) ? $results[0] : null;
    }

    public function count(): int
    {
        $redisClient = $this->documentManager->getRedisClient();
        $pattern = $this->metadata->getCollectionKeyPattern();
        $keys = $redisClient->keys($pattern);

        return count($keys);
    }
}