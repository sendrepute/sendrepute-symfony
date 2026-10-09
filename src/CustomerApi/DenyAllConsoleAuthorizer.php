<?php

declare(strict_types=1);

namespace SendRepute\Symfony\CustomerApi;

use Symfony\Component\HttpFoundation\Request;

/** Default authorizer: nobody may use the console until the application decides. */
final class DenyAllConsoleAuthorizer implements ConsoleAuthorizer
{
    public function isGranted(Request $request): bool
    {
        return false;
    }
}
