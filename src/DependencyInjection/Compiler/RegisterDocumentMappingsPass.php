<?php

namespace Phillarmonic\AllegroRedisOdmBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class RegisterDocumentMappingsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Check if metadata factory service exists
        if (!$container->hasDefinition('allegro_redis_odm.metadata_factory')) {
            return;
        }

        // Get the mappings parameter
        if (!$container->hasParameter('allegro_redis_odm.mappings')) {
            $container->log($this, 'No allegro_redis_odm.mappings parameter found');
            return;
        }

        $mappings = $container->getParameter('allegro_redis_odm.mappings');

        if (empty($mappings)) {
            $container->log($this, 'No document mappings configured');
            return;
        }

        $container->log($this, sprintf('Processing %d document mappings', count($mappings)));

        // Process and validate each mapping
        $processedMappings = [];
        foreach ($mappings as $name => $mapping) {
            if (!isset($mapping['dir'], $mapping['namespace'])) {
                $container->log($this, sprintf('Skipping mapping "%s" - missing dir or namespace', $name));
                continue;
            }

            // Resolve path if it's relative
            $dir = $mapping['dir'];
            if (!file_exists($dir) && !is_dir($dir)) {
                // Try to resolve relative to project root
                $projectDir = $container->getParameter('kernel.project_dir');
                $resolvedDir = $projectDir . '/' . $dir;

                if (is_dir($resolvedDir)) {
                    $dir = $resolvedDir;
                    $mapping['dir'] = $dir;
                    $container->log($this, sprintf('Resolved relative path for mapping "%s" to "%s"', $name, $dir));
                } else {
                    $container->log($this, sprintf('Mapping directory "%s" for "%s" does not exist', $dir, $name));
                    continue;
                }
            }

            // Store the processed mapping
            $processedMappings[$name] = $mapping;
            $container->log($this, sprintf('Registered mapping "%s": namespace="%s", dir="%s"',
                $name, $mapping['namespace'], $mapping['dir']));
        }

        // Update the mappings parameter with processed mappings
        $container->setParameter('allegro_redis_odm.mappings', $processedMappings);

        // Ensure the metadata factory service has the mappings
        $metadataFactoryDefinition = $container->getDefinition('allegro_redis_odm.metadata_factory');
        $metadataFactoryDefinition->setArgument(0, $processedMappings);

        $container->log($this, 'Metadata factory configured with ' . count($processedMappings) . ' mappings');
    }
}