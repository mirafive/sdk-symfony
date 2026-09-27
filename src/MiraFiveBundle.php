<?php

declare(strict_types=1);

namespace MiraFive\Symfony;

use LogicException;
use MiraFive\Flags\MiraFlags;
use MiraFive\Http\Transport;
use MiraFive\Mira;
use MiraFive\Symfony\Command\CheckCommand;
use MiraFive\Symfony\EventListener\Lifecycle;
use MiraFive\Symfony\Messenger\DeliverBatch;
use MiraFive\Symfony\Messenger\DeliverBatchHandler;
use MiraFive\Symfony\Test\MiraFake;
use MiraFive\Symfony\Test\RecordingTransport;
use MiraFive\Symfony\Twig\MiraFiveExtension;
use MiraFive\Symfony\Twig\MiraFiveRuntime;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class MiraFiveBundle extends AbstractBundle
{
    protected string $extensionAlias = 'mirafive';

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();

        if (! $root instanceof ArrayNodeDefinition) {
            throw new LogicException('The mirafive configuration root is an array node.');
        }

        $root
            ->children()
                ->scalarNode('secret_key')
                    ->info('Secret key of a server source. Server-side only; never render it into a page.')
                    ->defaultValue('%env(default::MIRAFIVE_SECRET_KEY)%')
                ->end()
                ->scalarNode('website_key')
                    ->info('Public website key, printed by mirafive_script().')
                    ->defaultValue('%env(default::MIRAFIVE_WEBSITE_KEY)%')
                ->end()
                ->scalarNode('host')
                    ->info('Ingest host with scheme. Empty means https://events.mirafive.io.')
                    ->defaultValue('%env(default::MIRAFIVE_HOST)%')
                ->end()
                ->enumNode('mode')
                    ->info('Collection mode of server-side events.')
                    ->values(['full', 'consentless'])
                    ->defaultValue('full')
                ->end()
                ->enumNode('script_mode')
                    ->info('Collection mode of the tracker printed by mirafive_script(). Consentless needs no banner.')
                    ->values(['consentless', 'full'])
                    ->defaultValue('consentless')
                ->end()
                ->booleanNode('enabled')
                    ->info('When false, or when there is no secret key, the client records nothing.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('messenger')
                    ->info('A message bus service id (true for messenger.default_bus) to deliver batches through Messenger.')
                    ->defaultNull()
                    ->beforeNormalization()
                        ->ifTrue(static fn (mixed $value): bool => $value === true)
                        ->then(static fn (): string => 'messenger.default_bus')
                    ->end()
                    ->beforeNormalization()
                        ->ifTrue(static fn (mixed $value): bool => $value === false)
                        ->then(static fn (): null => null)
                    ->end()
                ->end()
                ->arrayNode('flags')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('refresh_seconds')->min(10)->defaultValue(30)->end()
                        ->scalarNode('cache')
                            ->info('A PSR-16 or PSR-6 cache service id sharing the flag document between processes; null for none.')
                            ->defaultValue('cache.app')
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('test')
                    ->info('Record instead of sending, and expose MiraFive\Symfony\Test\MiraFake. For the test environment.')
                    ->defaultFalse()
                ->end()
            ->end();
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $test = $config['test'] === true;
        $bus = $test ? null : $config['messenger'];
        $flags = is_array($config['flags']) ? $config['flags'] : [];
        $cache = $test ? null : $flags['cache'];

        if (is_string($bus) && ! interface_exists(MessageBusInterface::class)) {
            throw new LogicException('mirafive.messenger needs symfony/messenger: composer require symfony/messenger');
        }

        if ($test) {
            $services->set(RecordingTransport::class);
            $services->alias('mirafive.transport', RecordingTransport::class);
            $services->set(MiraFake::class)
                ->args([service(Mira::class), service(RecordingTransport::class)])
                ->public();
        } else {
            $services->set('mirafive.transport', Transport::class)->factory([ClientFactory::class, 'defaultTransport']);
        }

        $services->set(ClientFactory::class)
            ->args([
                '$secretKey' => $config['secret_key'],
                '$host' => $config['host'],
                '$mode' => $config['mode'],
                '$enabled' => $config['enabled'],
                '$transport' => service('mirafive.transport'),
                '$bus' => is_string($bus) ? service($bus) : null,
                '$refreshSeconds' => $flags['refresh_seconds'] ?? 30,
                '$cache' => is_string($cache) && $cache !== '' ? service($cache) : null,
                '$logger' => service('logger')->nullOnInvalid(),
                '$test' => $test,
            ])
            ->tag('monolog.logger', ['channel' => 'mirafive']);

        $services->set(Mira::class)->factory([service(ClientFactory::class), 'mira']);
        $services->set(MiraFlags::class)->factory([service(Mira::class), 'flags']);

        $services->set(Lifecycle::class)
            ->args([service(ClientFactory::class)])
            ->tag('kernel.event_subscriber')
            ->tag('kernel.reset', ['method' => 'reset']);

        $services->set(CheckCommand::class)
            ->args([service(Mira::class)])
            ->tag('console.command', ['command' => CheckCommand::NAME, 'description' => CheckCommand::DESCRIPTION]);

        if (is_string($bus)) {
            $services->set(DeliverBatchHandler::class)
                ->args([service(Mira::class)])
                ->tag('messenger.message_handler', ['handles' => DeliverBatch::class]);
        }

        if (ContainerBuilder::willBeAvailable('twig/twig', Environment::class, ['symfony/twig-bundle'])) {
            $services->set(MiraFiveExtension::class)->tag('twig.extension');
            $services->set(MiraFiveRuntime::class)
                ->args([
                    service(ClientFactory::class),
                    service('request_stack'),
                    $config['website_key'],
                    $config['script_mode'],
                ])
                ->tag('twig.runtime');
        }
    }
}
