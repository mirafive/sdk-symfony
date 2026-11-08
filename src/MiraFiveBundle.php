<?php

declare(strict_types=1);

namespace MiraFive\Symfony;

use LogicException;
use MiraFive\Http\Transport;
use MiraFive\Mira;
use MiraFive\ScriptTag;
use MiraFive\Symfony\Command\CheckCommand;
use MiraFive\Symfony\EventListener\Lifecycle;
use MiraFive\Symfony\Messenger\DeliverBatch;
use MiraFive\Symfony\Messenger\DeliverBatchHandler;
use MiraFive\Symfony\Twig\MiraFiveExtension;
use MiraFive\Symfony\Twig\MiraFiveRuntime;
use MiraFive\Testing\MiraFake;
use MiraFive\Testing\RecordingTransport;
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
            ->booleanNode('enabled')
            ->info('false: nothing leaves the app and mirafive_script() prints nothing; input is still checked.')
            ->defaultTrue()
            ->end()
            ->scalarNode('secret_key')
            ->info('The project\'s secret key (mira_sk_…), for server events and revenue. Never render it into a page.')
            ->defaultValue('%env(default::MIRAFIVE_SECRET_KEY)%')
            ->end()
            ->scalarNode('public_key')
            ->info('The project\'s public key (mira_pk_…), printed by mirafive_script() for the browser SDK.')
            ->defaultValue('%env(default::MIRAFIVE_PUBLIC_KEY)%')
            ->end()
            ->scalarNode('host')
            ->info('The ingest origin. Empty means https://events.mirafive.io.')
            ->defaultValue('%env(default::MIRAFIVE_HOST)%')
            ->end()
            ->scalarNode('messenger')
            ->info('A message bus service id (true for messenger.default_bus) that carries batches to a worker instead of sending them after the response.')
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
            ->scalarNode('cache')
            ->info('A PSR-16 or PSR-6 cache service id that shares the delivery breaker between processes; null for none.')
            ->defaultValue('cache.app')
            ->end()
            ->arrayNode('script')
            ->info('What mirafive_script() prints. Options passed to the function win.')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('src')
            ->info('Where the browser SDK loads from. Default: the MIRA FIVE CDN, or {host}/sdk/mira.js for another host.')
            ->defaultNull()
            ->end()
            ->enumNode('consent')
            ->info('auto follows the consent banner; analytics: analytics yes, marketing no.')
            ->values(ScriptTag::CONSENT)
            ->defaultValue('auto')
            ->end()
            ->variableNode('autocapture')
            ->info('false turns off all automatic events; or turn off parts: {forms: false, outbound: false}.')
            ->defaultTrue()
            ->end()
            ->end()
            ->end()
            ->integerNode('timeout_ms')->min(1)->defaultValue(3_000)->end()
            ->integerNode('flush_deadline_ms')->min(1)->defaultValue(3_000)->end()
            ->integerNode('max_retries')->min(0)->defaultValue(2)->end()
            ->booleanNode('test')
            ->info('Record instead of sending, and expose MiraFive\Testing\MiraFake. For the test environment.')
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
        $cache = $test ? null : $config['cache'];

        if (is_string($bus) && ! interface_exists(MessageBusInterface::class)) {
            throw new LogicException('mirafive.messenger needs symfony/messenger: composer require symfony/messenger');
        }

        if ($test) {
            $services->set('mirafive.transport', RecordingTransport::class)->public();
            $services->alias(RecordingTransport::class, 'mirafive.transport')->public();
            $services->set(MiraFake::class)
                ->args([service(Mira::class), service('mirafive.transport')])
                ->public();
        } else {
            $services->set('mirafive.transport', Transport::class)->factory([ClientFactory::class, 'defaultTransport']);
        }

        $services->set(ClientFactory::class)
            ->args([
                '$secretKey' => $config['secret_key'],
                '$host' => $config['host'],
                '$enabled' => $config['enabled'],
                '$transport' => service('mirafive.transport'),
                '$timeoutMs' => $config['timeout_ms'],
                '$flushDeadlineMs' => $config['flush_deadline_ms'],
                '$maxRetries' => $config['max_retries'],
                '$bus' => is_string($bus) ? service($bus) : null,
                '$cache' => is_string($cache) && $cache !== '' ? service($cache)->ignoreOnInvalid() : null,
                '$logger' => service('logger')->nullOnInvalid(),
                '$test' => $test,
            ])
            ->tag('monolog.logger', ['channel' => 'mirafive'])
            ->tag('kernel.reset', ['method' => 'reset']);

        $services->set(Mira::class)->factory([service(ClientFactory::class), 'mira']);

        $services->set(Lifecycle::class)
            ->args([service(ClientFactory::class)])
            ->tag('kernel.event_subscriber');

        $services->set(CheckCommand::class)
            ->args([service(ClientFactory::class), $config['public_key']])
            ->tag('console.command', ['command' => CheckCommand::NAME, 'description' => CheckCommand::DESCRIPTION]);

        if (is_string($bus)) {
            $services->set(DeliverBatchHandler::class)
                ->args([service(ClientFactory::class), service('logger')->nullOnInvalid()])
                ->tag('messenger.message_handler', ['handles' => DeliverBatch::class])
                ->tag('monolog.logger', ['channel' => 'mirafive']);
        }

        if (ContainerBuilder::willBeAvailable('twig/twig', Environment::class, ['symfony/twig-bundle'])) {
            $services->set(MiraFiveExtension::class)->tag('twig.extension');
            $services->set(MiraFiveRuntime::class)
                ->args([
                    service(ClientFactory::class),
                    $config['public_key'],
                    $config['script'],
                    service('logger')->nullOnInvalid(),
                ])
                ->tag('twig.runtime')
                ->tag('monolog.logger', ['channel' => 'mirafive']);
        }
    }
}
