<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Repository;

use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;

class RepositoryFactory
{
    /**
     * Repository instances cache
     */
    private array $repositories = [];

    /**
     * @param DocumentManager $documentManager The document manager
     * @param MetadataFactory $metadataFactory The metadata factory
     */
    public function __construct(
        private DocumentManager $documentManager,
        private MetadataFactory $metadataFactory
    ) {
    }

    /**
     * Get a repository for the given document class
     *
     * @param string $documentClass The fully qualified document class name
     * @return DocumentRepository
     */
    public function getRepository(string $documentClass): DocumentRepository
    {
        if (!isset($this->repositories[$documentClass])) {
            $metadata = $this->metadataFactory->getMetadataFor($documentClass);

            // Check if document class has a custom repository class
            if ($metadata->repositoryClass && class_exists($metadata->repositoryClass)) {
                $repositoryClass = $metadata->repositoryClass;

                // Ensure custom repository extends base DocumentRepository
                if (!is_subclass_of($repositoryClass, DocumentRepository::class)) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Custom repository class "%s" must extend "%s"',
                            $repositoryClass,
                            DocumentRepository::class
                        )
                    );
                }

                $this->repositories[$documentClass] = new $repositoryClass(
                    $this->documentManager,
                    $documentClass,
                    $metadata
                );
            } else {
                $this->repositories[$documentClass] = new DocumentRepository(
                    $this->documentManager,
                    $documentClass,
                    $metadata
                );
            }
        }

        return $this->repositories[$documentClass];
    }

    /**
     * Check if a repository exists for the given document class
     *
     * @param string $documentClass The fully qualified document class name
     * @return bool
     */
    public function hasRepository(string $documentClass): bool
    {
        return isset($this->repositories[$documentClass]);
    }

    /**
     * Get all registered repositories
     *
     * @return array<string, DocumentRepository> Associative array of document class => repository instance
     */
    public function getRepositories(): array
    {
        return $this->repositories;
    }

    /**
     * Clear all cached repository instances
     */
    public function clearRepositories(): void
    {
        $this->repositories = [];
    }

    /**
     * Clear a specific repository from the cache
     *
     * @param string $documentClass The fully qualified document class name
     */
    public function clearRepository(string $documentClass): void
    {
        if (isset($this->repositories[$documentClass])) {
            unset($this->repositories[$documentClass]);
        }
    }
}