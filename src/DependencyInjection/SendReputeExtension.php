<?php

declare(strict_types=1);

namespace SendRepute\Symfony\DependencyInjection;

use SendRepute\Symfony\ClassifierInterface;
use SendRepute\Symfony\EventSubscriber\AnalyzeOutgoingMessage;
use SendRepute\Symfony\SendReputeClassifier;
use Symfony\Component\Config\FileLocator;
use SendRepute\Symfony\CustomerApi\ConsoleService;
use SendRepute\Symfony\CustomerApi\FileIntentStore;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class SendReputeExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $customer = $config['customer_api'];
        unset($config['customer_api']);
        foreach ($config as $key => $value) {
            $container->setParameter('send_repute.'.$key, $value);
        }
        $container->setParameter('send_repute.customer_api.api_key', $customer['api_key']);
        $container->setParameter('send_repute.customer_api.timeout_seconds', $customer['timeout_seconds']);
        $container->setParameter('send_repute.customer_api.max_response_bytes', $customer['max_response_bytes']);
        $container->setParameter('send_repute.customer_api.console.enabled', $customer['console']['enabled']);
        $container->setParameter('send_repute.customer_api.console.handoff_return_origin', $customer['console']['handoff_return_origin']);
        $container->setParameter('send_repute.customer_api.console.enabled_operations', $customer['console']['enabled_operations'] === [] ? null : $customer['console']['enabled_operations']);
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');
        $intent = $customer['console']['intent_store'];
        if ($intent['service'] !== null && $intent['service'] !== '') {
            $container->getDefinition(ConsoleService::class)->setArgument(4, new Reference($intent['service']));
        } elseif ($intent['path'] !== null && $intent['path'] !== '') {
            // Validated (absolute, 0700, owner, single-host acknowledgment) when first constructed.
            $container->register(FileIntentStore::class, FileIntentStore::class)->setArguments([$intent['path'], $intent['single_host']]);
            $container->getDefinition(ConsoleService::class)->setArgument(4, new Reference(FileIntentStore::class));
        }
        $container->setAlias(ClassifierInterface::class, SendReputeClassifier::class);
        $container->getDefinition(AnalyzeOutgoingMessage::class)->addTag('kernel.event_subscriber');
    }
}