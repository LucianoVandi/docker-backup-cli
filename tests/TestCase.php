<?php

declare(strict_types=1);

namespace DockerBackup\Tests;

use DockerBackup\ValueObject\DockerImage;
use DockerBackup\ValueObject\DockerVolume;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

abstract class TestCase extends PHPUnitTestCase
{
    /** @var array<string> */
    private array $tempFiles = [];

    /** @var array<string> */
    private array $tempDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Common setup for all tests...
    }

    protected function tearDown(): void
    {
        // Auto-cleanup of all temporary files
        $this->cleanupAllTempFiles();
        $this->cleanupAllTempDirectories();

        parent::tearDown();
    }

    /**
     * Helper for testing Symfony Console commands
     */
    protected function createCommandTester(string $commandClass): CommandTester
    {
        $application = new Application();
        $command = new $commandClass();
        $application->add($command);

        return new CommandTester($command);
    }

    /**
     * Helper for creating temporary files in tests
     */
    protected function createTempFile(string $content = '', ?string $suffix = null): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'dockerbackup_test_');

        if ($suffix !== null) {
            $newTempFile = $tempFile . $suffix;
            rename($tempFile, $newTempFile);
            $tempFile = $newTempFile;
        }

        file_put_contents($tempFile, $content);
        $this->tempFiles[] = $tempFile;

        return $tempFile;
    }

    /**
     * Helper to create a temporary directory
     */
    protected function createTempDirectory(string $prefix = 'dockerbackup_test_'): string
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid($prefix, true);
        mkdir($tempDir, 0755, true);
        $this->tempDirectories[] = $tempDir;

        return $tempDir;
    }

    /**
     * Helper to cleanup temporary files
     */
    protected function cleanupTempFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Remove from the list of tracked files
        $this->tempFiles = array_filter($this->tempFiles, fn($file) => $file !== $filePath);
    }

    /**
     * Helper to create a mock backup file for tests
     */
    protected function createMockBackupFile(string $volumeName = 'test-volume'): string
    {
        $backupContent = "Mock backup content for volume: {$volumeName}\n";
        $backupContent .= "Created at: " . date('Y-m-d H:i:s') . "\n";
        $backupContent .= str_repeat("Sample data\n", 100); // Simula contenuto

        return $this->createTempFile($backupContent, '.tar.gz');
    }

    /**
     * Helper to mock the output of `docker volume ls`
     */
    protected function createMockDockerVolumeList(): string
    {
        return implode("\n", [
            'DRIVER    VOLUME NAME',
            'local     test-volume-1',
            'local     test-volume-2',
            'local     my-app-data',
            'local     postgres-data'
        ]);
    }

    /**
     * Helper to check if Docker is available in the system
     */
    protected function isDockerAvailable(): bool
    {
        $process = new Process(['docker', '--version']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Skip tests if Docker is not available
     */
    protected function requiresDocker(): void
    {
        if (!$this->isDockerAvailable()) {
            $this->markTestSkipped('Docker is not available for integration tests');
        }
    }

    /**
     * Helper to create a mock Process that simulates Docker commands
     */
    protected function createMockProcess(int $exitCode = 0, string $output = '', string $errorOutput = ''): Process
    {
        $process = $this->createMock(Process::class);

        $process->method('run')->willReturn($exitCode);
        $process->method('isSuccessful')->willReturn($exitCode === 0);
        $process->method('getExitCode')->willReturn($exitCode);
        $process->method('getOutput')->willReturn($output);
        $process->method('getErrorOutput')->willReturn($errorOutput);

        return $process;
    }

    /**
     * Helper to create a DockerVolume for tests
     */
    protected function createTestVolume(
        string $name = 'test-volume',
        string $driver = 'local',
        ?string $mountpoint = null
    ): DockerVolume {
        $mountpoint ??= "/var/lib/docker/volumes/{$name}/_data";

        return new DockerVolume($name, $driver, $mountpoint);
    }

    /**
     * Helper to create a DockerImage for tests
     */
    protected function createTestImage(
        ?string $id = null,
        array $repoTags = [],
        int $size = 0,
        ?int $created = null
    ): DockerImage {
        $id ??= 'sha256:' . bin2hex(random_bytes(32)); // 64 caratteri hex
        $created ??= time();

        if (empty($repoTags)) {
            $repoTags = ['test-image:latest'];
        }

        return new DockerImage($id, $repoTags, $size, $created);
    }

    /**
     * Helper to create multiple volumes for tests
     */
    protected function createTestVolumes(int $count = 3): array
    {
        $volumes = [];
        for ($i = 1; $i <= $count; $i++) {
            $volumes[] = $this->createTestVolume("test-volume-{$i}");
        }
        return $volumes;
    }

    /**
     * Helper to create multiple images for tests
     */
    protected function createTestImages(int $count = 3): array
    {
        $images = [];
        for ($i = 1; $i <= $count; $i++) {
            $images[] = $this->createTestImage(
                repoTags: ["test-image-{$i}:latest"],
                size: 1000000 * $i // Dimensioni diverse
            );
        }
        return $images;
    }

    /**
     * Auto-cleanup of all temporary files
     */
    private function cleanupAllTempFiles(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * Auto-cleanup of all temporary directories
     */
    private function cleanupAllTempDirectories(): void
    {
        foreach ($this->tempDirectories as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }
        $this->tempDirectories = [];
    }

    /**
     * Recursively removes a directory
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}