<?php

declare(strict_types=1);

namespace DockerBackup\ValueObject;

use DockerBackup\Enum\OperationStatus;

abstract readonly class AbstractResult
{
    public function __construct(
        public string $resourceName,
        public OperationStatus $status,
        public ?string $filePath = null,
        public ?string $message = null,
        public ?int $fileSize = null,
        public ?\DateTimeImmutable $completedAt = null
    ) {}

    public static function success(string $resourceName, string $filePath, ?string $message = null): static
    {
        $fileSize = file_exists($filePath) ? filesize($filePath) : null;

        return new static(
            resourceName: $resourceName,
            status: OperationStatus::SUCCESS,
            filePath: $filePath,
            message: $message ?? static::getDefaultSuccessMessage(),
            fileSize: $fileSize ?: null,
            completedAt: new \DateTimeImmutable()
        );
    }

    public static function failed(string $resourceName, string $message): static
    {
        return new static(
            resourceName: $resourceName,
            status: OperationStatus::FAILED,
            message: $message,
            completedAt: new \DateTimeImmutable()
        );
    }

    public static function skipped(string $resourceName, string $message): static
    {
        return new static(
            resourceName: $resourceName,
            status: OperationStatus::SKIPPED,
            message: $message,
            completedAt: new \DateTimeImmutable()
        );
    }

    abstract protected static function getDefaultSuccessMessage(): string;

    public function isSuccessful(): bool
    {
        return $this->status === OperationStatus::SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === OperationStatus::FAILED;
    }

    public function isSkipped(): bool
    {
        return $this->status === OperationStatus::SKIPPED;
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
            OperationStatus::SUCCESS => '✅',
            OperationStatus::FAILED => '❌',
            OperationStatus::SKIPPED => '⚠️',
        };
    }
}