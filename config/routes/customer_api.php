<?php

declare(strict_types=1);

// Import with a prefix of your choice, behind your firewall:
//   sendrepute_customer_api:
//     resource: '@SendReputeBundle/config/routes/customer_api.php'
//     prefix: /admin/sendrepute
use SendRepute\Symfony\Controller\CustomerConsoleController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('sendrepute_customer_api_console', '/customer-api')
        ->controller([CustomerConsoleController::class, 'index'])->methods(['GET']);
    $routes->add('sendrepute_customer_api_catalog', '/customer-api/catalog')
        ->controller([CustomerConsoleController::class, 'catalog'])->methods(['GET']);
    $routes->add('sendrepute_customer_api_call', '/customer-api/call')
        ->controller([CustomerConsoleController::class, 'call'])->methods(['POST']);
};
