<?php

declare(strict_types=1);

namespace DockerBackup\ValueObject;

final readonly class ImageRestoreResult extends AbstractResult
{
    protected static function getDefaultSuccessMessage(): string
    {
        return 'Image restore completed successfully';
    }
}
