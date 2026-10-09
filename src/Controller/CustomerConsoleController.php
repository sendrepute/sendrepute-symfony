<?php

declare(strict_types=1);

namespace SendRepute\Symfony\Controller;

use SendRepute\Symfony\CustomerApi\ConsoleAuthorizer;
use SendRepute\Symfony\CustomerApi\ConsoleService;
use SendRepute\Symfony\CustomerApi\SymfonyConsoleSession;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Operator console for the full customer API. Disabled unless
 * send_repute.customer_api.console.enabled is true, and every action is denied
 * unless the application's ConsoleAuthorizer grants the request. Calls require
 * a session-bound CSRF token in X-CSRF-Token and a same-origin Origin header.
 */
final class CustomerConsoleController
{
    private const CSRF = 'sendrepute_console_csrf';

    public function __construct(
        /** @var \Closure():ConsoleService resolved lazily so a disabled console never builds the client */
        private readonly \Closure $console,
        private readonly ConsoleAuthorizer $authorizer,
        private readonly bool $enabled,
    ) {
    }

    public function index(Request $request): Response
    {
        if (($denied = $this->guard($request)) !== null) {
            return $denied;
        }
        $base = rtrim($request->getBaseUrl().$request->getPathInfo(), '/');
        [$html, $csp] = ConsoleService::renderPage($base, $this->token($request->getSession()), 'Symfony');

        return new Response($html, 200, ConsoleService::securityHeaders() + ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Security-Policy' => $csp]);
    }

    public function catalog(Request $request): JsonResponse
    {
        if (($denied = $this->guard($request)) !== null) {
            return $denied;
        }

        return new JsonResponse(($this->console)()->catalog($this->token($request->getSession())), 200, ConsoleService::securityHeaders());
    }

    public function call(Request $request): JsonResponse
    {
        if (($denied = $this->guard($request)) !== null) {
            return $denied;
        }
        if (!$request->isMethod('POST')) {
            return $this->error(405, 'METHOD_NOT_ALLOWED', 'POST required');
        }
        $origin = (string) $request->headers->get('Origin', '');
        if ($origin === '' || parse_url($origin, PHP_URL_HOST) !== $request->getHost()) {
            return $this->error(403, 'ORIGIN_REFUSED', 'Cross-origin console request refused');
        }
        $session = $request->getSession();
        $expected = $session->get(self::CSRF);
        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if (!is_string($expected) || $token === '' || !hash_equals($expected, $token)) {
            return $this->error(403, 'CSRF_REFUSED', 'CSRF validation failed');
        }
        if (!str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), 'application/json')) {
            return $this->error(415, 'JSON_REQUIRED', 'JSON body required');
        }
        [$status, $payload] = ($this->console)()->handleRawCall((string) $request->getContent(), new SymfonyConsoleSession($session));

        return new JsonResponse($payload, $status, ConsoleService::securityHeaders());
    }

    private function guard(Request $request): ?JsonResponse
    {
        if (!$this->enabled) {
            return $this->error(404, 'NOT_FOUND', 'Not found');
        }
        if (!$request->hasSession() || !$this->authorizer->isGranted($request)) {
            return $this->error(403, 'FORBIDDEN', 'Operator authorization required');
        }

        return null;
    }

    private function token(SessionInterface $session): string
    {
        $token = $session->get(self::CSRF);
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $session->set(self::CSRF, $token);
        }

        return $token;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status, ConsoleService::securityHeaders());
    }
}
