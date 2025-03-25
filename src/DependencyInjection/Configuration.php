<?php

namespace Phillarmonic\AllegroRedisOdmBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('allegro_redis_odm');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->enumNode('client_type')
                    ->values(['phpredis', 'predis'])
                    ->defaultValue('phpredis')
                    ->info('The Redis client implementation to use (phpredis or predis)')
                ->end()
                ->arrayNode('connection')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('host')->defaultValue('127.0.0.1')->end()
                        ->integerNode('port')->defaultValue(6379)->end()
                        ->integerNode('database')->defaultValue(0)->end()
                        ->scalarNode('auth')->defaultNull()->end()
                        ->floatNode('read_timeout')->defaultValue(0)->end()
                        ->booleanNode('persistent')->defaultFalse()->end()
                        ->arrayNode('options')
                            ->prototype('scalar')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('mappings')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('type')->defaultValue('attribute')->end()
                            ->scalarNode('dir')->isRequired()->end()
                            ->scalarNode('prefix')->defaultValue('')->end()
                            ->scalarNode('namespace')->isRequired()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('default_storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('type')
                            ->values(['hash', 'json'])
                            ->defaultValue('hash')
                            ->info('Default storage type (hash or json)')
                        ->end()
                        ->integerNode('ttl')
                            ->defaultValue(0)
                            ->info('Default TTL for documents (0 = no expiration)')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}