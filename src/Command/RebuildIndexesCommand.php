<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Command;

use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use Phillarmonic\AllegroRedisOdmBundle\Service\MappingDebuggerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'allegro:rebuild-indexes',
    description: 'Rebuild Redis indexes for documents'
)]
class RebuildIndexesCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly MetadataFactory $metadataFactory,
        private readonly MappingDebuggerService $mappingDebugger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Limit to a specific document class')
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Number of documents to process in each batch', 100)
            ->addOption('clear-indexes', null, InputOption::VALUE_NONE, 'Clear existing indexes before rebuilding')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->addOption('show-mappings', 'm', InputOption::VALUE_NONE, 'Show detailed mappings information');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Redis ODM Index Rebuild');

        // Display mappings information if requested
        if ($input->getOption('show-mappings')) {
            $this->mappingDebugger->displayMappingsInfo($io);
        } else {
            // Just show a simplified version
            $io->section('Configured Mappings:');
            $mappingsConfig = $this->metadataFactory->getMappings();
            foreach ($mappingsConfig as $name => $mapping) {
                $io->text("Mapping: $name");
                $io->text("  Directory: " . ($mapping['dir'] ?? 'Not set'));
                $io->text("  Namespace: " . ($mapping['namespace'] ?? 'Not set'));
                $io->text("  Exists: " . (is_dir($mapping['dir'] ?? '') ? 'Yes' : 'No'));
            }
        }

        $specificClass = $input->getOption('class');
        $batchSize = (int) $input->getOption('batch-size');
        $clearIndexes = (bool) $input->getOption('clear-indexes');
        $force = (bool) $input->getOption('force');

        // Determine which classes to process
        $classesToProcess = [];
        if ($specificClass) {
            if (!class_exists($specificClass)) {
                $io->error("Class {$specificClass} not found");
                return Command::FAILURE;
            }

            $classesToProcess[] = $specificClass;
        } else {
            // Get all document classes using the debugger
            $classesToProcess = $this->mappingDebugger->getAllDocumentClasses();

            if (empty($classesToProcess)) {
                $io->warning('No document classes found - check your mappings configuration');
                return Command::SUCCESS;
            }
        }

        $io->section('Classes to process:');
        $io->listing($classesToProcess);

        // Ask for confirmation if not forced
        if (!$force) {
            $io->note('This operation will rebuild all indexed fields for the selected document classes.');
            if ($clearIndexes) {
                $io->warning('You have selected to clear existing indexes before rebuilding. This will remove ALL indexes for the selected classes.');
            }
            if (!$io->confirm('Do you want to continue?', false)) {
                $io->note('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $documentsProcessed = 0;
        $indexesRebuilt = 0;

        // Process each class
        foreach ($classesToProcess as $className) {
            $io->section("Processing {$className}");

            try {
                $metadata = $this->metadataFactory->getMetadataFor($className);
                $indices = $metadata->getIndices();

                if (empty($indices)) {
                    $io->note("No indexes defined for {$className}, skipping.");
                    continue;
                }

                $io->text(sprintf('Found %d indexed fields: %s',
                    count($indices),
                    implode(', ', array_keys($indices)))
                );

                // Clear existing indexes if requested
                if ($clearIndexes) {
                    $this->clearExistingIndexes($metadata, $io);
                }

                // Get the document repository and find all documents
                $repository = $this->documentManager->getRepository($className);
                $documents = $repository->findAll();

                $io->note(sprintf('Found %d documents to process', count($documents)));

                if (count($documents) === 0) {
                    $io->text('No documents to process, skipping.');
                    continue;
                }

                // Create a progress bar
                $progressBar = $io->createProgressBar(count($documents));
                $progressBar->start();

                // Process documents in batches
                $i = 0;
                $batch = [];

                foreach ($documents as $document) {
                    $batch[] = $document;
                    $i++;

                    if (count($batch) >= $batchSize) {
                        $this->processBatch($batch);
                        $progressBar->advance(count($batch));
                        $documentsProcessed += count($batch);
                        $indexesRebuilt += count($batch) * count($indices);
                        $batch = [];
                    }
                }

                // Process any remaining documents
                if (!empty($batch)) {
                    $this->processBatch($batch);
                    $progressBar->advance(count($batch));
                    $documentsProcessed += count($batch);
                    $indexesRebuilt += count($batch) * count($indices);
                }

                $progressBar->finish();
                $io->newLine(2);

                $io->success(sprintf('Successfully processed %d documents with %d index entries for class %s',
                    count($documents),
                    count($documents) * count($indices),
                    $className)
                );
            } catch (\Exception $e) {
                $io->error("Error processing {$className}: " . $e->getMessage());
                return Command::FAILURE;
            }
        }

        $io->success(sprintf('Index rebuild complete! Processed %d documents and rebuilt %d index entries across %d classes.',
            $documentsProcessed,
            $indexesRebuilt,
            count($classesToProcess)
        ));

        return Command::SUCCESS;
    }

    /**
     * Process a batch of documents - persist and flush
     *
     * @param array $documents The documents to process
     * @throws \ReflectionException
     */
    private function processBatch(array $documents): void
    {
        // Enable force rebuild of indexes before processing the batch
        $this->documentManager->enableForceRebuildIndexes();

        foreach ($documents as $document) {
            $this->documentManager->persist($document);
        }

        $this->documentManager->flush();
        // No need to explicitly disable, as flush() resets it automatically
    }

    /**
     * Clear existing indexes for a document class
     *
     * @param \Phillarmonic\AllegroRedisOdmBundle\Mapping\ClassMetadata $metadata The class metadata
     * @param SymfonyStyle $io The I/O interface
     */
    private function clearExistingIndexes($metadata, SymfonyStyle $io): void
    {
        $io->text('Clearing existing indexes...');
        $redisClient = $this->documentManager->getRedisClient();

        foreach ($metadata->getIndices() as $propertyName => $indexName) {
            $pattern = $metadata->getIndexKeyPattern($indexName);
            $keys = $redisClient->keys($pattern);

            if (!empty($keys)) {
                $redisClient->del($keys);
                $io->text(sprintf('Cleared %d index keys for index %s', count($keys), $indexName));
            }
        }
    }
}