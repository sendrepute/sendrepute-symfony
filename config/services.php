<?php

declare(strict_types=1);

use SendRepute\Symfony\EventSubscriber\AnalyzeOutgoingMessage;
use SendRepute\Symfony\SendReputeClassifier;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->set(SendReputeClassifier::class)
        ->args([
            service(HttpClientInterface::class),
            param('send_repute.api_token'),
            param('send_repute.paid_analysis_consent'),
            param('send_repute.connect_timeout_seconds'),
            param('send_repute.request_timeout_seconds'),
        ]);
    $services->set(AnalyzeOutgoingMessage::class)
        ->args([
            service(SendReputeClassifier::class),
            service('event_dispatcher'),
            param('send_repute.enabled'),
            param('send_repute.paid_analysis_consent'),
            param('send_repute.mode'),
            param('send_repute.failure_policy'),
            param('send_repute.spam_probability_threshold'),
            param('send_repute.opt_in_header'),
            param('send_repute.model'),
        ]);
};