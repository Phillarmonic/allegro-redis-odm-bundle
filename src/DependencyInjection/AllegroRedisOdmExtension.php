<?php

namespace Phillarmonic\AllegroRedisOdmBundle\DependencyInjection;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;

class AllegroRedisOdmExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );
        $loader->load('services.yaml');

        // Store configuration
        $container->setParameter('allegro_redis_odm.connection', $config['connection']);
        $container->setParameter('allegro_redis_odm.client_type', $config['client_type']);

        // Configure Redis client service based on client type
        $this->configureRedisClient($container, $config);

        // Configure mapping paths
        if (isset($config['mappings'])) {
            $container->setParameter('allegro_redis_odm.mappings', $config['mappings']);
        } else {
            $container->setParameter('allegro_redis_odm.mappings', []);
        }
    }

    private function configureRedisClient(ContainerBuilder $container, array $config)
    {
        $clientType = $config['client_type'];
        $connection = $config['connection'];

        // Remove the predefined service
        if ($container->hasDefinition('allegro_redis_odm.client')) {
            $container->removeDefinition('allegro_redis_odm.client');
        }

        // Create the appropriate client based on configuration
        if ($clientType === 'phpredis') {
            $clientDef = new Definition(\Redis::class);
            $clientDef->addMethodCall('connect', [$connection['host'], $connection['port']]);

            if (!empty($connection['auth'])) {
                $clientDef->addMethodCall('auth', [$connection['auth']]);
            }

            if (isset($connection['database'])) {
                $clientDef->addMethodCall('select', [$connection['database']]);
            }

            if (!empty($connection['read_timeout'])) {
                $clientDef->addMethodCall('setOption', [\Redis::OPT_READ_TIMEOUT, $connection['read_timeout']]);
            }

            if (!empty($connection['persistent'])) {
                $clientDef->addMethodCall('pconnect', [$connection['host'], $connection['port']]);
            }

            $container->setDefinition('allegro_redis_odm.client', $clientDef);
        } elseif ($clientType === 'predis') {
            $clientDef = new Definition('Predis\Client');

            $parameters = [
                'scheme' => 'tcp',
                'host' => $connection['host'],
                'port' => $connection['port']
            ];

            if (!empty($connection['auth'])) {
                $parameters['password'] = $connection['auth'];
            }

            if (isset($connection['database'])) {
                $parameters['database'] = $connection['database'];
            }

            if (!empty($connection['read_timeout'])) {
                $parameters['read_write_timeout'] = $connection['read_timeout'];
            }

            $options = [];

            $clientDef->setArguments([$parameters, $options]);
            $container->setDefinition('allegro_redis_odm.client', $clientDef);
        } else {
            throw new \InvalidArgumentException(sprintf('Unsupported Redis client type: %s', $clientType));
        }

        // Create client adapter that normalizes Redis client interfaces
        $container->register('allegro_redis_odm.client_adapter', 'Phillarmonic\\AllegroRedisOdmBundle\\Client\\RedisClientAdapter')
            ->addArgument(new Reference('allegro_redis_odm.client'))
            ->addArgument($clientType);
    }
}