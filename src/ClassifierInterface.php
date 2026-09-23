<?php

declare(strict_types=1);

namespace SendRepute\Symfony;

interface ClassifierInterface
{
    public function classify(string $sender, string $subject, string $body, ?string $model = null): ClassificationResult;
}