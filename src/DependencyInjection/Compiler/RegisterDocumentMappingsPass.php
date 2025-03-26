<?php

namespace Phillarmonic\AllegroRedisOdmBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class RegisterDocumentMappingsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Check if metadata factory service exists
        if (!$container->hasDefinition('allegro_redis_odm.metadata_factory')) {
            return;
        }

        // Get the mappings parameter
        $mappings = $container->getParameter('allegro_redis_odm.mappings');

        if (empty($mappings)) {
            $container->log($this, 'No document mappings configured');
            return;
        }

        $container->log($this, sprintf('Processing %d document mappings', count($mappings)));

        // Process each mapping
        foreach ($mappings as $name => $mapping) {
            if (!isset($mapping['dir'], $mapping['namespace'])) {
                $container->log($this, sprintf('Skipping mapping "%s" - missing dir or namespace', $name));
                continue;
            }

            // Validate the mapping directory exists
            if (!is_dir($mapping['dir'])) {
                $container->log($this, sprintf('Mapping directory "%s" for "%s" does not exist', $mapping['dir'], $name));
                continue;
            }

            $container->log($this, sprintf('Registered mapping "%s": namespace="%s", dir="%s"',
                $name, $mapping['namespace'], $mapping['dir']));
        }

        // Ensure the metadata factory service has the mappings
        $metadataFactoryDefinition = $container->getDefinition('allegro_redis_odm.metadata_factory');
        $metadataFactoryDefinition->setArgument(0, $mappings);

        // Force metadata factory initialization during container build
        $container->log($this, 'Metadata factory configured with mappings');
    }
}