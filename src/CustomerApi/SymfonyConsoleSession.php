<?php

declare(strict_types=1);

namespace SendRepute\Symfony\CustomerApi;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class SymfonyConsoleSession implements ConsoleSession
{
    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session->get($key, $default);
    }

    public function put(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }
}
