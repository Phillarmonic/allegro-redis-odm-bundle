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
        private readonly BulkOperations $bulkOperations
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Analyze a specific document class')
            ->addOption('index-stats', 'i', InputOption::VALUE_NONE, 'Show detailed index statistics')
            ->addOption('analyze-memory', 'm', InputOption::VALUE_NONE, 'Analyze memory usage of documents')
            ->addOption('sample-size', 's', InputOption::VALUE_REQUIRED, 'Number of documents to sample for analysis', 100)
            ->addOption('benchmark', 'b', InputOption::VALUE_NONE, 'Run benchmarks for common operations')
            ->addOption('show-indices', null, InputOption::VALUE_NONE, 'Show all indices and their size');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Redis ODM Performance Analysis');

        $specificClass = $input->getOption('class');
        $showIndexStats = $input->getOption('index-stats');
        $analyzeMemory = $input->getOption('analyze-memory');
        $sampleSize = (int)$input->getOption('sample-size');
        $runBenchmarks = $input->getOption('benchmark');
        $showIndices = $input->getOption('show-indices');

        // Determine which classes to analyze
        $classesToProcess = [];
        if ($specificClass) {
            if (!class_exists($specificClass)) {
                $io->error("Class {$specificClass} not found");
                return Command::FAILURE;
            }

            $classesToProcess[] = $specificClass;
        } else {
            // Get all document classes
            $classesToProcess = $this->mappingDebugger->getAllDocumentClasses();

            if (empty($classesToProcess)) {
                $io->warning('No document classes found - check your mappings configuration');
                return Command::SUCCESS;
            }
        }

        // Process each class
        foreach ($classesToProcess as $className) {
            $io->section("Analyzing {$className}");

            try {
                $metadata = $this->metadataFactory->getMetadataFor($className);
                $stats = $this->bulkOperations->getCollectionStats($className);

                // Display basic statistics
                $io->definitionList(
                    ['Collection' => $stats['collection']],
                    ['Prefix' => $stats['prefix'] ?: '(none)'],
                    ['Document Count' => number_format($stats['document_count'])],
                    ['Storage Type' => $stats['storage_type']],
                    ['Regular Indices' => count($stats['indices'])],
                    ['Sorted Indices' => count($stats['sorted_indices'])]
                );

                // Show index details if requested
                if ($showIndexStats) {
                    $this->displayIndexStats($io, $stats);
                }

                // Show all indices and their size if requested
                if ($showIndices) {
                    $this->displayAllIndices($io, $metadata);
                }

                // Analyze memory usage if requested
                if ($analyzeMemory) {
                    $this->analyzeMemoryUsage($io, $className, $sampleSize);
                }

                // Run benchmarks if requested
                if ($runBenchmarks) {
                    $this->runBenchmarks($io, $className, $sampleSize);
                }

                // Suggest optimizations
                $this->suggestOptimizations($io, $className, $stats);
            } catch (\Exception $e) {
                $io->error("Error analyzing {$className}: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Display index statistics
     */
    private function displayIndexStats(SymfonyStyle $io, array $stats): void
    {
        $io->section('Index Statistics');

        // Regular indices
        if (!empty($stats['indices'])) {
            $io->text('Regular Indices:');
            $tableRows = [];

            foreach ($stats['indices'] as $indexName => $indexInfo) {
                $tableRows[] = [
                    $indexName,
                    $indexInfo['field'],
                    number_format($indexInfo['key_count']),
                    number_format($indexInfo['total_references'])
                ];
            }

            $indexTable = new Table($io);
            $indexTable->setHeaders(['Index Name', 'Field', 'Key Count', 'Total References']);
            $indexTable->setRows($tableRows);
            $indexTable->render();
        } else {
            $io->text('No regular indices defined.');
        }

        // Sorted indices
        if (!empty($stats['sorted_indices'])) {
            $io->text('Sorted Indices:');
            $tableRows = [];

            foreach ($stats['sorted_indices'] as $indexName => $indexInfo) {
                $tableRows[] = [
                    $indexName,
                    $indexInfo['field'],
                    number_format($indexInfo['cardinality'])
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
     * Display all indices and their size
     */
    private function displayAllIndices(SymfonyStyle $io, $metadata): void
    {
        $io->section('Index Details');
        $redisClient = $this->documentManager->getRedisClient();

        // Regular indices
        if (!empty($metadata->indices)) {
            $io->text('Regular Indices (Set Index):');
            $tableRows = [];

            foreach ($metadata->indices as $propertyName => $indexName) {
                $pattern = $metadata->getIndexKeyPattern($indexName);
                $keys = $redisClient->keys($pattern);

                foreach ($keys as $key) {
                    $size = $redisClient->sCard($key);
                    $keyParts = explode(':', $key);
                    $value = end($keyParts);

                    $tableRows[] = [
                        $indexName,
                        $propertyName,
                        $value,
                        number_format($size),
                        $metadata->getIndexTTL($indexName) > 0 ? $metadata->getIndexTTL($indexName) . 's' : 'No TTL'
                    ];
                }
            }

            $indexTable = new Table($io);
            $indexTable->setHeaders(['Index Name', 'Property', 'Value', 'Set Size', 'TTL']);
            $indexTable->setRows($tableRows);
            $indexTable->render();
        }

        // Sorted indices
        if (!empty($metadata->sortedIndices)) {
            $io->text('Sorted Indices (ZSet Index):');
            $tableRows = [];

            foreach ($metadata->sortedIndices as $propertyName => $indexName) {
                $key = $metadata->getSortedIndexKeyName($indexName);
                $size = $redisClient->zCard($key);
                $min = $redisClient->zRange($key, 0, 0, true);
                $max = $redisClient->zRevRange($key, 0, 0, true);

                $minVal = 'N/A';
                $maxVal = 'N/A';

                if (!empty($min)) {
                    $minVal = reset($min);
                }

                if (!empty($max)) {
                    $maxVal = reset($max);
                }

                $tableRows[] = [
                    $indexName,
                    $propertyName,
                    number_format($size),
                    $minVal,
                    $maxVal,
                    $metadata->getSortedIndexTTL($indexName) > 0 ? $metadata->getSortedIndexTTL($indexName) . 's' : 'No TTL'
                ];
            }

            $sortedIndexTable = new Table($io);
            $sortedIndexTable->setHeaders(['Index Name', 'Property', 'Size', 'Min Value', 'Max Value', 'TTL']);
            $sortedIndexTable->setRows($tableRows);
            $sortedIndexTable->render();
        }
    }

    /**
     * Analyze memory usage of documents
     */
    private function analyzeMemoryUsage(SymfonyStyle $io, string $className, int $sampleSize): void
    {
        $io->section('Memory Usage Analysis');

        $repository = $this->documentManager->getRepository($className);
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $redisClient = $this->documentManager->getRedisClient();

        // Get sample documents
        $pattern = $metadata->getCollectionKeyPattern();
        $allKeys = $redisClient->keys($pattern);

        if (empty($allKeys)) {
            $io->warning('No documents found for memory analysis.');
            return;
        }

        $sampleSize = min($sampleSize, count($allKeys));
        $sampleKeys = array_slice($allKeys, 0, $sampleSize);

        $totalSize = 0;
        $fieldSizes = [];
        $sampleCount = 0;

        foreach ($sampleKeys as $key) {
            // Extract ID from key
            $parts = explode(':', $key);
            $id = end($parts);

            // Get document data
            if ($metadata->storageType === 'hash') {
                $data = $redisClient->hGetAll($key);
                $docSize = 0;

                foreach ($data as $field => $value) {
                    $valueSize = strlen($value);
                    $docSize += strlen($field) + $valueSize;

                    // Track field sizes
                    if (!isset($fieldSizes[$field])) {
                        $fieldSizes[$field] = ['total' => 0, 'count' => 0, 'min' => PHP_INT_MAX, 'max' => 0];
                    }

                    $fieldSizes[$field]['total'] += $valueSize;
                    $fieldSizes[$field]['count']++;
                    $fieldSizes[$field]['min'] = min($fieldSizes[$field]['min'], $valueSize);
                    $fieldSizes[$field]['max'] = max($fieldSizes[$field]['max'], $valueSize);
                }

                $totalSize += $docSize;
                $sampleCount++;
            } elseif ($metadata->storageType === 'json') {
                $jsonData = $redisClient->get($key);
                if ($jsonData) {
                    $docSize = strlen($jsonData);
                    $totalSize += $docSize;
                    $sampleCount++;

                    // Parse and analyze JSON structure
                    $data = json_decode($jsonData, true);
                    if (is_array($data)) {
                        foreach ($data as $field => $value) {
                            $valueSize = strlen(json_encode($value));

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

        // Calculate average size
        $avgSize = $sampleCount > 0 ? $totalSize / $sampleCount : 0;
        $totalCount = count($allKeys);
        $estimatedTotalSize = $avgSize * $totalCount;

        // Display memory usage summary
        $io->definitionList(
            ['Sample Size' => $sampleCount],
            ['Average Document Size' => round($avgSize, 2) . ' bytes'],
            ['Estimated Total Collection Size' => $this->formatBytes($estimatedTotalSize)],
            ['Storage Type' => $metadata->storageType]
        );

        // Display field size analysis
        $io->text('Field Size Analysis:');
        $tableRows = [];

        foreach ($fieldSizes as $field => $sizeInfo) {
            $avgFieldSize = $sizeInfo['count'] > 0 ? $sizeInfo['total'] / $sizeInfo['count'] : 0;
            $tableRows[] = [
                $field,
                round($avgFieldSize, 2) . ' bytes',
                $sizeInfo['min'] . ' bytes',
                $sizeInfo['max'] . ' bytes',
                $this->formatBytes($sizeInfo['total'])
            ];
        }

        $fieldSizeTable = new Table($io);
        $fieldSizeTable->setHeaders(['Field', 'Average Size', 'Min Size', 'Max Size', 'Total Size']);
        $fieldSizeTable->setRows($tableRows);
        $fieldSizeTable->render();

        // Check for potential memory optimizations
        $io->text('Potential Memory Optimizations:');
        $largeFields = [];

        foreach ($fieldSizes as $field => $sizeInfo) {
            $avgFieldSize = $sizeInfo['count'] > 0 ? $sizeInfo['total'] / $sizeInfo['count'] : 0;
            if ($avgFieldSize > 100) {
                $largeFields[] = [$field, round($avgFieldSize, 2) . ' bytes'];
            }
        }

        if (empty($largeFields)) {
            $io->text('No obvious memory optimization targets found.');
        } else {
            $io->text('Large fields that could be candidates for optimization:');

            $largeFieldsTable = new Table($io);
            $largeFieldsTable->setHeaders(['Field', 'Average Size']);
            $largeFieldsTable->setRows($largeFields);
            $largeFieldsTable->render();
        }
    }

    /**
     * Run performance benchmarks
     */
    private function runBenchmarks(SymfonyStyle $io, string $className, int $sampleSize): void
    {
        $io->section('Performance Benchmarks');

        $repository = $this->documentManager->getRepository($className);
        $metadata = $this->metadataFactory->getMetadataFor($className);
        $redisClient = $this->documentManager->getRedisClient();

        // Get a list of all document keys
        $pattern = $metadata->getCollectionKeyPattern();
        $allKeys = $redisClient->keys($pattern);

        if (empty($allKeys)) {
            $io->warning('No documents found for benchmarking.');
            return;
        }

        $sampleSize = min($sampleSize, count($allKeys));
        $sampleKeys = array_slice($allKeys, 0, $sampleSize);

        $benchmarks = [];

        // Benchmark 1: Single document retrieval
        $io->text('Benchmarking single document retrieval...');

        $startTime = microtime(true);
        foreach ($sampleKeys as $key) {
            // Extract ID from key
            $parts = explode(':', $key);
            $id = end($parts);

            // Find document
            $document = $repository->find($id);
        }
        $endTime = microtime(true);

        $totalTime = $endTime - $startTime;
        $avgTime = $totalTime / count($sampleKeys);

        $benchmarks[] = [
            'Find by ID',
            number_format($avgTime * 1000, 3) . ' ms',
            number_format(1 / $avgTime, 1) . ' ops/sec'
        ];

        // Benchmark 2: Index lookup for documents with indices
        if (!empty($metadata->indices)) {
            $indexNames = array_values($metadata->indices);
            $indexName = reset($indexNames);
            $propertyName = array_search($indexName, $metadata->indices);

            $io->text("Benchmarking index lookup using {$indexName}...");

            // Get sample values from index
            $indexPattern = $metadata->getIndexKeyPattern($indexName);
            $indexKeys = $redisClient->keys($indexPattern);

            if (!empty($indexKeys)) {
                $sampleIndexKeys = array_slice($indexKeys, 0, min(10, count($indexKeys)));

                $startTime = microtime(true);
                foreach ($sampleIndexKeys as $indexKey) {
                    // Extract value from index key
                    $parts = explode(':', $indexKey);
                    $value = end($parts);

                    // Find documents using index
                    $documents = $repository->findBy([$propertyName => $value]);
                }
                $endTime = microtime(true);

                $totalTime = $endTime - $startTime;
                $avgTime = $totalTime / count($sampleIndexKeys);

                $benchmarks[] = [
                    "Index Lookup ({$indexName})",
                    number_format($avgTime * 1000, 3) . ' ms',
                    number_format(1 / $avgTime, 1) . ' ops/sec'
                ];
            }
        }

        // Benchmark 3: Sorted index range query if available
        if (!empty($metadata->sortedIndices)) {
            $io->text("Benchmarking sorted index range queries...");

            // TODO: Implement range query benchmark here
            // This would use a RangeQuery object to perform range queries
        }

        // Benchmark 4: Batch retrieval performance
        $io->text('Benchmarking batch retrieval...');

        $batchSizes = [10, 50, 100];
        foreach ($batchSizes as $batchSize) {
            if (count($allKeys) >= $batchSize) {
                $batchKeys = array_slice($allKeys, 0, $batchSize);
                $batchIds = array_map(function($key) {
                    $parts = explode(':', $key);
                    return end($parts);
                }, $batchKeys);

                $startTime = microtime(true);
                $batchDocuments = $this->documentManager->findByIds($repository->getDocumentClass(), $batchIds);
                $endTime = microtime(true);

                $totalTime = $endTime - $startTime;

                $benchmarks[] = [
                    "Batch Find ({$batchSize} docs)",
                    number_format($totalTime * 1000, 3) . ' ms',
                    number_format($batchSize / $totalTime, 1) . ' docs/sec'
                ];
            }
        }

        // Display benchmark results
        $benchmarkTable = new Table($io);
        $benchmarkTable->setHeaders(['Operation', 'Average Time', 'Throughput']);
        $benchmarkTable->setRows($benchmarks);
        $benchmarkTable->render();
    }

    /**
     * Suggest optimizations based on the analysis
     */
    private function suggestOptimizations(SymfonyStyle $io, string $className, array $stats): void
    {
        $io->section('Optimization Suggestions');

        $suggestions = [];

        // Check for large collections without indices
        if ($stats['document_count'] > 1000 && empty($stats['indices']) && empty($stats['sorted_indices'])) {
            $suggestions[] = [
                'High Priority',
                'Add indices for fields used in queries',
                'Large collection with no indices can lead to full collection scans'
            ];
        }

        // Check for overuse of indices
        if (count($stats['indices']) > 5) {
            $suggestions[] = [
                'Medium Priority',
                'Review index usage',
                'Many indices can increase memory usage and slow writes'
            ];
        }

        // Check for potential sorted index candidates
        if (!empty($stats['indices']) && empty($stats['sorted_indices'])) {
            $suggestions[] = [
                'Low Priority',
                'Consider using sorted indices for numeric fields',
                'Enables efficient range queries and sorting'
            ];
        }

        // Check collection size
        if ($stats['document_count'] > 10000) {
            $suggestions[] = [
                'Medium Priority',
                'Use batch processing and pagination',
                'Large collections benefit from streaming and pagination'
            ];
        }

        // Check storage type for large collections
        if ($stats['document_count'] > 5000 && $stats['storage_type'] === 'json') {
            $suggestions[] = [
                'Medium Priority',
                'Consider hash storage for large collections',
                'Hash storage often has better memory efficiency for large documents'
            ];
        }

        if (empty($suggestions)) {
            $io->text('No specific optimization suggestions for this collection.');
        } else {
            $suggestionsTable = new Table($io);
            $suggestionsTable->setHeaders(['Priority', 'Suggestion', 'Reason']);
            $suggestionsTable->setRows($suggestions);
            $suggestionsTable->render();
        }
    }

    /**
     * Format bytes into a human-readable string
     */
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