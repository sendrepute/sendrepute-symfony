<?php

declare(strict_types=1);

namespace SendRepute\Symfony;

final class ClassificationResult
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $model,
        public readonly string $label,
        public readonly float $spamProbability,
        public readonly string $confidence,
        public readonly bool $replayed,
        public readonly int $chargedMillicents,
    ) {
    }
}