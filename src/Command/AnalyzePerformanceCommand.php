<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Command;

use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use Phillarmonic\AllegroRedisOdmBundle\Service\BulkOperations;
use Phillarmonic\AllegroRedisOdmBundle\Service\MappingDebuggerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'allegro:analyze-performance',
    description: 'Analyze Redis ODM performance for large datasets'
)]
class AnalyzePerformanceCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly MetadataFactory $metadataFactory,
        private readonly MappingDebuggerService $mappingDebugger,
        private readonly BulkOperations $bulkOperations // Injected BulkOperations
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'class',
                'c',
                InputOption::VALUE_REQUIRED,
                'Analyze a specific document class'
            )
            ->addOption(
                'index-stats',
                'i',
                InputOption::VALUE_NONE,
                'Show detailed index statistics'
            )
            ->addOption(
                'analyze-memory',
                'm',
                InputOption::VALUE_NONE,
                'Analyze memory usage of documents'
            )
            ->addOption(
                'sample-size',
                's',
                InputOption::VALUE_REQUIRED,
                'Number of documents to sample for analysis',
                100
            )
            ->addOption(
                'benchmark',
                'b',
                InputOption::VALUE_NONE,
                'Run benchmarks for common operations'
            )
            ->addOption(
                'show-indices',
                null,
                InputOption::VALUE_NONE,
                'Show all indices and their size'
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('Redis ODM Performance Analysis');

        $specificClass = $input->getOption('class');
        $showIndexStats = $input->getOption('index-stats');
        $analyzeMemory = $input->getOption('analyze-memory');
        $sampleSize = (int) $input->getOption('sample-size');
        $runBenchmarks = $input->getOption('benchmark');
        $showIndices = $input->getOption('show-indices');

        $classesToProcess = [];
        if ($specificClass) {
            if (!class_exists($specificClass)) {
                $io->error("Class {$specificClass} not found");
                return Command::FAILURE;
            }
            $classesToProcess[] = $specificClass;
        } else {
            $classesToProcess = $this->mappingDebugger->getAllDocumentClasses();
            if (empty($classesToProcess)) {
                $io->warning(
                    'No document classes found - check your mappings configuration'
                );
                return Command::SUCCESS;
            }
        }

        foreach ($classesToProcess as $className) {
            $io->section("Analyzing {$className}");
            try {
                $metadata = $this->metadataFactory->getMetadataFor($className);
                // Use BulkOperations to get stats, which uses SCAN
                $stats = $this->bulkOperations->getCollectionStats($className);

                $io->definitionList(
                    ['Collection' => $stats['collection']],
                    ['Prefix' => $stats['prefix'] ?: '(none)'],
                    [
                        'Document Count' => number_format(
                            $stats['document_count']
                        ),
                    ],
                    ['Storage Type' => $stats['storage_type']],
                    ['Regular Indices' => count($stats['indices'])],
                    ['Sorted Indices' => count($stats['sorted_indices'])]
                );

                if ($showIndexStats) {
                    $this->displayIndexStats($io, $stats);
                }
                if ($showIndices) {
                    $this->displayAllIndices($io, $metadata, $className);
                }
                if ($analyzeMemory) {
                    $this->analyzeMemoryUsage(
                        $io,
                        $className,
                        $sampleSize,
                        $metadata
                    );
                }
                if ($runBenchmarks) {
                    $this->runBenchmarks(
                        $io,
                        $className,
                        $sampleSize,
                        $metadata
                    );
                }
                $this->suggestOptimizations($io, $className, $stats);
            } catch (\Exception $e) {
                $io->error(
                    "Error analyzing {$className}: " . $e->getMessage()
                );
            }
        }
        return Command::SUCCESS;
    }

    private function displayIndexStats(SymfonyStyle $io, array $stats): void
    {
        $io->section('Index Statistics');
        if (!empty($stats['indices'])) {
            $io->text('Regular Indices:');
            $tableRows = [];
            foreach ($stats['indices'] as $indexName => $indexInfo) {
                $tableRows[] = [
                    $indexName,
                    $indexInfo['field'],
                    number_format($indexInfo['key_count']),
                    number_format($indexInfo['total_references']),
                ];
            }
            $indexTable = new Table($io);
            $indexTable->setHeaders([
                                        'Index Name',
                                        'Field',
                                        'Key Count',
                                        'Total References',
                                    ]);
            $indexTable->setRows($tableRows);
            $indexTable->render();
        } else {
            $io->text('No regular indices defined.');
        }

        if (!empty($stats['sorted_indices'])) {
            $io->text('Sorted Indices:');
            $tableRows = [];
            foreach ($stats['sorted_indices'] as $indexName => $indexInfo) {
                $tableRows[] = [
                    $indexName,
                    $indexInfo['field'],
                    number_format($indexInfo['cardinality']),
                ];
            }
            $sortedIndexTable = new Table($io);
            $sortedIndexTable->setHeaders(['Index Name', 'Field', 'Cardinality']);
            $sortedIndexTable->setRows($tableRows);
            $sortedIndexTable->render();
        } else {
            $io->text('No sorted indices defined.');
        }
    }

    /**
     * Display all indices and their size using SCAN.
     */
    private function displayAllIndices(
        SymfonyStyle $io,
                     $metadata,
        string $className
    ): void {
        $io->section('Index Details');
        $redisClient = $this->documentManager->getRedisClient();

        if (!empty($metadata->indices)) {
            $io->text('Regular Indices (Set Index):');
            $tableRows = [];
            foreach ($metadata->indices as $propertyName => $indexName) {
                $pattern = $metadata->getIndexKeyPattern($indexName);
                $cursor = null;
                do {
                    [$cursor, $keys] = $redisClient->scan(
                        $cursor,
                        ['match' => $pattern, 'count' => 100]
                    );
                    foreach ($keys as $key) {
                        $size = $redisClient->sCard($key);
                        $keyParts = explode(':', $key);
                        $value = end($keyParts);
                        $tableRows[] = [
                            $indexName,
                            $propertyName,
                            $value,
                            number_format($size),
                            $metadata->getIndexTTL($indexName) > 0
                                ? $metadata->getIndexTTL($indexName) . 's'
                                : 'No TTL',
                        ];
                    }
                } while ($cursor != 0);
            }
            if (!empty($tableRows)) {
                $indexTable = new Table($io);
                $indexTable->setHeaders([
                                            'Index Name',
                                            'Property',
                                            'Value',
                                            'Set Size',
                                            'TTL',
                                        ]);
                $indexTable->setRows($tableRows);
                $indexTable->render();
            } else {
                $io->text('No regular index entries found.');
            }
        }

        if (!empty($metadata->sortedIndices)) {
            $io->text('Sorted Indices (ZSet Index):');
            $tableRows = [];
            foreach ($metadata->sortedIndices as $propertyName => $indexName) {
                $key = $metadata->getSortedIndexKeyName($indexName);
                $size = $redisClient->zCard($key);
                $min = $redisClient->zRange($key, 0, 0, ['withscores' => true]);
                $max = $redisClient->zRevRange($key, 0, 0, ['withscores' => true]);
                $minVal = !empty($min) ? key($min) . ' (score: ' . current($min) . ')' : 'N/A';
                $maxVal = !empty($max) ? key($max) . ' (score: ' . current($max) . ')' : 'N/A';

                $tableRows[] = [
                    $indexName,
                    $propertyName,
                    number_format($size),
                    $minVal,
                    $maxVal,
                    $metadata->getSortedIndexTTL($indexName) > 0
                        ? $metadata->getSortedIndexTTL($indexName) . 's'
                        : 'No TTL',
                ];
            }
            if (!empty($tableRows)) {
                $sortedIndexTable = new Table($io);
                $sortedIndexTable->setHeaders([
                                                  'Index Name',
                                                  'Property',
                                                  'Size',
                                                  'Min Value',
                                                  'Max Value',
                                                  'TTL',
                                              ]);
                $sortedIndexTable->setRows($tableRows);
                $sortedIndexTable->render();
            } else {
                $io->text('No sorted index entries found.');
            }
        }
    }

    /**
     * Analyze memory usage of documents using SCAN.
     */
    private function analyzeMemoryUsage(
        SymfonyStyle $io,
        string $className,
        int $sampleSize,
                     $metadata // Pass metadata to avoid refetching
    ): void {
        $io->section('Memory Usage Analysis');
        $redisClient = $this->documentManager->getRedisClient();

        $allKeys = [];
        $this->bulkOperations->scanCollection(
            $className,
            function ($key) use (&$allKeys) {
                $allKeys[] = $key;
            },
            1000
        ); // Scan in batches of 1000

        if (empty($allKeys)) {
            $io->warning('No documents found for memory analysis.');
            return;
        }

        $actualSampleSize = min($sampleSize, count($allKeys));
        // Shuffle to get a random sample if $allKeys is large, or just take the first few
        if (count($allKeys) > $actualSampleSize * 2) { // Heuristic for shuffling
            shuffle($allKeys);
        }
        $sampleKeys = array_slice($allKeys, 0, $actualSampleSize);

        $totalSize = 0;
        $fieldSizes = [];
        $sampleCount = 0;

        foreach ($sampleKeys as $key) {
            $sampleCount++;
            if ($metadata->storageType === 'hash') {
                $data = $redisClient->hGetAll($key);
                $docSize = 0;
                foreach ($data as $field => $value) {
                    $valueSize = strlen($value);
                    $docSize += strlen($field) + $valueSize;
                    if (!isset($fieldSizes[$field])) {
                        $fieldSizes[$field] = ['total' => 0, 'count' => 0, 'min' => PHP_INT_MAX, 'max' => 0];
                    }
                    $fieldSizes[$field]['total'] += $valueSize;
                    $fieldSizes[$field]['count']++;
                    $fieldSizes[$field]['min'] = min($fieldSizes[$field]['min'], $valueSize);
                    $fieldSizes[$field]['max'] = max($fieldSizes[$field]['max'], $valueSize);
                }
                $totalSize += $docSize;
            } elseif ($metadata->storageType === 'json') {
                $jsonData = $redisClient->get($key);
                if ($jsonData) {
                    $docSize = strlen($jsonData);
                    $totalSize += $docSize;
                    $data = json_decode($jsonData, true);
                    if (is_array($data)) {
                        foreach ($data as $field => $value) {
                            $valueSize = strlen(json_encode($value)); // Approximation
                            if (!isset($fieldSizes[$field])) {
                                $fieldSizes[$field] = ['total' => 0, 'count' => 0, 'min' => PHP_INT_MAX, 'max' => 0];
                            }
                            $fieldSizes[$field]['total'] += $valueSize;
                            $fieldSizes[$field]['count']++;
                            $fieldSizes[$field]['min'] = min($fieldSizes[$field]['min'], $valueSize);
                            $fieldSizes[$field]['max'] = max($fieldSizes[$field]['max'], $valueSize);
                        }
                    }
                }
            }
        }

        $avgSize = $sampleCount > 0 ? $totalSize / $sampleCount : 0;
        $totalDocCount = $this->bulkOperations->getCollectionStats($className)['document_count'];
        $estimatedTotalSize = $avgSize * $totalDocCount;

        $io->definitionList(
            ['Sample Size' => $sampleCount],
            ['Average Document Size' => round($avgSize, 2) . ' bytes'],
            [
                'Estimated Total Collection Size' => $this->formatBytes(
                    $estimatedTotalSize
                ),
            ],
            ['Storage Type' => $metadata->storageType]
        );

        if (!empty($fieldSizes)) {
            $io->text('Field Size Analysis:');
            $tableRows = [];
            foreach ($fieldSizes as $field => $sizeInfo) {
                $avgFieldSize = $sizeInfo['count'] > 0 ? $sizeInfo['total'] / $sizeInfo['count'] : 0;
                $tableRows[] = [
                    $field,
                    round($avgFieldSize, 2) . ' bytes',
                    $sizeInfo['min'] . ' bytes',
                    $sizeInfo['max'] . ' bytes',
                    $this->formatBytes($sizeInfo['total']),
                ];
            }
            $fieldSizeTable = new Table($io);
            $fieldSizeTable->setHeaders(['Field', 'Average Size', 'Min Size', 'Max Size', 'Total Size (Sample)']);
            $fieldSizeTable->setRows($tableRows);
            $fieldSizeTable->render();
        }
    }

    /**
     * Run performance benchmarks using SCAN.
     */
    private function runBenchmarks(
        SymfonyStyle $io,
        string $className,
        int $sampleSize,
                     $metadata // Pass metadata
    ): void {
        $io->section('Performance Benchmarks');
        $repository = $this->documentManager->getRepository($className);
        $redisClient = $this->documentManager->getRedisClient();

        $allKeys = [];
        $this->bulkOperations->scanCollection(
            $className,
            function ($key) use (&$allKeys) {
                $allKeys[] = $key;
            },
            1000
        );

        if (empty($allKeys)) {
            $io->warning('No documents found for benchmarking.');
            return;
        }

        $actualSampleSize = min($sampleSize, count($allKeys));
        if (count($allKeys) > $actualSampleSize * 2) {
            shuffle($allKeys);
        }
        $sampleKeys = array_slice($allKeys, 0, $actualSampleSize);
        $sampleIds = array_map(function ($key) {
            $parts = explode(':', $key);
            return end($parts);
        }, $sampleKeys);

        $benchmarks = [];

        // Benchmark 1: Single document retrieval
        if (!empty($sampleIds)) {
            $io->text('Benchmarking single document retrieval...');
            $startTime = microtime(true);
            foreach ($sampleIds as $id) {
                $repository->find($id);
            }
            $endTime = microtime(true);
            $totalTime = $endTime - $startTime;
            $avgTime = $totalTime / count($sampleIds);
            $benchmarks[] = [
                'Find by ID',
                number_format($avgTime * 1000, 3) . ' ms',
                number_format(1 / $avgTime, 1) . ' ops/sec',
            ];
        }

        // Benchmark 2: Index lookup
        if (!empty($metadata->indices) && !empty($sampleIds)) {
            // Find an indexed property and a value from one of the sample documents
            $indexName = null;
            $propertyName = null;
            $testValue = null;

            // Try to find a value from sample docs for an indexed field
            $firstSampleDoc = $repository->find($sampleIds[0]);
            if ($firstSampleDoc) {
                foreach ($metadata->indices as $propName => $idxName) {
                    $getter = 'get' . ucfirst($propName);
                    if (method_exists($firstSampleDoc, $getter)) {
                        $val = $firstSampleDoc->$getter();
                        if ($val !== null) {
                            $indexName = $idxName;
                            $propertyName = $propName;
                            $testValue = $val;
                            break;
                        }
                    }
                }
            }

            if ($indexName && $propertyName && $testValue !== null) {
                $io->text("Benchmarking index lookup using {$indexName} for value '{$testValue}'...");
                $startTime = microtime(true);
                for ($i = 0; $i < count($sampleIds); $i++) { // Repeat to get a better average
                    $repository->findBy([$propertyName => $testValue]);
                }
                $endTime = microtime(true);
                $totalTime = $endTime - $startTime;
                $avgTime = $totalTime / count($sampleIds);
                $benchmarks[] = [
                    "Index Lookup ({$indexName})",
                    number_format($avgTime * 1000, 3) . ' ms',
                    number_format(1 / $avgTime, 1) . ' ops/sec',
                ];
            }
        }
        // Benchmark 4: Batch retrieval performance
        $io->text('Benchmarking batch retrieval...');
        $batchSizes = [10, 50, 100];
        foreach ($batchSizes as $batchSize) {
            if (count($sampleIds) >= $batchSize) {
                $batchTestIds = array_slice($sampleIds, 0, $batchSize);
                $startTime = microtime(true);
                $this->documentManager->findByIds($repository->getDocumentClass(), $batchTestIds);
                $endTime = microtime(true);
                $totalTime = $endTime - $startTime;
                $benchmarks[] = [
                    "Batch Find ({$batchSize} docs)",
                    number_format($totalTime * 1000, 3) . ' ms',
                    number_format($batchSize / $totalTime, 1) . ' docs/sec',
                ];
            }
        }

        if (!empty($benchmarks)) {
            $benchmarkTable = new Table($io);
            $benchmarkTable->setHeaders(['Operation', 'Average Time', 'Throughput']);
            $benchmarkTable->setRows($benchmarks);
            $benchmarkTable->render();
        } else {
            $io->text('Not enough data or configuration to run benchmarks.');
        }
    }

    private function suggestOptimizations(
        SymfonyStyle $io,
        string $className,
        array $stats
    ): void {
        $io->section('Optimization Suggestions');
        $suggestions = [];

        if ($stats['document_count'] > 1000 && empty($stats['indices']) && empty($stats['sorted_indices'])) {
            $suggestions[] = ['High Priority', 'Add indices for fields used in queries', 'Large collection with no indices can lead to full collection scans (slow queries).'];
        }
        if (count($stats['indices']) > 5) {
            $suggestions[] = ['Medium Priority', 'Review index usage', 'Too many indices can increase memory usage, slow down writes, and might not all be beneficial.'];
        }
        // ... (other suggestions remain the same)
        if ($stats['document_count'] > 5000 && $stats['storage_type'] === 'json') {
            $suggestions[] = ['Medium Priority', 'Consider hash storage for large collections if field-level updates are common or memory is a concern.', 'Hash storage can be more memory efficient and allow atomic field updates. JSON is simpler for complex nested data.'];
        }
        if ($stats['document_count'] > 10000) {
            $suggestions[] = ['High Priority', 'Ensure queries are paginated and use `stream()` or `BatchProcessor` for bulk operations.', 'Avoid loading all documents from large collections into memory at once.'];
        }


        if (empty($suggestions)) {
            $io->text('No specific optimization suggestions for this collection based on current stats.');
        } else {
            $suggestionsTable = new Table($io);
            $suggestionsTable->setHeaders(['Priority', 'Suggestion', 'Reason']);
            $suggestionsTable->setRows($suggestions);
            $suggestionsTable->render();
        }
    }

    private function formatBytes(float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}