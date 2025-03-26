<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Command;

use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Document;

#[AsCommand(
    name: 'allegro:debug-mappings',
    description: 'Debug Redis ODM document mappings'
)]
class DebugMappingsCommand extends Command
{
    public function __construct(
        private readonly MetadataFactory $metadataFactory,
        private readonly ContainerInterface $container
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Show more detailed information')
            ->addOption('scan-directories', 's', InputOption::VALUE_NONE, 'Explicitly scan mapping directories for PHP files')
            ->addOption('test-class', 't', InputOption::VALUE_REQUIRED, 'Test a specific class for document attributes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Redis ODM Document Mappings Debug');

        // Show container parameters first
        $io->section('Container Parameters:');
        $hasParameter = $this->container->hasParameter('allegro_redis_odm.mappings');
        $io->text('Parameter allegro_redis_odm.mappings exists: ' . ($hasParameter ? 'Yes' : 'No'));

        if ($hasParameter) {
            $parameterValue = $this->container->getParameter('allegro_redis_odm.mappings');
            $io->text('Parameter value type: ' . gettype($parameterValue));
            $io->text('Parameter is empty: ' . (empty($parameterValue) ? 'Yes' : 'No'));

            if (!empty($parameterValue)) {
                $io->text('Parameter contains ' . count($parameterValue) . ' mappings');

                if ($input->getOption('verbose')) {
                    $io->section('Parameter Value:');
                    $io->text(print_r($parameterValue, true));
                }
            }
        }

        // Display configured mappings from the factory
        $io->section('Metadata Factory Mappings:');
        $mappings = $this->metadataFactory->getMappings();

        if (empty($mappings)) {
            $io->warning('No mappings configured in the MetadataFactory!');
        } else {
            foreach ($mappings as $name => $mapping) {
                $io->definitionList(
                    ['Mapping' => $name],
                    ['Namespace' => $mapping['namespace']],
                    ['Directory' => $mapping['dir']],
                    ['Directory exists' => is_dir($mapping['dir']) ? 'Yes' : 'No'],
                    ['Type' => $mapping['type'] ?? 'attribute'],
                    ['Prefix' => $mapping['prefix'] ?? '(none)']
                );

                // If directory doesn't exist, check for common issues
                if (!is_dir($mapping['dir'])) {
                    $io->error("Directory doesn't exist: {$mapping['dir']}");
                    $this->checkPotentialDirectoryIssues($mapping['dir'], $io);
                } else if ($input->getOption('verbose')) {
                    // If verbose and directory exists, scan it
                    $io->text('Directory contents:');
                    $this->listDirectoryContents($mapping['dir'], $io);
                }
            }
        }

        // If scan-directories option is provided, manually check for PHP files
        if ($input->getOption('scan-directories') && !empty($mappings)) {
            $io->section('Manual Directory Scan:');

            foreach ($mappings as $name => $mapping) {
                $dir = $mapping['dir'];
                $namespace = $mapping['namespace'];

                if (!is_dir($dir)) {
                    $io->warning("Directory {$dir} does not exist, skipping scan.");
                    continue;
                }

                $io->text("Scanning {$dir} for PHP files...");
                $phpFiles = $this->scanForPhpFiles($dir);

                if (empty($phpFiles)) {
                    $io->text("No PHP files found in {$dir}");
                } else {
                    $io->text("Found " . count($phpFiles) . " PHP files:");
                    $io->listing($phpFiles);

                    // Try to map files to class names
                    $io->text("Potential class names:");
                    $potentialClasses = [];

                    foreach ($phpFiles as $file) {
                        $relativePath = substr($file, strlen($dir) + 1);
                        $relativePathWithoutExt = substr($relativePath, 0, -4); // Remove .php
                        $classPath = str_replace('/', '\\', $relativePathWithoutExt);
                        $fullyQualifiedClassName = $namespace . '\\' . $classPath;

                        $exists = class_exists($fullyQualifiedClassName);
                        $potentialClasses[] = $fullyQualifiedClassName . ' (Class ' . ($exists ? 'exists' : 'DOES NOT exist') . ')';

                        if ($exists) {
                            // Check if it has document attributes
                            $isDocument = $this->metadataFactory->isDocument($fullyQualifiedClassName);
                            $io->text("  • Has Document attribute: " . ($isDocument ? 'Yes' : 'No'));

                            if (!$isDocument) {
                                $this->inspectClass($fullyQualifiedClassName, $io);
                            }
                        }
                    }

                    $io->listing($potentialClasses);
                }
            }
        }

        // Test a specific class if provided
        if ($testClass = $input->getOption('test-class')) {
            $io->section("Testing Class: {$testClass}");

            if (!class_exists($testClass)) {
                $io->error("Class {$testClass} does not exist or cannot be autoloaded");
            } else {
                $io->success("Class {$testClass} exists");
                $isDocument = $this->metadataFactory->isDocument($testClass);
                $io->text("Has Document attribute: " . ($isDocument ? 'Yes' : 'No'));

                if (!$isDocument) {
                    $this->inspectClass($testClass, $io);
                } else {
                    try {
                        $metadata = $this->metadataFactory->getMetadataFor($testClass);
                        $io->text("Successfully loaded metadata:");
                        $io->text("Collection: " . $metadata->collection);
                        $io->text("Storage type: " . $metadata->storageType);
                        $io->text("Fields: " . count($metadata->fields));
                        $io->text("Indices: " . count($metadata->indices));
                    } catch (\Exception $e) {
                        $io->error("Error loading metadata: " . $e->getMessage());
                    }
                }
            }
        }

        // Try to find document classes
        $io->section('Discovered Document Classes:');

        try {
            $documentClasses = $this->metadataFactory->getAllDocumentClasses();

            if (empty($documentClasses)) {
                $io->warning('No document classes were found in the configured mappings.');
            } else {
                $classCount = count($documentClasses);
                $io->success(sprintf('Found %d document classes.', $classCount));

                $tableRows = [];
                foreach ($documentClasses as $className) {
                    $metadata = null;
                    $storageType = 'Unknown';
                    $collection = 'Unknown';
                    $fields = 0;
                    $indices = 0;

                    try {
                        $metadata = $this->metadataFactory->getMetadataFor($className);
                        $storageType = $metadata->storageType;
                        $collection = $metadata->collection;
                        $fields = count($metadata->fields);
                        $indices = count($metadata->indices);
                    } catch (\Exception $e) {
                        $storageType = 'ERROR: ' . $e->getMessage();
                    }

                    $tableRows[] = [
                        $className,
                        $storageType,
                        $collection,
                        $fields,
                        $indices
                    ];
                }

                $io->table(
                    ['Class', 'Storage Type', 'Collection', 'Fields', 'Indices'],
                    $tableRows
                );
            }
        } catch (\Exception $e) {
            $io->error('Error discovering document classes: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text('Trace: ' . $e->getTraceAsString());
            }
            return Command::FAILURE;
        }

        // Autoloader info
        $io->section('Autoloading Information:');
        $io->text('Registered autoloaders:');

        $autoloaders = spl_autoload_functions();
        $autoloaderInfo = [];

        foreach ($autoloaders as $index => $autoloader) {
            if (is_array($autoloader) && count($autoloader) >= 2) {
                if (is_object($autoloader[0])) {
                    $autoloaderInfo[] = [
                        $index,
                        get_class($autoloader[0]) . '->' . $autoloader[1]
                    ];
                } else {
                    $autoloaderInfo[] = [
                        $index,
                        (string)$autoloader[0] . '::' . $autoloader[1]
                    ];
                }
            } else {
                $autoloaderInfo[] = [
                    $index,
                    is_callable($autoloader) ? 'Closure' : 'Unknown'
                ];
            }
        }

        $io->table(['#', 'Autoloader'], $autoloaderInfo);

        // Get Symfony information
        $io->section('Symfony Environment Information:');
        $io->text('Environment: ' . $this->container->getParameter('kernel.environment'));
        $io->text('Debug mode: ' . ($this->container->getParameter('kernel.debug') ? 'Yes' : 'No'));
        $io->text('Project directory: ' . $this->container->getParameter('kernel.project_dir'));

        if (empty($documentClasses)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * List the contents of a directory, showing only PHP files
     */
    private function listDirectoryContents(string $dir, SymfonyStyle $io): void
    {
        $files = $this->scanForPhpFiles($dir);

        if (empty($files)) {
            $io->text("  No PHP files found in {$dir}");
        } else {
            foreach ($files as $file) {
                $io->text("  - " . str_replace($dir . '/', '', $file));
            }
        }
    }

    /**
     * Scan directory recursively for PHP files
     */
    private function scanForPhpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $phpFiles = [];
        $directoryIterator = new \RecursiveDirectoryIterator(
            $dir,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );

        $iterator = new \RecursiveIteratorIterator(
            $directoryIterator,
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $phpFiles[] = $file->getRealPath();
            }
        }

        return $phpFiles;
    }

