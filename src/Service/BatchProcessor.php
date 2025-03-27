<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Service;

use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use Phillarmonic\AllegroRedisOdmBundle\Repository\DocumentRepository;

/**
 * Batch processor for efficiently handling large datasets
 */
class BatchProcessor
{
    /**
     * @var int Default batch size
     */
    private int $batchSize;
    
    /**
     * @var DocumentManager
     */
    private DocumentManager $documentManager;
    
    /**
     * @var MetadataFactory
     */
    private MetadataFactory $metadataFactory;
    
    /**
     * @var array Current batch of documents
     */
    private array $batch = [];
    
    /**
     * @var int Counter for processed items
     */
    private int $processedCount = 0;
    
    /**
     * @var callable|null Progress callback
     */
    private $progressCallback = null;
    
    /**
     * @param DocumentManager $documentManager
     * @param MetadataFactory $metadataFactory
     * @param int $batchSize Default batch size
     */
    public function __construct(
        DocumentManager $documentManager,
        MetadataFactory $metadataFactory,
        int $batchSize = 100
    ) {
        $this->documentManager = $documentManager;
        $this->metadataFactory = $metadataFactory;
        $this->batchSize = $batchSize;
    }
    
    /**
     * Process a list of items in batches
     *
     * @param array $items Items to process
     * @param callable $processor Function that prepares an item for persistence
     * @param int|null $batchSize Optional custom batch size
     * @param callable|null $progressCallback Optional progress callback
     * @return int Number of items processed
     */
    public function processItems(
        array $items,
        callable $processor,
        ?int $batchSize = null,
        ?callable $progressCallback = null
    ): int {
        $this->resetState();
        $this->progressCallback = $progressCallback;
        $batchSize = $batchSize ?? $this->batchSize;
        
        foreach ($items as $item) {
            $document = $processor($item);
            
            if ($document) {
                $this->addToBatch($document);
                
                if (count($this->batch) >= $batchSize) {
                    $this->flushBatch();
                }
            }
        }
        
        // Flush any remaining items
        if (!empty($this->batch)) {
            $this->flushBatch();
        }
        
        return $this->processedCount;
    }
    
    /**
     * Process a repository query in batches
     *
     * @param DocumentRepository $repository
     * @param array $criteria Query criteria
     * @param callable $processor Function that processes each document
     * @param int|null $batchSize Optional custom batch size
     * @param callable|null $progressCallback Optional progress callback
     * @return int Number of items processed
     */
    public function processQuery(
        DocumentRepository $repository,
        array $criteria,
        callable $processor,
        ?int $batchSize = null,
        ?callable $progressCallback = null
    ): int {
        $this->resetState();
        $this->progressCallback = $progressCallback;
        $batchSize = $batchSize ?? $this->batchSize;
        
        return $repository->stream(function($document) use ($processor) {
            $result = $processor($document);
            
            if ($result) {
                $this->addToBatch($document);
                
                if (count($this->batch) >= $this->batchSize) {
                    $this->flushBatch();
                }
            }
            
            $this->notifyProgress();
        }, $criteria, $batchSize);
    }
    
    /**
     * Import a large dataset in batches
     *
     * @param string $documentClass The document class
     * @param array $data Array of data to import
     * @param callable $factory Function that creates a document from data
     * @param int|null $batchSize Optional custom batch size
     * @param callable|null $progressCallback Optional progress callback
     * @return int Number of items imported
     */
    public function importData(
        string $documentClass,
        array $data,
        callable $factory,
        ?int $batchSize = null,
        ?callable $progressCallback = null
    ): int {
        $this->resetState();
        $this->progressCallback = $progressCallback;
        $batchSize = $batchSize ?? $this->batchSize;
        $totalItems = count($data);
        
        for ($i = 0; $i < $totalItems; $i += $batchSize) {
            $chunk = array_slice($data, $i, $batchSize);
            
            foreach ($chunk as $item) {
                $document = $factory($item);
                
                if ($document) {
                    $this->documentManager->persist($document);
                    $this->processedCount++;
                }
            }
            
            $this->documentManager->flush();
            $this->documentManager->clear();
            
            if ($this->progressCallback) {
                call_user_func($this->progressCallback, $this->processedCount, $totalItems);
            }
        }
        
        return $this->processedCount;
    }
    
    /**
     * Add a document to the current batch
     *
     * @param object $document
     */
    private function addToBatch(object $document): void
    {
        $this->batch[] = $document;
        $this->documentManager->persist($document);
        $this->processedCount++;
    }
    
    /**
     * Flush the current batch to Redis
     */
    private function flushBatch(): void
    {
        $this->documentManager->flush();
        $this->documentManager->clear();
        $this->batch = [];
        $this->notifyProgress();
    }
    
    /**
     * Reset the processor state
     */
    private function resetState(): void
    {
        $this->batch = [];
        $this->processedCount = 0;
        $this->progressCallback = null;
    }

    /**
     * Notify progress callback if available
     */
    private function notifyProgress(): void
    {
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, $this->processedCount);
        }
    }

    /**
     * Export data in batches to prevent memory issues
     *
     * @param DocumentRepository $repository
     * @param callable $exporter Function that processes each document for export
     * @param array $criteria Optional criteria to filter documents
     * @param int|null $batchSize Optional custom batch size
     * @param callable|null $progressCallback Optional progress callback
     * @return array The exported data
     */
    public function exportData(
        DocumentRepository $repository,
        callable $exporter,
        array $criteria = [],
        ?int $batchSize = null,
        ?callable $progressCallback = null
    ): array {
        $this->resetState();
        $this->progressCallback = $progressCallback;
        $batchSize = $batchSize ?? $this->batchSize;

        $result = [];

        $repository->stream(function($document) use ($exporter, &$result) {
            $exportedItem = $exporter($document);

            if ($exportedItem !== null) {
                $result[] = $exportedItem;
            }

            $this->processedCount++;
            $this->notifyProgress();
        }, $criteria, $batchSize);

        return $result;
    }

    /**
     * Set the batch size
     *
     * @param int $batchSize
     * @return self
     */
    public function setBatchSize(int $batchSize): self
    {
        $this->batchSize = $batchSize;
        return $this;
    }

    /**
     * Get the current document manager
     *
     * @return DocumentManager
     */
    public function getDocumentManager(): DocumentManager
    {
        return $this->documentManager;
    }
}