<?php

declare(strict_types=1);

namespace DockerBackup\Trait;

trait BackupFileSystemTrait
{
    /**
     * Convert a container path to the equivalent host path.
     *
     * This handles the mapping between container paths and host paths
     * when running in Docker development mode.
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

    /**
     * Check if directory exists and is writable, create if necessary.
     *
     * @return array{success: bool, error?: string} Result with success status and optional error message
     */
    private function ensureDirectoryExists(string $directory): array
    {
        // Resolve absolute path to avoid issues with relative paths
        if (is_dir($directory)) {
            $directory = realpath($directory);
        }

        // If directory exists and is writable, all good
        if (is_dir($directory) && is_writable($directory)) {
            return ['success' => true];
        }

        // If directory doesn't exist, create it
        if (!is_dir($directory)) {
            $success = @mkdir($directory, 0755, true);

            if (!$success) {
                $error = error_get_last();

                return [
                    'success' => false,
                    'error' => 'Failed to create directory: ' . ($error['message'] ?? 'Unknown error'),
                ];
            }
        }

        // Final check that it's writable
        if (!is_writable($directory)) {
            return [
                'success' => false,
                'error' => 'Directory exists but is not writable. Check permissions or run with sudo if needed.',
            ];
        }

        return ['success' => true];
    }

    /**
     * Format bytes into human-readable format (B, KB, MB, GB, TB).
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0.00 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $bytes;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return sprintf('%.2f %s', $size, $units[$unitIndex]);
    }

    /**
     * Check if file exists and is readable.
     *
     * @return array{exists: bool, readable: bool} File access status
     */
    private function checkFileAccess(string $filePath): array
    {
        return [
            'exists' => file_exists($filePath),
            'readable' => is_readable($filePath),
        ];
    }

    /**
     * Check if file has valid archive extension.
     */
    private function hasValidArchiveExtension(string $filePath): bool
    {
        return str_ends_with($filePath, '.tar') || str_ends_with($filePath, '.tar.gz');
    }
}
