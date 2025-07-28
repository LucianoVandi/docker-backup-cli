<?php

declare(strict_types=1);

namespace DockerBackup\ValueObject;

use DockerBackup\Enum\BackupStatus;

final readonly class BackupResult
{
    public function __construct(
        public string $resourceName,
        public BackupStatus $status,
        public ?string $filePath = null,
        public ?string $message = null,
        public ?int $fileSize = null,
        public ?\DateTimeImmutable $completedAt = null
    ) {}

    public static function success(string $resourceName, string $filePath): self
    {
        $fileSize = file_exists($filePath) ? filesize($filePath) : null;

        return new self(
            resourceName: $resourceName,
            status: BackupStatus::SUCCESS,
            filePath: $filePath,
            message: 'Backup completed successfully',
            fileSize: $fileSize ?: null,
            completedAt: new \DateTimeImmutable()
        );
    }

    public static function failed(string $resourceName, string $message): self
    {
        return new self(
            resourceName: $resourceName,
            status: BackupStatus::FAILED,
            message: $message,
            completedAt: new \DateTimeImmutable()
        );
    }

    public static function skipped(string $resourceName, string $message): self
    {
        return new self(
            resourceName: $resourceName,
            status: BackupStatus::SKIPPED,
            message: $message,
            completedAt: new \DateTimeImmutable()
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === BackupStatus::SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === BackupStatus::FAILED;
    }

    public function isSkipped(): bool
    {
        return $this->status === BackupStatus::SKIPPED;
    }

    public function getFormattedFileSize(): string
    {
        if ($this->fileSize === null) {
            return 'N/A';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $this->fileSize;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return sprintf('%.2f %s', $size, $units[$unitIndex]);
    }

    public function getStatusIcon(): string
    {
        return match ($this->status) {
            BackupStatus::SUCCESS => '✅',
            BackupStatus::FAILED => '❌',
            BackupStatus::SKIPPED => '⚠️',
        };
    }
}
