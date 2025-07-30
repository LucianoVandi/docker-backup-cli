<?php

declare(strict_types=1);

namespace DockerBackup\Service;

use DockerBackup\Contract\DockerServiceInterface;
use DockerBackup\Exception\BackupException;
use DockerBackup\Trait\BackupFileSystemTrait;
use DockerBackup\ValueObject\BackupResult;
use DockerBackup\ValueObject\DockerVolume;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class VolumeBackupService
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
     * @param string[] $volumeNames
     *
     * @return BackupResult[]
     */
    public function backupVolumes(array $volumeNames, string $backupDirectory): array
    {
        $this->ensureBackupDirectoryExists($backupDirectory);

        $results = [];

        foreach ($volumeNames as $volumeName) {
            $results[] = $this->backupSingleVolume($volumeName, $backupDirectory);
        }

        return $results;
    }

    public function backupSingleVolume(string $volumeName, string $backupDirectory, bool $compress = true): BackupResult
    {
        $this->logger->info("Starting backup of volume: {$volumeName}");

        try {
            // Verify volume exists
            if (!$this->dockerService->volumeExists($volumeName)) {
                throw new BackupException("Volume '{$volumeName}' not found");
            }

            $archivePath = $this->getArchivePath($volumeName, $backupDirectory, $compress);

            // Skip if backup already exists
            if (file_exists($archivePath)) {
                $this->logger->warning("Backup file already exists, skipping: {$archivePath}");

                return BackupResult::skipped($volumeName, "File already exists: {$archivePath}");
            }

            // Perform backup using Docker container
            $this->performVolumeBackup($volumeName, $archivePath, $compress);

            $this->logger->info("Successfully backed up volume: {$volumeName}");

            return BackupResult::success($volumeName, $archivePath);
        } catch (\Throwable $exception) {
            $this->logger->error("Failed to backup volume: {$volumeName}", [
                'error' => $exception->getMessage(),
            ]);

            return BackupResult::failed($volumeName, $exception->getMessage());
        }
    }

    /**
     * @return DockerVolume[]
     */
    public function getAvailableVolumes(): array
    {
        return $this->dockerService->listVolumes();
    }

    private function performVolumeBackup(string $volumeName, string $archivePath, bool $compress = true): void
    {
        $backupDir = dirname($archivePath);
        $archiveFilename = basename($archivePath);

        // Converte il path del container nel path equivalente dell'host
        $hostBackupDir = $this->getHostPath($backupDir);

        $tarCommand = $compress
            ? ['tar', 'czf', "/backup/{$archiveFilename}", '-C', '/volume', '.']
            : ['tar', 'cf', "/backup/{$archiveFilename}", '-C', '/volume', '.'];

        $dockerArgs = [
            '--rm',
            '-v', "{$volumeName}:/volume:ro",
            '-v', "{$hostBackupDir}:/backup",
            'alpine',
            ...$tarCommand,
        ];

        $process = $this->dockerService->runContainer($dockerArgs);

        if (!$process->isSuccessful()) {
            throw new BackupException(
                'Failed to create backup archive: ' . $process->getErrorOutput()
            );
        }

        if (!file_exists($archivePath)) {
            throw new BackupException("Backup archive was not created: {$archivePath}");
        }
    }

    private function getArchivePath(string $volumeName, string $backupDirectory, bool $compress = true): string
    {
        $extension = $compress ? '.tar.gz' : '.tar';

        return $backupDirectory . DIRECTORY_SEPARATOR . $volumeName . $extension;
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
