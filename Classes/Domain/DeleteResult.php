<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Domain;

final class DeleteResult
{
    public function __construct(
        public readonly int $fileUid,
        public readonly string $identifier,
        public readonly DeleteOutcome $outcome,
        public readonly string $message = '',
    ) {}
}