    /**
     * Check for potential issues with directory paths
     */
    private function checkPotentialDirectoryIssues(string $dir, SymfonyStyle $io): void
    {
        // Check if the directory exists relative to project root
        $projectDir = $this->container->getParameter('kernel.project_dir');
        $relativePath = str_replace($projectDir, '', $dir);
        $relativePath = ltrim($relativePath, '/\\');

        $io->text("Checking for '{$relativePath}' relative to project root:");

        if (is_dir($projectDir . '/' . $relativePath)) {
            $io->warning("Directory exists when treating as relative to project root: {$projectDir}/{$relativePath}");
            $io->text("This suggests the path is being used as relative but was configured as absolute");
        }

        // Check permissions
        $parentDir = dirname($dir);
        if (is_dir($parentDir)) {
            $io->text("Parent directory exists: {$parentDir}");
            $io->text("Parent directory readable: " . (is_readable($parentDir) ? 'Yes' : 'No'));
        } else {
            $io->text("Parent directory doesn't exist: {$parentDir}");
        }
    }

    /**
     * Inspect a class for Document attributes
     */
    private function inspectClass(string $className, SymfonyStyle $io): void
    {
        try {
            $reflClass = new \ReflectionClass($className);

            // Check for Document attribute
            $attributes = $reflClass->getAttributes(Document::class);
            $io->text("Class has " . count($attributes) . " Document attributes");

            if (empty($attributes)) {
                $allAttributes = $reflClass->getAttributes();
                if (!empty($allAttributes)) {
                    $io->text("Class has these attributes:");
                    foreach ($allAttributes as $attr) {
                        $io->text("  - " . $attr->getName());
                    }
                } else {
                    $io->text("Class has no attributes at all");
                }

                // Check for use statements
                $fileName = $reflClass->getFileName();
                if ($fileName) {
                    $io->text("Checking use statements in {$fileName}");
                    $source = file_get_contents($fileName);
                    preg_match_all('/^use\s+([^;]+);/m', $source, $matches);

                    if (!empty($matches[1])) {
                        $io->text("Use statements found:");
                        foreach ($matches[1] as $useStatement) {
                            $io->text("  - " . $useStatement);
                        }
                    } else {
                        $io->text("No use statements found");
                    }
                }
            }
        } catch (\ReflectionException $e) {
            $io->error("Error reflecting class: " . $e->getMessage());
        }
    }

    /**
     * Check PHP configuration
     */
    private function checkPhpConfiguration(SymfonyStyle $io): void
    {
        $io->section('PHP Configuration:');
        $io->text('PHP Version: ' . PHP_VERSION);
        $io->text('Loaded Extensions: ');
        $io->listing(get_loaded_extensions());

        // Check PHP include path
        $io->text('Include Path: ' . get_include_path());

        // Check current working directory
        $io->text('Current Working Directory: ' . getcwd());

        // Check open_basedir restrictions if applicable
        $openBasedir = ini_get('open_basedir');
        if (!empty($openBasedir)) {
            $io->text('open_basedir restrictions: ' . $openBasedir);
        }
    }

}