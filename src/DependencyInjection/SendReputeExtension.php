<?php

declare(strict_types=1);

namespace SendRepute\Symfony\DependencyInjection;

use SendRepute\Symfony\ClassifierInterface;
use SendRepute\Symfony\EventSubscriber\AnalyzeOutgoingMessage;
use SendRepute\Symfony\SendReputeClassifier;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class SendReputeExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        foreach ($config as $key => $value) {
            $container->setParameter('send_repute.'.$key, $value);
        }
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');
        $container->setAlias(ClassifierInterface::class, SendReputeClassifier::class);
        $container->getDefinition(AnalyzeOutgoingMessage::class)->addTag('kernel.event_subscriber');
    }
}