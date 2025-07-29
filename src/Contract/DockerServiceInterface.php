<?php

declare(strict_types=1);

namespace DockerBackup\Contract;

use DockerBackup\ValueObject\DockerImage;
use DockerBackup\ValueObject\DockerVolume;
use Symfony\Component\Process\Process;

interface DockerServiceInterface
{
    /**
     * @return DockerVolume[]
     */
    public function listVolumes(): array;

    /**
     * @return DockerImage[]
     */
    public function listImages(): array;

    public function volumeExists(string $volumeName): bool;

    public function imageExists(string $imageReference): bool;

    public function runContainer(array $dockerArgs): Process;

    public function saveImage(string $imageReference, string $outputPath): Process;

    public function loadImage(string $inputPath): Process;
}
