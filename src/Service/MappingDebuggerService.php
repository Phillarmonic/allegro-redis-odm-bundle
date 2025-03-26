<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Service;

use Phillarmonic\AllegroRedisOdmBundle\Mapping\Document;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for debugging document mappings and discovering document classes
 */
class MappingDebuggerService
{
    public function __construct(
        private readonly MetadataFactory $metadataFactory,
        private readonly ContainerInterface $container
    ) {
    }

    /**
     * Get all document classes from configured mappings
     *
     * @return array Array of fully qualified class names that are documents
     */
    public function getAllDocumentClasses(): array
    {
        return $this->metadataFactory->getAllDocumentClasses();
    }

    /**
     * Display all configured mappings information
     */
    public function displayMappingsInfo(SymfonyStyle $io): void
    {
        $io->section('Configured Mappings:');

        // Check if mappings parameter exists
        $hasParameter = $this->container->hasParameter('allegro_redis_odm.mappings');
        $io->text('Parameter allegro_redis_odm.mappings exists: ' . ($hasParameter ? 'Yes' : 'No'));

        if (!$hasParameter) {
            $io->warning('No mappings parameter found in container!');
            return;
        }

        // Get mappings from metadata factory
        $mappings = $this->metadataFactory->getMappings();

        if (empty($mappings)) {
            $io->warning('No mappings configured in the MetadataFactory!');
            return;
        }

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
            }
        }
    }

    /**
     * Scan for PHP files in a directory recursively
     */
    public function scanForPhpFiles(string $dir): array
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
     * List the contents of a directory, showing only PHP files
     */
    public function listDirectoryContents(string $dir, SymfonyStyle $io): void
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
     * Check for potential issues with directory paths
     */
    public function checkPotentialDirectoryIssues(string $dir, SymfonyStyle $io): void
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
     * Test if a specific class is a document and inspect it
     */
    public function inspectClass(string $className, SymfonyStyle $io): void
    {
        if (!class_exists($className)) {
            $io->error("Class {$className} does not exist or cannot be autoloaded");
            return;
        }

        $io->success("Class {$className} exists");
        $isDocument = $this->metadataFactory->isDocument($className);
        $io->text("Has Document attribute: " . ($isDocument ? 'Yes' : 'No'));

        if (!$isDocument) {
            $this->displayClassAttributes($className, $io);
        } else {
            try {
                $metadata = $this->metadataFactory->getMetadataFor($className);
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

    /**
     * Display all discovered document classes with their metadata
     */
    public function displayDocumentClasses(SymfonyStyle $io): void
    {
        $io->section('Discovered Document Classes:');

        try {
            $documentClasses = $this->getAllDocumentClasses();

            if (empty($documentClasses)) {
                $io->warning('No document classes were found in the configured mappings.');
                return;
            }

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
        } catch (\Exception $e) {
            $io->error('Error discovering document classes: ' . $e->getMessage());
        }
    }

    /**
     * Inspect a class for Document attributes
     */
    private function displayClassAttributes(string $className, SymfonyStyle $io): void
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
     * Display PHP environment information
     */
    public function displayPhpInfo(SymfonyStyle $io): void
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

    /**
     * Display autoloader information
     */
    public function displayAutoloaderInfo(SymfonyStyle $io): void
    {
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
    }

    /**
     * Display Symfony environment information
     */
    public function displaySymfonyInfo(SymfonyStyle $io): void
    {
        $io->section('Symfony Environment Information:');
        $io->text('Environment: ' . $this->container->getParameter('kernel.environment'));
        $io->text('Debug mode: ' . ($this->container->getParameter('kernel.debug') ? 'Yes' : 'No'));
        $io->text('Project directory: ' . $this->container->getParameter('kernel.project_dir'));
    }
}