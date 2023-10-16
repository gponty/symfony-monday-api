<?php declare(strict_types=1);

namespace Gponty\MondayBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class MondayBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.yml');

        $container->services()
            ->get(MondayApi::class)
            ->arg('$mondayApiKey', $config['api_key'])
            ->arg('$mondayApiVersion', $config['api_version'])
        ;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        // if the configuration is short, consider adding it in this class
        $definition->rootNode()
            ->children()
            ->scalarNode('api_key')->defaultValue('%env(MONDAY_API_KEY)%')->end()
            ->scalarNode('api_version')->defaultValue('%env(MONDAY_API_VERSION)%')->end()
            ->end()
        ;
    }
}
