<?php

declare(strict_types=1);

namespace SendRepute\Symfony\Exception;

use RuntimeException;

final class SendReputeException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $apiCode = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }
}