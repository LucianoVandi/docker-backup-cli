<?php

namespace DockerBackup\Enum;

enum BackupStatus: string
{
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}