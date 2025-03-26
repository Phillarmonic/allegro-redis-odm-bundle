<?php

namespace Phillarmonic\AllegroRedisOdmBundle\DependencyInjection;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;

class AllegroRedisOdmExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
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

        // Store default storage configuration
        if (isset($config['default_storage'])) {
            $container->setParameter('allegro_redis_odm.default_storage', $config['default_storage']);
        }

        // Configure Redis client service based on client type
        $this->configureRedisClient($container, $config);

        // Configure mapping paths - ensure this is set even if empty
        if (isset($config['mappings']) && !empty($config['mappings'])) {
            // Process mappings to resolve paths
            $mappings = $this->processMappingsConfiguration($container, $config['mappings']);
            $container->setParameter('allegro_redis_odm.mappings', $mappings);
        } else {
            $container->setParameter('allegro_redis_odm.mappings', []);
        }
    }

    /**
     * Process mappings configuration to resolve paths and validate
     */
    private function processMappingsConfiguration(ContainerBuilder $container, array $mappings): array
    {
        $processedMappings = [];
        $projectDir = $container->getParameter('kernel.project_dir');

        foreach ($mappings as $name => $mapping) {
            if (!isset($mapping['dir']) || !isset($mapping['namespace'])) {
                continue;
            }

            // Resolve directory path if it's relative
            $dir = $mapping['dir'];
            if (!file_exists($dir) || !is_dir($dir)) {
                // Try to resolve as relative to project root
                $resolvedDir = $projectDir . '/' . $dir;
                if (is_dir($resolvedDir)) {
                    $mapping['dir'] = $resolvedDir;
                }
            }

            $processedMappings[$name] = $mapping;
        }

        return $processedMappings;
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

            // For phpredis, we need to use different methods depending on the scheme
            $isTls = $connection['scheme'] === 'rediss';

            if (!empty($connection['persistent'])) {
                // Configure pconnect options
                $connectMethod = $isTls ? 'pconnect' : 'pconnect';

                if ($isTls) {
                    // SSL connection options for persistent connection
                    $clientDef->addMethodCall($connectMethod, [
                        $connection['host'],
                        $connection['port'],
                        0.0, // default timeout
                        null, // persistent_id
                        0, // retry_interval
                        ['verify_peer' => true, 'verify_peer_name' => true] // TLS options
                    ]);
                } else {
                    // Standard connection
                    $clientDef->addMethodCall($connectMethod, [
                        $connection['host'],
                        $connection['port']
                    ]);
                }
            } else {
                // Configure connect options
                $connectMethod = $isTls ? 'connect' : 'connect';

                if ($isTls) {
                    // SSL connection options for non-persistent connection
                    $clientDef->addMethodCall($connectMethod, [
                        $connection['host'],
                        $connection['port'],
                        0.0, // default timeout
                        null, // persistent_id
                        0, // retry_interval
                        ['verify_peer' => true, 'verify_peer_name' => true] // TLS options
                    ]);
                } else {
                    // Standard connection
                    $clientDef->addMethodCall($connectMethod, [
                        $connection['host'],
                        $connection['port']
                    ]);
                }
            }

            if (!empty($connection['auth'])) {
                $clientDef->addMethodCall('auth', [$connection['auth']]);
            }

            if (isset($connection['database'])) {
                $clientDef->addMethodCall('select', [$connection['database']]);
            }

            if (!empty($connection['read_timeout'])) {
                $clientDef->addMethodCall('setOption', [\Redis::OPT_READ_TIMEOUT, $connection['read_timeout']]);
            }

            $container->setDefinition('allegro_redis_odm.client', $clientDef);
        } elseif ($clientType === 'predis') {
            $clientDef = new Definition('Predis\Client');

            $parameters = [
                'scheme' => $connection['scheme'], // Use the configured scheme directly
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

            // If using SSL/TLS, add additional configuration options
            if ($connection['scheme'] === 'rediss') {
                $options['ssl'] = [
                    'verify_peer' => true,
                    'verify_peer_name' => true
                ];
            }

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