<?php

declare(strict_types=1);

namespace SendRepute\Symfony\CustomerApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Decides whether the current request belongs to an operator allowed to use the
 * customer API console. Applications alias this interface to their own
 * implementation (for example one that calls Security::isGranted()).
 */
interface ConsoleAuthorizer
{
    public function isGranted(Request $request): bool;
}
