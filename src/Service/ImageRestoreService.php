<?php

declare(strict_types=1);

namespace DockerBackup\Service;

use DockerBackup\Contract\DockerServiceInterface;
use DockerBackup\Exception\RestoreException;
use DockerBackup\Trait\BackupFileSystemTrait;
use DockerBackup\ValueObject\ImageRestoreResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class ImageRestoreService
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
     * @param string[] $archivePaths
     *
     * @return ImageRestoreResult[]
     */
    public function restoreImages(array $archivePaths, bool $overwrite = false): array
    {
        $results = [];

        foreach ($archivePaths as $archivePath) {
            $results[] = $this->restoreSingleImage($archivePath, $overwrite);
        }

        return $results;
    }

    public function restoreSingleImage(string $archivePath, bool $overwrite = false): ImageRestoreResult
    {
        $archiveName = basename($archivePath);
        $this->logger->info("Starting restore of image from: {$archiveName}");

        try {
            $fileCheck = $this->checkFileAccess($archivePath);

            // Validate archive exists and is readable
            if (!$fileCheck['exists']) {
                throw new RestoreException("Archive file not found: {$archivePath}");
            }

            if (!$fileCheck['readable']) {
                throw new RestoreException("Archive file is not readable: {$archivePath}");
            }

            // Validate archive format
            $this->validateArchive($archivePath);

            // Extract image information from archive without loading it
            $imageInfo = $this->extractImageInfoFromArchive($archivePath);

            // Check if image already exists (if not overwriting)
            if (!$overwrite && $this->imageAlreadyExists($imageInfo)) {
                $message = "Image already exists: {$imageInfo['name']}. Use --overwrite to replace it.";
                $this->logger->warning($message);

                return ImageRestoreResult::skipped($archiveName, $message);
            }

            // Perform the restore
            $this->performImageRestore($archivePath);

            $this->logger->info("Successfully restored image from: {$archiveName}");

            return ImageRestoreResult::success($archiveName, $archivePath);
        } catch (\Throwable $exception) {
            $this->logger->error("Failed to restore image from: {$archiveName}", [
                'error' => $exception->getMessage(),
            ]);

            return ImageRestoreResult::failed($archiveName, $exception->getMessage());
        }
    }

    /**
     * Get list of available backup archives in a directory.
     *
     * @return array<array{name: string, path: string, size: int, compressed: bool}>
     */
    public function getAvailableBackups(string $backupDirectory): array
    {
        if (!is_dir($backupDirectory)) {
            return [];
        }

        $backups = [];
        $files = glob($backupDirectory . '/*.{tar,tar.gz}', GLOB_BRACE);

        foreach ($files as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $fileName = basename($filePath);
            $compressed = str_ends_with($filePath, '.tar.gz');

            // Extract original image name from filename
            $imageName = $this->extractImageNameFromFilename($fileName);

            $backups[] = [
                'name' => $imageName,
                'path' => $filePath,
                'size' => filesize($filePath) ?: 0,
                'compressed' => $compressed,
            ];
        }

        // Sort by name
        usort($backups, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $backups;
    }

    private function validateArchive(string $archivePath): void
    {
        $this->logger->info("Validating archive: {$archivePath}");

        // Validate file extension
        if (!$this->hasValidArchiveExtension($archivePath)) {
            throw new RestoreException(
                'Invalid archive format: ' . basename($archivePath)
                . '. Expected .tar or .tar.gz extension'
            );
        }

        $backupDir = dirname($archivePath);
        $archiveFilename = basename($archivePath);
        $hostBackupDir = $this->getHostPath($backupDir);

        // Test archive integrity using Docker (like VolumeRestoreService)
        $isCompressed = str_ends_with($archiveFilename, '.tar.gz');
        $testCommand = $isCompressed
            ? ['tar', 'tzf', "/backup/{$archiveFilename}"]
            : ['tar', 'tf', "/backup/{$archiveFilename}"];

        try {
            $process = $this->dockerService->runContainer([
                '--rm',
                '-v', "{$hostBackupDir}:/backup:ro",
                'alpine',
                ...$testCommand,
            ]);

            if (!$process->isSuccessful()) {
                throw new RestoreException(
                    'Archive integrity check failed: ' . trim($process->getErrorOutput())
                );
            }

            $output = trim($process->getOutput());
            if (empty($output)) {
                throw new RestoreException("Archive appears to be empty: {$archiveFilename}");
            }

            $this->logger->info('Archive validation successful');
        } catch (RestoreException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RestoreException(
                "Failed to validate archive {$archiveFilename}: " . $e->getMessage()
            );
        }
    }

    private function extractImageInfoFromArchive(string $archivePath): array
    {
        // For now, extract info from filename since full archive inspection is complex
        $fileName = basename($archivePath);
        $imageName = $this->extractImageNameFromFilename($fileName);

        return [
            'name' => $imageName,
            'archive' => $archivePath,
        ];
    }

    private function extractImageNameFromFilename(string $fileName): string
    {
        // Remove file extensions
        $name = preg_replace('/\.(tar\.gz|tar)$/', '', $fileName);

        // Convert underscores back to Docker image format
        // nginx_latest -> nginx:latest
        // docker_io_library_nginx_latest -> docker.io/library/nginx:latest
        $name = str_replace('_', ':', $name);

        // Handle registry prefixes (basic reconstruction)
        if (str_contains($name, ':::')) {
            $name = str_replace(':::', '/', $name);
        }

        return $name ?: 'unknown';
    }

    private function imageAlreadyExists(array $imageInfo): bool
    {
        // For simplicity, we'll check if any image with similar name exists
        // In a real implementation, you might want to parse the tar to get exact image info
        $imageName = $imageInfo['name'];

        try {
            return $this->dockerService->imageExists($imageName);
        } catch (\Exception) {
            // If we can't check, assume it doesn't exist
            return false;
        }
    }

    private function performImageRestore(string $archivePath): void
    {
        if (str_ends_with($archivePath, '.tar.gz')) {
            // Use pipe with gunzip for compressed files
            $this->performCompressedImageRestore($archivePath);
        } else {
            // Direct docker load for uncompressed
            $process = $this->dockerService->loadImage($archivePath);

            if (!$process->isSuccessful()) {
                throw new RestoreException(
                    'Failed to load image: ' . $process->getErrorOutput()
                );
            }
        }
    }

    private function performCompressedImageRestore(string $archivePath): void
    {
        // Decompress using PHP gzip functions, then use docker load
        $tempFile = tempnam(sys_get_temp_dir(), 'image_restore_');

        try {
            // Step 1: Decompress using PHP gzip
            $this->decompressWithPhpGzip($archivePath, $tempFile);

            // Step 2: Load the decompressed tar with docker load
            $process = $this->dockerService->loadImage($tempFile);

            if (!$process->isSuccessful()) {
                throw new RestoreException(
                    'Failed to load decompressed image: ' . $process->getErrorOutput()
                );
            }

            $this->logger->info('Successfully restored compressed image: ' . basename($archivePath));
        } finally {
            // Clean up temporary file
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    private function decompressWithPhpGzip(string $inputPath, string $outputPath): void
    {
        $inputHandle = gzopen($inputPath, 'rb');
        if (!$inputHandle) {
            throw new RestoreException('Failed to open compressed archive for reading');
        }

        $outputHandle = fopen($outputPath, 'wb');
        if (!$outputHandle) {
            gzclose($inputHandle);

            throw new RestoreException('Failed to create temporary file for decompression');
        }

        try {
            // Decompress in chunks to handle large files efficiently
            while (!gzeof($inputHandle)) {
                $chunk = gzread($inputHandle, 8192); // 8KB chunks
                if ($chunk === false) {
                    throw new RestoreException('Failed to read compressed data');
                }

                if (fwrite($outputHandle, $chunk) === false) {
                    throw new RestoreException('Failed to write decompressed data');
                }
            }

            $this->logger->info('Successfully decompressed archive to temporary file');
        } finally {
            gzclose($inputHandle);
            fclose($outputHandle);
        }
    }
}
