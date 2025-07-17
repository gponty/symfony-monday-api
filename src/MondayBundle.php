<?php declare(strict_types=1);

namespace Gponty\MondayBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class MondayBundle extends AbstractBundle
{
    /**
     * Configure the bundle's service container.
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Add your service definitions or parameters here if needed
    }

    /**
     * Define configuration structure (if needed).
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        // Define configuration tree if needed
    }
}
