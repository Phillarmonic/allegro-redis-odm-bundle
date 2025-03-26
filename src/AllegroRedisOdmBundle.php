<?php

namespace Phillarmonic\AllegroRedisOdmBundle;

use Phillarmonic\AllegroRedisOdmBundle\DependencyInjection\AllegroRedisOdmExtension;
use Phillarmonic\AllegroRedisOdmBundle\DependencyInjection\Compiler\RegisterDocumentMappingsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class AllegroRedisOdmBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Register the compiler pass to process document mappings
        $container->addCompilerPass(new RegisterDocumentMappingsPass());
    }

    public function getContainerExtension(): ?\Symfony\Component\DependencyInjection\Extension\ExtensionInterface
    {
        return new AllegroRedisOdmExtension();
    }
}