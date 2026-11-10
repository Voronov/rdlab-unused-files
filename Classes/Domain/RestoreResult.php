<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Domain;

final class RestoreResult
{
    public function __construct(
        public readonly bool $restored,
        /** Combined identifier of the original place, empty when the entry is unknown */
        public readonly string $identifier,
        public readonly string $message = '',
    ) {}
}
