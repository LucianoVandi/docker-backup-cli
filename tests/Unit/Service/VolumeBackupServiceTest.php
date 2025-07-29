<?php

declare(strict_types=1);

namespace DockerBackup\Tests\Unit\Service;

use DockerBackup\Contract\DockerServiceInterface;
use DockerBackup\Service\VolumeBackupService;
use DockerBackup\Tests\TestCase;

class VolumeBackupServiceTest extends TestCase
{
    private DockerServiceInterface $dockerService;
    private VolumeBackupService $backupService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dockerService = $this->createMock(DockerServiceInterface::class);
        $this->backupService = new VolumeBackupService($this->dockerService);
    }

    public function testBackupSingleVolumeWhenVolumeExists(): void
    {
        $volumeName = 'test-volume';
        $backupDir = $this->createTempDirectory();

        // Ensure that the archive file does not exist before the test
        $expectedArchivePath = $backupDir . DIRECTORY_SEPARATOR . $volumeName . '.tar.gz';
        if (file_exists($expectedArchivePath)) {
            unlink($expectedArchivePath);
        }

        $this->dockerService
            ->expects($this->once())
            ->method('volumeExists')
            ->with($volumeName)
            ->willReturn(true);

        $this->dockerService
            ->expects($this->once())
            ->method('runContainer')
            ->willReturnCallback(function() use ($expectedArchivePath) {
                touch($expectedArchivePath);
                return $this->createMockProcess(0, 'Backup completed');
            });

        $result = $this->backupService->backupSingleVolume($volumeName, $backupDir);

        $this->assertTrue($result->isSuccessful());
    }

    public function testBackupSingleVolumeWhenVolumeDoesNotExist(): void
    {
        $volumeName = 'non-existent';
        $backupDir = $this->createTempDirectory();

        $this->dockerService
            ->expects($this->once())
            ->method('volumeExists')
            ->with($volumeName)
            ->willReturn(false);

        $this->dockerService
            ->expects($this->never())
            ->method('runContainer');

        $result = $this->backupService->backupSingleVolume($volumeName, $backupDir);

        $this->assertFalse($result->isSuccessful());
    }

    public function testBackupMultipleVolumes(): void
    {
        $backupDir = $this->createTempDirectory();
        $volumeNames = ['volume1', 'volume2'];

        // Clean up any existing archives
        foreach ($volumeNames as $volumeName) {
            $archivePath = $backupDir . DIRECTORY_SEPARATOR . $volumeName . '.tar.gz';
            if (file_exists($archivePath)) {
                unlink($archivePath);
            }
        }

        $this->dockerService
            ->expects($this->exactly(2))
            ->method('volumeExists')
            ->willReturn(true);

        $callCount = 0;
        $this->dockerService
            ->expects($this->exactly(2))
            ->method('runContainer')
            ->willReturnCallback(function() use ($backupDir, $volumeNames, &$callCount) {
                $archivePath = $backupDir . DIRECTORY_SEPARATOR . $volumeNames[$callCount] . '.tar.gz';
                touch($archivePath);
                $callCount++;
                return $this->createMockProcess(0, 'Backup completed');
            });

        $results = $this->backupService->backupVolumes($volumeNames, $backupDir);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->isSuccessful());
        $this->assertTrue($results[1]->isSuccessful());
    }

    public function testGetAvailableVolumes(): void
    {
        $volumes = [
            $this->createTestVolume('vol1'),
            $this->createTestVolume('vol2')
        ];

        $this->dockerService
            ->expects($this->once())
            ->method('listVolumes')
            ->willReturn($volumes);

        $result = $this->backupService->getAvailableVolumes();

        $this->assertCount(2, $result);
        $this->assertEquals('vol1', $result[0]->name);
        $this->assertEquals('vol2', $result[1]->name);
    }

    public function testBackupWithCompressionDisabled(): void
    {
        $volumeName = 'test-volume';
        $backupDir = $this->createTempDirectory();

        // Clean up any existing archives with .tar extension
        $expectedArchivePath = $backupDir . DIRECTORY_SEPARATOR . $volumeName . '.tar';
        if (file_exists($expectedArchivePath)) {
            unlink($expectedArchivePath);
        }

        $this->dockerService
            ->method('volumeExists')
            ->willReturn(true);

        $this->dockerService
            ->method('runContainer')
            ->willReturnCallback(function() use ($expectedArchivePath) {
                touch($expectedArchivePath);
                return $this->createMockProcess(0, 'Backup completed');
            });

        $result = $this->backupService->backupSingleVolume($volumeName, $backupDir, false);

        $this->assertTrue($result->isSuccessful());
    }

    public function testBackupFailsWhenDockerCommandFails(): void
    {
        $volumeName = 'test-volume';
        $backupDir = $this->createTempDirectory();

        $this->dockerService
            ->method('volumeExists')
            ->willReturn(true);

        $failedProcess = $this->createMockProcess(1, '', 'Docker error');
        $this->dockerService
            ->method('runContainer')
            ->willReturn($failedProcess);

        $result = $this->backupService->backupSingleVolume($volumeName, $backupDir);

        $this->assertFalse($result->isSuccessful());
    }
}