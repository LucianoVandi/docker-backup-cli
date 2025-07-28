<?php

declare(strict_types=1);

namespace DockerBackup\ValueObject;

final readonly class RestoreResult extends AbstractResult
{
    protected static function getDefaultSuccessMessage(): string
    {
        return 'Restore completed successfully';
    }
}