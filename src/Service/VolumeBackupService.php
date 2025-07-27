<?php

declare(strict_types=1);

namespace DockerBackup\Service;

use DockerBackup\Exception\BackupException;
use DockerBackup\ValueObject\BackupResult;
use DockerBackup\ValueObject\DockerVolume;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class VolumeBackupService
{
    private LoggerInterface $logger;

    public function __construct(
        private DockerService $dockerService,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param string[] $volumeNames
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

    public function backupSingleVolume(string $volumeName, string $backupDirectory): BackupResult
    {
        $this->logger->info("Starting backup of volume: {$volumeName}");

        try {
            // Verify volume exists
            if (!$this->dockerService->volumeExists($volumeName)) {
                throw new BackupException("Volume '{$volumeName}' not found");
            }

            $archivePath = $this->getArchivePath($volumeName, $backupDirectory);

            // Skip if backup already exists
            if (file_exists($archivePath)) {
                $this->logger->warning("Backup file already exists, skipping: {$archivePath}");
                return BackupResult::skipped($volumeName, "File already exists: {$archivePath}");
            }

            // Perform backup using Docker container
            $this->performVolumeBackup($volumeName, $archivePath);

            $this->logger->info("Successfully backed up volume: {$volumeName}");
            return BackupResult::success($volumeName, $archivePath);

        } catch (\Throwable $exception) {
            $this->logger->error("Failed to backup volume: {$volumeName}", [
                'error' => $exception->getMessage()
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

    private function performVolumeBackup(string $volumeName, string $archivePath): void
    {
        $backupDir = dirname($archivePath);
        $archiveFilename = basename($archivePath);

        // Converte il path del container nel path equivalente dell'host
        $hostBackupDir = $this->getHostPath($backupDir);

        $dockerArgs = [
            '--rm',
            '-v', "{$volumeName}:/volume:ro",
            '-v', "{$hostBackupDir}:/backup",
            'alpine',
            'tar', 'czf', "/backup/{$archiveFilename}", '-C', '/volume', '.'
        ];

        $process = $this->dockerService->runContainer($dockerArgs);

        if (!$process->isSuccessful()) {
            throw new BackupException(
                "Failed to create backup archive: " . $process->getErrorOutput()
            );
        }

        if (!file_exists($archivePath)) {
            throw new BackupException("Backup archive was not created: {$archivePath}");
        }
    }

    /**
     * Converte un path del container nel path equivalente dell'host
     */
    private function getHostPath(string $containerPath): string
    {
        // Se siamo in ambiente di sviluppo (container), convertiamo il path
        if (str_starts_with($containerPath, '/app/')) {
            // /app nel container corrisponde alla directory corrente dell'host
            // Il docker-compose monta . come /app
            $relativePath = substr($containerPath, 5); // rimuove "/app/"

            // Otteniamo il path dell'host dalla variabile d'ambiente o assumiamo il working dir
            $hostProjectDir = $_ENV['HOST_PROJECT_DIR'] ?? getcwd();
            return $hostProjectDir . '/' . $relativePath;
        }

        // Se non inizia con /app, assumiamo che sia già un path dell'host
        return $containerPath;
    }

    private function getArchivePath(string $volumeName, string $backupDirectory): string
    {
        return $backupDirectory . DIRECTORY_SEPARATOR . $volumeName . '.tar.gz';
    }

    private function ensureBackupDirectoryExists(string $backupDirectory): void
    {
        // Se la directory esiste già, tutto ok
        if (is_dir($backupDirectory) && is_writable($backupDirectory)) {
            return;
        }

        // Proviamo a creare la directory
        if (!is_dir($backupDirectory)) {
            $success = @mkdir($backupDirectory, 0755, true);
            if (!$success) {
                // Se mkdir fallisce, proviamo un approccio diverso
                $this->createDirectoryRecursively($backupDirectory);
            }
        }

        // Verifica finale
        if (!is_dir($backupDirectory)) {
            throw new BackupException("Failed to create backup directory: {$backupDirectory}");
        }

        if (!is_writable($backupDirectory)) {
            throw new BackupException("Backup directory is not writable: {$backupDirectory}");
        }
    }

    private function createDirectoryRecursively(string $path): void
    {
        $parts = explode('/', trim($path, '/'));
        $currentPath = '';

        foreach ($parts as $part) {
            if (empty($part)) continue;

            $currentPath .= '/' . $part;

            if (!is_dir($currentPath)) {
                $success = @mkdir($currentPath, 0755);
                if (!$success && !is_dir($currentPath)) {
                    throw new BackupException("Failed to create directory: {$currentPath}");
                }
            }
        }
    }
}