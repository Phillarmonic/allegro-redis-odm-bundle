<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Command;

use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use Phillarmonic\AllegroRedisOdmBundle\Service\MappingDebuggerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'allegro:debug-mappings',
    description: 'Debug Redis ODM document mappings'
)]
class DebugMappingsCommand extends Command
{
    public function __construct(
        private readonly MappingDebuggerService $mappingDebugger,
        private readonly MetadataFactory $metadataFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('scan-directories', 's', InputOption::VALUE_NONE, 'Explicitly scan mapping directories for PHP files')
            ->addOption('test-class', 't', InputOption::VALUE_REQUIRED, 'Test a specific class for document attributes')
            ->addOption('show-php-info', 'p', InputOption::VALUE_NONE, 'Show PHP environment information')
            ->addOption('show-autoloader-info', 'a', InputOption::VALUE_NONE, 'Show autoloader information');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Redis ODM Document Mappings Debug');

        // Display mappings information
        $this->mappingDebugger->displayMappingsInfo($io);

        // If scan-directories option is provided, manually check for PHP files
        if ($input->getOption('scan-directories')) {
            $io->section('Manual Directory Scan:');
            $mappings = $this->metadataFactory->getMappings();

            foreach ($mappings as $name => $mapping) {
                $dir = $mapping['dir'];
                $namespace = $mapping['namespace'];

                if (!is_dir($dir)) {
                    $io->warning("Directory {$dir} does not exist, skipping scan.");
                    continue;
                }

                $io->text("Scanning {$dir} for PHP files...");
                $phpFiles = $this->mappingDebugger->scanForPhpFiles($dir);

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
                        }
                    }

                    $io->listing($potentialClasses);
                }
            }
        }

        // Test a specific class if provided
        if ($testClass = $input->getOption('test-class')) {
            $io->section("Testing Class: {$testClass}");
            $this->mappingDebugger->inspectClass($testClass, $io);
        }

        // Display discovered document classes
        $this->mappingDebugger->displayDocumentClasses($io);

        // Optional PHP info
        if ($input->getOption('show-php-info')) {
            $this->mappingDebugger->displayPhpInfo($io);
        }

        // Optional autoloader info
        if ($input->getOption('show-autoloader-info')) {
            $this->mappingDebugger->displayAutoloaderInfo($io);
        }

        // Always show Symfony environment info
        $this->mappingDebugger->displaySymfonyInfo($io);

        // Get all document classes to check if we found any
        $documentClasses = $this->mappingDebugger->getAllDocumentClasses();

        return empty($documentClasses) ? Command::FAILURE : Command::SUCCESS;
    }
}