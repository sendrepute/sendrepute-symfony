<?php

declare(strict_types=1);

namespace SendRepute\Symfony;

final class ClassificationOutcome
{
    public function __construct(
        public readonly string $kind,
        public readonly string $action,
        public readonly ?float $spamProbability = null,
        public readonly ?string $category = null,
        public readonly ?int $status = null,
        public readonly ?string $apiCode = null,
        public readonly ?string $requestId = null,
    ) {
    }
}