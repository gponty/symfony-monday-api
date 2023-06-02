<?php

namespace Gponty\MondayBundle;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;

class MondayBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void
    {
        dump($config);
        dump('extension monday loaded');

        $containerConfigurator->import('../config/services.yml');

        $containerConfigurator->services()
            ->get(MondayApi::class)
            ->arg('$mondayApiKey', $config['api_key'])
        ;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        // if the configuration is short, consider adding it in this class
        $definition->rootNode()
            ->children()
            ->scalarNode('api_key')->defaultValue('%env(MONDAY_API_KEY)%')->end()
            ->end()
        ;
    }

}