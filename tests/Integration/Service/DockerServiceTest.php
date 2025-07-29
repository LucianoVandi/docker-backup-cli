<?php

declare(strict_types=1);

namespace DockerBackup\Tests\Integration\Service;

use DockerBackup\Exception\DockerCommandException;
use DockerBackup\Service\DockerService;
use DockerBackup\Tests\TestCase;
use DockerBackup\ValueObject\DockerVolume;
use DockerBackup\ValueObject\DockerImage;
use Symfony\Component\Process\Process;

class DockerServiceTest extends TestCase
{
    private DockerService $dockerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresDocker();
        $this->dockerService = new DockerService();
    }

    public function testListVolumesReturnsArray(): void
    {
        $volumes = $this->dockerService->listVolumes();

        $this->assertIsArray($volumes);

        foreach ($volumes as $volume) {
            $this->assertInstanceOf(DockerVolume::class, $volume);
            $this->assertNotEmpty($volume->name);
        }
    }

    public function testListImagesReturnsArray(): void
    {
        $images = $this->dockerService->listImages();

        $this->assertIsArray($images);

        foreach ($images as $image) {
            $this->assertInstanceOf(DockerImage::class, $image);
            $this->assertNotEmpty($image->id);
        }
    }

    public function testVolumeExistsWithNonExistentVolume(): void
    {
        $volumeName = 'non-existent-volume-' . uniqid();

        $exists = $this->dockerService->volumeExists($volumeName);

        $this->assertFalse($exists);
    }

    public function testImageExistsWithNonExistentImage(): void
    {
        $imageName = 'non-existent-image-' . uniqid();

        $exists = $this->dockerService->imageExists($imageName);

        $this->assertFalse($exists);
    }

    public function testRunContainerWithSimpleCommand(): void
    {
        try {
            $process = $this->dockerService->runContainer(['--help']);

            $this->assertInstanceOf(Process::class, $process);
        } catch (DockerCommandException $e) {
            $this->markTestSkipped('Docker not available: ' . $e->getMessage());
        }
    }

    public function testSaveImageWithNonExistentImage(): void
    {
        $this->expectException(DockerCommandException::class);

        $this->dockerService->saveImage('non-existent:latest', '/tmp/test.tar');
    }

    public function testLoadImageWithNonExistentFile(): void
    {
        $this->expectException(DockerCommandException::class);

        $this->dockerService->loadImage('/non/existent/file.tar');
    }
}