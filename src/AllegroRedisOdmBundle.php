<?php

namespace Phillarmonic\AllegroRedisOdmBundle;


use Phillarmonic\AllegroRedisOdmBundle\DependencyInjection\AllegroRedisOdmExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class AllegroRedisOdmBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // Register compiler passes if needed
    }

    public function getContainerExtension(): ?\Symfony\Component\DependencyInjection\Extension\ExtensionInterface
    {
        return new AllegroRedisOdmExtension();
    }
}