<?php

declare(strict_types=1);

namespace DockerBackup\Service;

use DockerBackup\Exception\DockerCommandException;
use DockerBackup\ValueObject\DockerImage;
use DockerBackup\ValueObject\DockerVolume;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class DockerService
{
    private const DOCKER_COMMAND = 'docker';

    /**
     * @return DockerVolume[]
     */
    public function listVolumes(): array
    {
        try {
            $process = $this->runDockerCommand(['volume', 'ls', '--format', 'json']);
            $output = trim($process->getOutput());

            if (empty($output)) {
                return [];
            }

            $volumes = [];
            foreach (explode("\n", $output) as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $data = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($data['Name'])) {
                    $volumes[] = DockerVolume::fromArray($data);
                }
            }

            return $volumes;
        } catch (DockerCommandException $e) {
            throw new DockerCommandException(
                'Failed to list volumes. Please ensure Docker 23+ is installed: ' . $e->getMessage()
            );
        }
    }

    /**
     * @return DockerImage[]
     */
    public function listImages(): array
    {
        try {
            $process = $this->runDockerCommand(['images', '--format', 'json']);
            $output = trim($process->getOutput());

            if (empty($output)) {
                return [];
            }

            $images = [];
            $lines = explode("\n", $output);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $data = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    $images[] = DockerImage::fromArray($data);
                }
            }

            return $images;
        } catch (\Exception) {
            // Fallback per versioni molto vecchie
            return [];
        }
    }

    public function volumeExists(string $volumeName): bool
    {
        try {
            $this->runDockerCommand(['volume', 'inspect', $volumeName]);

            return true;
        } catch (DockerCommandException) {
            return false;
        }
    }

    public function imageExists(string $imageReference): bool
    {
        try {
            $this->runDockerCommand(['image', 'inspect', $imageReference]);

            return true;
        } catch (DockerCommandException) {
            return false;
        }
    }

    public function runContainer(array $dockerArgs): Process
    {
        $command = array_merge([self::DOCKER_COMMAND, 'run'], $dockerArgs);

        return $this->executeCommand($command);
    }

    public function saveImage(string $imageReference, string $outputPath): Process
    {
        return $this->runDockerCommand(['save', '-o', $outputPath, $imageReference]);
    }

    public function loadImage(string $inputPath): Process
    {
        return $this->runDockerCommand(['load', '-i', $inputPath]);
    }

    private function runDockerCommand(array $args): Process
    {
        $command = array_merge([self::DOCKER_COMMAND], $args);

        return $this->executeCommand($command);
    }

    private function executeCommand(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(300); // 5 minutes timeout

        try {
            $process->mustRun();

            return $process;
        } catch (ProcessFailedException $exception) {
            throw new DockerCommandException(
                sprintf('Docker command failed: %s', $exception->getMessage()),
                $exception->getCode(),
                $exception
            );
        }
    }
}
