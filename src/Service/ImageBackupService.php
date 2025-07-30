<?php

declare(strict_types=1);

namespace DockerBackup\Service;

use DockerBackup\Contract\DockerServiceInterface;
use DockerBackup\Exception\BackupException;
use DockerBackup\Trait\BackupFileSystemTrait;
use DockerBackup\ValueObject\DockerImage;
use DockerBackup\ValueObject\ImageBackupResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class ImageBackupService
{
    use BackupFileSystemTrait;

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
        // Create uncompressed tar first using docker save
        $tempFile = tempnam(sys_get_temp_dir(), 'image_backup_');

        try {
            // Step 1: Use docker save to create uncompressed tar
            $process = $this->dockerService->saveImage($imageReference, $tempFile);

            if (!$process->isSuccessful()) {
                throw new BackupException(
                    'Failed to save image: ' . $process->getErrorOutput()
                );
            }

            // Verify temp file was created and has content
            if (!file_exists($tempFile) || filesize($tempFile) === 0) {
                throw new BackupException('Docker save created empty or missing file');
            }

            // Step 2: Compress using PHP gzip functions
            $this->compressWithPhpGzip($tempFile, $archivePath);
        } finally {
            // Clean up temporary file
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    private function compressWithPhpGzip(string $inputPath, string $outputPath): void
    {
        $inputHandle = fopen($inputPath, 'rb');
        if (!$inputHandle) {
            throw new BackupException('Failed to open temporary tar file for reading');
        }

        $outputHandle = gzopen($outputPath, 'wb9'); // wb9 = maximum compression level
        if (!$outputHandle) {
            fclose($inputHandle);

            throw new BackupException('Failed to create compressed output file');
        }

        try {
            // Copy and compress in chunks to handle large files efficiently
            while (!feof($inputHandle)) {
                $chunk = fread($inputHandle, 8192); // 8KB chunks
                if ($chunk === false) {
                    throw new BackupException('Failed to read from temporary file');
                }

                if (gzwrite($outputHandle, $chunk) === false) {
                    throw new BackupException('Failed to write compressed data');
                }
            }

            $this->logger->info('Successfully compressed image backup: ' . basename($outputPath));
        } finally {
            fclose($inputHandle);
            gzclose($outputHandle);
        }
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

        return trim($sanitized, '_');
    }

    private function ensureBackupDirectoryExists(string $backupDirectory): void
    {
        $result = $this->ensureDirectoryExists($backupDirectory);

        if (!$result['success']) {
            throw new BackupException(
                "Failed to create backup directory '{$backupDirectory}': " . $result['error']
            );
        }
    }
}
