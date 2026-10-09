<?php

declare(strict_types=1);

use SendRepute\Symfony\Controller\CustomerConsoleController;
use SendRepute\Symfony\CustomerApi\ConsoleAuthorizer;
use SendRepute\Symfony\CustomerApi\ConsoleService;
use SendRepute\Symfony\CustomerApi\CustomerApiClient;
use SendRepute\Symfony\CustomerApi\DenyAllConsoleAuthorizer;
use SendRepute\Symfony\CustomerApi\SymfonyTransport;
use SendRepute\Symfony\EventSubscriber\AnalyzeOutgoingMessage;
use SendRepute\Symfony\SendReputeClassifier;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

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
    $services->set(SymfonyTransport::class)->args([service(HttpClientInterface::class)]);
    $services->set(CustomerApiClient::class)
        ->args([
            service(SymfonyTransport::class),
            param('send_repute.customer_api.api_key'),
            null,
            [],
            param('send_repute.customer_api.timeout_seconds'),
            param('send_repute.customer_api.max_response_bytes'),
        ]);
    $services->set(ConsoleService::class)
        ->args([
            service(CustomerApiClient::class),
            'Symfony',
            param('send_repute.customer_api.console.handoff_return_origin'),
            param('send_repute.customer_api.console.enabled_operations'),
            null,
        ]);
    $services->set(DenyAllConsoleAuthorizer::class);
    $services->alias(ConsoleAuthorizer::class, DenyAllConsoleAuthorizer::class);
    $services->set(CustomerConsoleController::class)
        ->public()
        ->tag('controller.service_arguments')
        ->args([
            service_closure(ConsoleService::class),
            service(ConsoleAuthorizer::class),
            param('send_repute.customer_api.console.enabled'),
        ]);
};
