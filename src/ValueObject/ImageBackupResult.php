<?php

declare(strict_types=1);

namespace DockerBackup\ValueObject;

final readonly class ImageBackupResult extends AbstractResult
{
    protected static function getDefaultSuccessMessage(): string
    {
        return 'Image backup completed successfully';
    }
}