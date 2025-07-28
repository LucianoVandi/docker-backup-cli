<?php

declare(strict_types=1);

namespace DockerBackup\Service;

use DockerBackup\Exception\BackupException;
use DockerBackup\ValueObject\RestoreResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class VolumeRestoreService
{
    private LoggerInterface $logger;

    public function __construct(
        private DockerService $dockerService,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param string[] $archivePaths
     * @return RestoreResult[]
     */
    public function restoreVolumes(array $archivePaths, bool $overwrite = false, bool $createVolumes = true): array
    {
        $results = [];

        foreach ($archivePaths as $archivePath) {
            $results[] = $this->restoreSingleVolume($archivePath, $overwrite, $createVolumes);
        }

        return $results;
    }

    public function restoreSingleVolume(string $archivePath, bool $overwrite = false, bool $createVolume = true): RestoreResult
    {
        $volumeName = $this->extractVolumeNameFromPath($archivePath);
        $this->logger->info("Starting restore of volume: {$volumeName} from {$archivePath}");

        try {
            // Verify archive file exists
            if (!file_exists($archivePath)) {
                throw new BackupException("Archive file not found: {$archivePath}");
            }

            // Verify archive file is readable
            if (!is_readable($archivePath)) {
                throw new BackupException("Archive file is not readable: {$archivePath}");
            }

            // Check if volume already exists
            $volumeExists = $this->dockerService->volumeExists($volumeName);

            if ($volumeExists && !$overwrite) {
                $this->logger->warning("Volume already exists, skipping: {$volumeName}");
                return RestoreResult::skipped(
                    $volumeName,
                    "Volume already exists. Use --overwrite to replace it.",
                    $archivePath
                );
            }

            // Create volume if it doesn't exist and createVolume is true
            if (!$volumeExists && $createVolume) {
                $this->createVolume($volumeName);
            } elseif (!$volumeExists && !$createVolume) {
                throw new BackupException("Volume '{$volumeName}' does not exist and --no-create-volume was specified");
            }

            // If volume exists and overwrite is true, we need to clean it first
            if ($volumeExists && $overwrite) {
                $this->cleanVolume($volumeName);
            }

            // Perform restore
            $extractedBytes = $this->performVolumeRestore($volumeName, $archivePath);

            $this->logger->info("Successfully restored volume: {$volumeName}");

            return RestoreResult::success($volumeName, $archivePath);
        } catch (\Throwable $exception) {
            $this->logger->error("Failed to restore volume: {$volumeName}", [
                'error' => $exception->getMessage(),
                'archive' => $archivePath,
            ]);

            return RestoreResult::failed($volumeName, $exception->getMessage(), $archivePath);
        }
    }

    /**
     * Get available backup archives from a directory
     * @return array<string, array{path: string, volume: string, compressed: bool, size: int}>
     */
    public function getAvailableBackups(string $backupDirectory): array
    {
        if (!is_dir($backupDirectory)) {
            return [];
        }

        $backups = [];
        $files = glob($backupDirectory . '/*.{tar,tar.gz}', GLOB_BRACE);

        foreach ($files ?: [] as $file) {
            $volumeName = $this->extractVolumeNameFromPath($file);
            $compressed = str_ends_with($file, '.tar.gz');
            $size = filesize($file) ?: 0;

            $backups[$volumeName] = [
                'path' => $file,
                'volume' => $volumeName,
                'compressed' => $compressed,
                'size' => $size,
            ];
        }

        return $backups;
    }

    private function extractVolumeNameFromPath(string $archivePath): string
    {
        $filename = basename($archivePath);

        // Remove .tar.gz or .tar extension
        if (str_ends_with($filename, '.tar.gz')) {
            return substr($filename, 0, -7);
        }

        if (str_ends_with($filename, '.tar')) {
            return substr($filename, 0, -4);
        }

        throw new BackupException("Invalid archive file format: {$filename}. Expected .tar or .tar.gz");
    }

    private function createVolume(string $volumeName): void
    {
        $this->logger->info("Creating volume: {$volumeName}");

        $process = $this->dockerService->runContainer([
            '--rm',
            '-v', "{$volumeName}:/dummy",
            'alpine',
            'true'
        ]);

        if (!$process->isSuccessful()) {
            throw new BackupException(
                "Failed to create volume '{$volumeName}': " . $process->getErrorOutput()
            );
        }
    }

    private function cleanVolume(string $volumeName): void
    {
        $this->logger->info("Cleaning existing volume: {$volumeName}");

        $process = $this->dockerService->runContainer([
            '--rm',
            '-v', "{$volumeName}:/volume",
            'alpine',
            'sh', '-c', 'rm -rf /volume/* /volume/.[!.]* /volume/..?*'
        ]);

        if (!$process->isSuccessful()) {
            throw new BackupException(
                "Failed to clean volume '{$volumeName}': " . $process->getErrorOutput()
            );
        }
    }

    private function performVolumeRestore(string $volumeName, string $archivePath): ?int
    {
        $backupDir = dirname($archivePath);
        $archiveFilename = basename($archivePath);

        // Convert container path to equivalent host path
        $hostBackupDir = $this->getHostPath($backupDir);

        // Determine if archive is compressed
        $isCompressed = str_ends_with($archiveFilename, '.tar.gz');

        $tarCommand = $isCompressed
            ? ['tar', 'xzf', "/backup/{$archiveFilename}", '-C', '/volume']
            : ['tar', 'xf', "/backup/{$archiveFilename}", '-C', '/volume'];

        $dockerArgs = [
            '--rm',
            '-v', "{$volumeName}:/volume",
            '-v', "{$hostBackupDir}:/backup:ro",
            'alpine',
            ...$tarCommand,
        ];

        $process = $this->dockerService->runContainer($dockerArgs);

        if (!$process->isSuccessful()) {
            throw new BackupException(
                'Failed to extract backup archive: ' . $process->getErrorOutput()
            );
        }

        // Try to get the size of extracted content (optional)
        return $this->getVolumeSize($volumeName);
    }

    private function getVolumeSize(string $volumeName): ?int
    {
        try {
            $process = $this->dockerService->runContainer([
                '--rm',
                '-v', "{$volumeName}:/volume:ro",
                'alpine',
                'du', '-sb', '/volume'
            ]);

            if ($process->isSuccessful()) {
                $output = trim($process->getOutput());
                $parts = explode("\t", $output);
                return (int) ($parts[0] ?? 0);
            }
        } catch (\Throwable) {
            // Size calculation failed, not critical
        }

        return null;
    }

    /**
     * Convert a container path to equivalent host path.
     */
    private function getHostPath(string $containerPath): string
    {
        // Only if we're in development environment with Docker
        if (isset($_ENV['DOCKER_BACKUP_DEV_MODE'])) {
            $hostProjectDir = $_ENV['HOST_PROJECT_DIR'] ?? getcwd();
            return $hostProjectDir . substr($containerPath, 4);
        }

        return $containerPath; // Standalone mode
    }
}