<?php

declare(strict_types=1);

namespace DockerBackup\Service;

use DockerBackup\Contract\DockerServiceInterface;
use DockerBackup\Exception\BackupException;
use DockerBackup\ValueObject\ImageBackupResult;
use DockerBackup\ValueObject\DockerImage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class ImageBackupService
{
    private LoggerInterface $logger;

    public function __construct(
        private DockerServiceInterface $dockerService,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param string[] $imageReferences
     *
     * @return ImageBackupResult[]
     */
    public function backupImages(array $imageReferences, string $backupDirectory): array
    {
        $this->ensureBackupDirectoryExists($backupDirectory);

        $results = [];

        foreach ($imageReferences as $imageReference) {
            $results[] = $this->backupSingleImage($imageReference, $backupDirectory);
        }

        return $results;
    }

    public function backupSingleImage(string $imageReference, string $backupDirectory, bool $compress = true): ImageBackupResult
    {
        $this->logger->info("Starting backup of image: {$imageReference}");

        try {
            // Verify image exists
            if (!$this->dockerService->imageExists($imageReference)) {
                throw new BackupException("Image '{$imageReference}' not found");
            }

            $archivePath = $this->getArchivePath($imageReference, $backupDirectory, $compress);

            // Skip if backup already exists
            if (file_exists($archivePath)) {
                $this->logger->warning("Backup file already exists, skipping: {$archivePath}");

                return ImageBackupResult::skipped($imageReference, "File already exists: {$archivePath}");
            }

            // Perform backup using docker save
            $this->performImageBackup($imageReference, $archivePath, $compress);

            $this->logger->info("Successfully backed up image: {$imageReference}");

            return ImageBackupResult::success($imageReference, $archivePath);
        } catch (\Throwable $exception) {
            $this->logger->error("Failed to backup image: {$imageReference}", [
                'error' => $exception->getMessage(),
            ]);

            return ImageBackupResult::failed($imageReference, $exception->getMessage());
        }
    }

    /**
     * @return DockerImage[]
     */
    public function getAvailableImages(): array
    {
        return $this->dockerService->listImages();
    }

    private function performImageBackup(string $imageReference, string $archivePath, bool $compress = true): void
    {
        if ($compress) {
            // Use docker save with gzip compression
            $this->performCompressedImageBackup($imageReference, $archivePath);
        } else {
            // Use docker save directly
            $process = $this->dockerService->saveImage($imageReference, $archivePath);

            if (!$process->isSuccessful()) {
                throw new BackupException(
                    'Failed to save image: ' . $process->getErrorOutput()
                );
            }
        }

        if (!file_exists($archivePath)) {
            throw new BackupException("Backup archive was not created: {$archivePath}");
        }
    }

    private function performCompressedImageBackup(string $imageReference, string $archivePath): void
    {
        $backupDir = dirname($archivePath);
        $archiveFilename = basename($archivePath);

        // Convert container path to host path
        $hostBackupDir = $this->getHostPath($backupDir);

        $dockerArgs = [
            '--rm',
            '-v', "{$hostBackupDir}:/backup",
            'alpine',
            'sh', '-c',
            "docker save {$imageReference} | gzip > /backup/{$archiveFilename}"
        ];

        $process = $this->dockerService->runContainer($dockerArgs);

        if (!$process->isSuccessful()) {
            throw new BackupException(
                'Failed to create compressed backup archive: ' . $process->getErrorOutput()
            );
        }
    }

    /**
     * Convert a container path to the equivalent host path.
     */
    private function getHostPath(string $containerPath): string
    {
        // Only if we're in Docker development mode
        if (isset($_ENV['DOCKER_BACKUP_DEV_MODE'])) {
            $hostProjectDir = $_ENV['HOST_PROJECT_DIR'] ?? getcwd();

            return $hostProjectDir . substr($containerPath, 4);
        }

        return $containerPath; // Standalone mode
    }

    private function getArchivePath(string $imageReference, string $backupDirectory, bool $compress = true): string
    {
        // Convert image reference to safe filename
        $safeFilename = $this->sanitizeImageReference($imageReference);
        $extension = $compress ? '.tar.gz' : '.tar';

        return $backupDirectory . DIRECTORY_SEPARATOR . $safeFilename . $extension;
    }

    private function sanitizeImageReference(string $imageReference): string
    {
        // Replace unsafe characters for filenames
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $imageReference);

        // Handle registry prefixes like docker.io/library/nginx:latest
        $sanitized = str_replace(['/', ':'], '_', $sanitized);

        // Remove consecutive underscores and trim
        $sanitized = preg_replace('/_+/', '_', $sanitized);
        $sanitized = trim($sanitized, '_');

        return $sanitized;
    }

    private function ensureBackupDirectoryExists(string $backupDirectory): void
    {
        // Resolve absolute path to avoid issues with relative paths
        if (is_dir($backupDirectory)) {
            $backupDirectory = realpath($backupDirectory);
        }

        // If directory exists and is writable, all good
        if (is_dir($backupDirectory) && is_writable($backupDirectory)) {
            return;
        }

        // If directory doesn't exist, create it
        if (!is_dir($backupDirectory)) {
            $success = @mkdir($backupDirectory, 0755, true);

            if (!$success) {
                $error = error_get_last();

                throw new BackupException(
                    "Failed to create backup directory '{$backupDirectory}': "
                    . ($error['message'] ?? 'Unknown error')
                );
            }
        }

        // Final check that it's writable
        if (!is_writable($backupDirectory)) {
            throw new BackupException(
                "Backup directory '{$backupDirectory}' exists but is not writable. "
                . 'Check permissions or run with sudo if needed.'
            );
        }
    }
}