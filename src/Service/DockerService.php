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
        // Prima proviamo il formato JSON moderno (Docker 23+)
        try {
            $process = $this->runDockerCommand(['volume', 'ls', '--format', 'json']);
            $output = trim($process->getOutput());

            if (!empty($output)) {
                $volumes = [];
                $lines = explode("\n", $output);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    // Se otteniamo JSON vero, usiamolo
                    $data = json_decode($line, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($data) && isset($data['Name'])) {
                        $volumes[] = DockerVolume::fromArray($data);
                    }
                    // Se otteniamo solo "json", il formato non è supportato
                    else if ($line === 'json') {
                        // Fallback a metodo compatibile
                        return $this->listVolumesCompatible();
                    }
                }

                // Se abbiamo ottenuto volumi validi, ritorniamoli
                if (!empty($volumes)) {
                    return $volumes;
                }
            }
        } catch (\Exception) {
            // Se il comando fallisce, proviamo il metodo compatibile
        }

        // Fallback per versioni Docker più vecchie
        return $this->listVolumesCompatible();
    }

    /**
     * Metodo compatibile per versioni Docker più vecchie (< 23.0)
     * @return DockerVolume[]
     */
    private function listVolumesCompatible(): array
    {
        // Usiamo formato template che funziona con tutte le versioni
        $process = $this->runDockerCommand(['volume', 'ls', '--format', '{{.Name}}']);
        $output = trim($process->getOutput());

        if (empty($output)) {
            return [];
        }

        $volumes = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Per ogni volume, otteniamo dettagli completi con inspect
            try {
                $inspectProcess = $this->runDockerCommand(['volume', 'inspect', $line]);
                $inspectOutput = trim($inspectProcess->getOutput());
                $volumeData = json_decode($inspectOutput, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($volumeData[0])) {
                    $volumes[] = DockerVolume::fromArray($volumeData[0]);
                } else {
                    // Fallback con dati minimi
                    $volumes[] = new DockerVolume(name: $line);
                }
            } catch (\Exception) {
                // Fallback con dati minimi
                $volumes[] = new DockerVolume(name: $line);
            }
        }

        return $volumes;
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