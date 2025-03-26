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
            error_log('RegisterDocumentMappingsPass: No metadata_factory service found');
            return;
        }

        // Get the mappings parameter
        if (!$container->hasParameter('allegro_redis_odm.mappings')) {
            error_log('RegisterDocumentMappingsPass: No allegro_redis_odm.mappings parameter found');
            return;
        }

        $mappings = $container->getParameter('allegro_redis_odm.mappings');
        error_log('RegisterDocumentMappingsPass: Raw mappings: ' . print_r($mappings, true));

        if (empty($mappings)) {
            error_log('RegisterDocumentMappingsPass: No document mappings configured');
            return;
        }

        error_log(sprintf('RegisterDocumentMappingsPass: Processing %d document mappings', count($mappings)));

        // Process and validate each mapping
        $processedMappings = [];
        foreach ($mappings as $name => $mapping) {
            if (!isset($mapping['dir'], $mapping['namespace'])) {
                error_log(sprintf('RegisterDocumentMappingsPass: Skipping mapping "%s" - missing dir or namespace', $name));
                continue;
            }

            // Resolve path if it's relative
            $dir = $mapping['dir'];
            $dirStatus = 'Exists';

            if (!file_exists($dir) || !is_dir($dir)) {
                $dirStatus = 'Not found';
                // Try to resolve relative to project root
                $projectDir = $container->getParameter('kernel.project_dir');
                $resolvedDir = $projectDir . '/' . $dir;

                if (is_dir($resolvedDir)) {
                    $dir = $resolvedDir;
                    $mapping['dir'] = $dir;
                    $dirStatus = 'Resolved to ' . $dir;
                    error_log(sprintf('RegisterDocumentMappingsPass: Resolved relative path for mapping "%s" to "%s"', $name, $dir));
                } else {
                    error_log(sprintf('RegisterDocumentMappingsPass: Mapping directory "%s" for "%s" does not exist', $dir, $name));
                    // Include it anyway, but mark as potentially problematic
                    $dirStatus = 'Not found, even after resolution attempt';
                }
            }

            // Store the processed mapping
            $processedMappings[$name] = $mapping;
            error_log(sprintf('RegisterDocumentMappingsPass: Registered mapping "%s": namespace="%s", dir="%s", status="%s"',
                $name, $mapping['namespace'], $mapping['dir'], $dirStatus));
        }

        // Update the mappings parameter with processed mappings
        $container->setParameter('allegro_redis_odm.mappings', $processedMappings);
        error_log('RegisterDocumentMappingsPass: Updated parameter allegro_redis_odm.mappings with: ' . print_r($processedMappings, true));

        // We no longer set the constructor argument directly since we're using the parameter bag
        error_log('RegisterDocumentMappingsPass: Completed with ' . count($processedMappings) . ' mappings');
    }
}