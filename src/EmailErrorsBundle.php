<?php

declare(strict_types=1);

namespace Sparklink\EmailErrorsBundle;

use Monolog\LogRecord;
use Sparklink\EmailErrorsBundle\Monolog\EmailErrorsHandler;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class EmailErrorsBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('enabled')->defaultTrue()->end()
                ->scalarNode('mailer_service')->defaultValue('mailer.mailer')->end()
                ->scalarNode('from')->isRequired()->end()
                ->scalarNode('to')->isRequired()->end()
                ->scalarNode('subject')->defaultValue('Exception')->end()
                ->scalarNode('graphql')->defaultFalse()->end()
                ->integerNode('throttle')->defaultValue(0)->min(0)->info('Seconds during which the same error is not sent again (0 = off)')->end()
                ->scalarNode('monolog_level')->defaultValue('critical')->info('Minimum level of the records sent by the "email_errors.monolog_handler" Monolog handler')->end()
                ->arrayNode('ignored_exception_classes')
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('ignored_exception_messages')
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('ignored_ip_addresses')
                    ->prototype('scalar')->end()
                ->end()
            ->end()
        ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void
    {
        $enabled = $config['enabled'];

        $containerBuilder->setParameter('email_errors.enabled', $config['enabled']);
        $containerBuilder->setParameter('email_errors.from', $config['from']);
        $containerBuilder->setParameter('email_errors.to', $config['to']);
        $containerBuilder->setParameter('email_errors.subject', $config['subject']);
        $containerBuilder->setParameter('email_errors.ignored_exception_classes', $config['ignored_exception_classes']);
        $containerBuilder->setParameter('email_errors.ignored_exception_messages', $config['ignored_exception_messages']);
        $containerBuilder->setParameter('email_errors.ignored_ip_addresses', $config['ignored_ip_addresses']);
        $containerBuilder->setParameter('email_errors.throttle', $config['throttle']);

        // Monolog 3 handler, defined even when disabled (it then sends nothing) so a Monolog configuration using it keeps working
        if (class_exists(LogRecord::class)) {
            $containerConfigurator->services()
                ->set('email_errors.monolog_handler', EmailErrorsHandler::class)
                ->args([
                    $enabled ? new Reference('email_errors.exception_mailer') : null,
                    new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    $config['monolog_level'],
                ])
            ;
        }

        if (!$enabled) {
            return;
        }

        $containerConfigurator->import('../config/services.yaml');

        $containerConfigurator->services()
            ->get('email_errors.exception_mailer')
            ->arg(0, new Reference($config['mailer_service']))
        ;

        if ($config['graphql']) {
            $containerConfigurator->import('../config/graphql.yaml');
        }
    }
}
