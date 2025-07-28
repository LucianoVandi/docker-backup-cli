<?php

declare(strict_types=1);

namespace DockerBackup\Enum;

enum OperationStatus: string
{
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
