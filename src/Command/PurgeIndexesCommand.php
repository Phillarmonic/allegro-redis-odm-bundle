<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Command;

use Phillarmonic\AllegroRedisOdmBundle\Client\RedisClientAdapter;
use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'allegro:purge-indexes',
    description: 'Purge stale Redis indexes for documents'
)]
class PurgeIndexesCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly MetadataFactory $metadataFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without actually deleting')
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Limit to a specific document class')
            ->addOption('index-name', 'i', InputOption::VALUE_REQUIRED, 'Limit to a specific index name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Redis ODM Index Purge');

        $dryRun = (bool) $input->getOption('dry-run');
        $specificClass = $input->getOption('class');
        $specificIndex = $input->getOption('index-name');

        if ($dryRun) {
            $io->note('Running in dry-run mode - no indexes will actually be deleted');
        }

        $redisClient = $this->documentManager->getRedisClient();
        $removedCount = 0;
        $scannedCount = 0;

        // Determine which classes to process
        $classesToProcess = [];
        if ($specificClass) {
            if (!class_exists($specificClass)) {
                $io->error("Class {$specificClass} not found");
                return Command::FAILURE;
            }

            $classesToProcess[] = $specificClass;
        } else {
            // Process all registered document classes
            // This would require a way to find all document classes
            // For simplicity, just use what's in memory for now
            $registeredMetadata = $this->getAllDocumentClasses();

            if (empty($registeredMetadata)) {
                $io->warning('No document classes found - check your mappings configuration');
                return Command::SUCCESS;
            }

            $classesToProcess = $registeredMetadata;
        }

        $io->section('Processing document classes:');
        $io->listing($classesToProcess);

        // Process each class
        foreach ($classesToProcess as $className) {
            $io->section("Processing indexes for {$className}");

            try {
                $metadata = $this->metadataFactory->getMetadataFor($className);

                // Get all document keys for this class
                $pattern = $metadata->getCollectionKeyPattern();
                $documentKeys = $redisClient->keys($pattern);
                $documentIds = [];

                foreach ($documentKeys as $key) {
                    // Extract ID from document key
                    $parts = explode(':', $key);
                    $documentIds[] = end($parts);
                }

                $io->note(sprintf('Found %d existing documents', count($documentIds)));

                // Process each index
                foreach ($metadata->getIndices() as $propertyName => $indexName) {
                    if ($specificIndex && $indexName !== $specificIndex) {
                        continue;
                    }

                    $io->text("Checking index <info>{$indexName}</info> for property <info>{$propertyName}</info>");

                    // Get all keys for this index
                    $pattern = $metadata->getIndexKeyPattern($indexName);
                    $indexKeys = $redisClient->keys($pattern);

                    $io->text(sprintf('Found %d index entries to check', count($indexKeys)));

                    foreach ($indexKeys as $indexKey) {
                        // Get all document IDs in this index
                        $idsInIndex = $redisClient->sMembers($indexKey);
                        $scannedCount += count($idsInIndex);

                        // Find orphaned IDs (those in index but no document exists)
                        $orphanedIds = array_diff($idsInIndex, $documentIds);

                        if (!empty($orphanedIds)) {
                            $io->text(sprintf('Found %d orphaned IDs in index %s', count($orphanedIds), $indexKey));

                            if (!$dryRun) {
                                foreach ($orphanedIds as $id) {
                                    $redisClient->sRem($indexKey, $id);
                                    $removedCount++;
                                }
                            } else {
                                $removedCount += count($orphanedIds);
                            }
                        }

                        // If index is now empty, remove it too
                        if (!$dryRun) {
                            $remaining = $redisClient->sCard($indexKey);
                            if ($remaining === 0) {
                                $redisClient->del($indexKey);
                                $io->text("<comment>Removed empty index key: {$indexKey}</comment>");
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $io->error("Error processing {$className}: " . $e->getMessage());
            }
        }

        $io->success(sprintf('Purge complete! Scanned %d entries and %s %d orphaned references',
            $scannedCount,
            $dryRun ? 'found' : 'removed',
            $removedCount
        ));

        return Command::SUCCESS;
    }

    /**
     * Get all document classes from the metadata factory
     */
    private function getAllDocumentClasses(): array
    {
        return $this->metadataFactory->getAllDocumentClasses();
    }
}