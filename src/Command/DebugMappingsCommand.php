<?php

namespace Phillarmonic\AllegroRedisOdmBundle\Command;

use Phillarmonic\AllegroRedisOdmBundle\Mapping\MetadataFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'allegro:debug-mappings',
    description: 'Debug Redis ODM document mappings'
)]
class DebugMappingsCommand extends Command
{
    public function __construct(
        private readonly MetadataFactory $metadataFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Redis ODM Document Mappings Debug');

        // Display configured mappings
        $io->section('Configured Mappings:');
        $mappings = $this->metadataFactory->getMappings();

        if (empty($mappings)) {
            $io->warning('No mappings configured!');
            return Command::FAILURE;
        }

        foreach ($mappings as $name => $mapping) {
            $io->definitionList(
                ['Mapping' => $name],
                ['Namespace' => $mapping['namespace']],
                ['Directory' => $mapping['dir']],
                ['Directory exists' => is_dir($mapping['dir']) ? 'Yes' : 'No'],
                ['Type' => $mapping['type'] ?? 'attribute']
            );

            // Check if mapping directory exists and is readable
            if (!is_dir($mapping['dir'])) {
                $io->warning(sprintf(
                    'Mapping directory "%s" does not exist or is not accessible.',
                    $mapping['dir']
                ));
            }
        }

        // Try to find document classes
        $io->section('Discovered Document Classes:');
        $classCount = 0;

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

        if ($classCount > 0) {
            return Command::SUCCESS;
        } else {
            return Command::FAILURE;
        }
    }
}