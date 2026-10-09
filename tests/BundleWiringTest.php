<?php

declare(strict_types=1);

namespace SendRepute\Symfony\Tests;

use PHPUnit\Framework\TestCase;
use SendRepute\Symfony\DependencyInjection\SendReputeExtension;
use SendRepute\Symfony\EventSubscriber\AnalyzeOutgoingMessage;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BundleWiringTest extends TestCase
{
    public function testNativeExtensionWiresSubscriberWithSafeDefaults(): void
    {
        $container = new ContainerBuilder();
        $container->set(HttpClientInterface::class, new MockHttpClient());
        $container->set('event_dispatcher', new EventDispatcher());
        (new SendReputeExtension())->load([], $container);
        self::assertTrue($container->hasDefinition(AnalyzeOutgoingMessage::class));
        self::assertNotEmpty($container->getDefinition(AnalyzeOutgoingMessage::class)->getTag('kernel.event_subscriber'));
        $container->compile();

        self::assertFalse($container->getParameter('send_repute.enabled'));
        self::assertFalse($container->getParameter('send_repute.paid_analysis_consent'));
        self::assertSame('advisory', $container->getParameter('send_repute.mode'));
        self::assertSame('allow', $container->getParameter('send_repute.failure_policy'));
    }
}