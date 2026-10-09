<?php

declare(strict_types=1);

namespace SendRepute\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('send_repute');
        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->booleanNode('paid_analysis_consent')->defaultFalse()->end()
                ->scalarNode('api_token')->defaultValue('')->end()
                ->enumNode('mode')->values(['advisory', 'blocking'])->defaultValue('advisory')->end()
                ->enumNode('failure_policy')->values(['allow', 'block'])->defaultValue('allow')->end()
                ->floatNode('spam_probability_threshold')->min(0.0)->max(1.0)->defaultValue(0.8)->end()
                ->scalarNode('opt_in_header')
                    ->defaultValue('X-SendRepute-Classify')
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $value) !== 1)
                        ->thenInvalid('opt_in_header must be a valid 1-64 character mail header name.')
                    ->end()
                ->end()
                ->enumNode('model')->values(['thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo'])->defaultNull()->end()
                ->floatNode('connect_timeout_seconds')->min(0.1)->max(30.0)->defaultValue(3.0)->end()
                ->floatNode('request_timeout_seconds')->min(0.1)->max(60.0)->defaultValue(10.0)->end()
                ->arrayNode('customer_api')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('api_key')->defaultValue('')->end()
                        ->floatNode('timeout_seconds')->min(1.0)->max(60.0)->defaultValue(20.0)->end()
                        ->integerNode('max_response_bytes')->min(1024)->max(16777216)->defaultValue(8388608)->end()
                        ->arrayNode('console')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->scalarNode('handoff_return_origin')->defaultNull()->end()
                                ->arrayNode('enabled_operations')->scalarPrototype()->end()->defaultValue([])->end()
                                ->arrayNode('intent_store')
                                    ->info('Durable paid-intent ledger. Paid and billing operations are refused until one is configured.')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('service')->defaultNull()->info('Id of a custom IntentLedger service (multi-host deployments).')->end()
                                        ->scalarNode('path')->defaultNull()->info('Absolute 0700 directory for the filesystem store. SINGLE HOST ONLY.')->end()
                                        ->booleanNode('single_host')->defaultFalse()->info('Must be true to acknowledge the filesystem store is single-host only.')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}