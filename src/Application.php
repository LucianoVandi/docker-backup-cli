<?php

declare(strict_types=1);

namespace DockerBackup;

use DockerBackup\Service\DockerService;
use DockerBackup\Service\VolumeBackupService;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Application extends BaseApplication
{
    public function __construct(string $name = 'Docker Backup CLI', string $version = '1.0.0')
    {
        parent::__construct($name, $version);

        // Registra i nostri comandi personalizzati
        $this->registerCustomCommands();
    }

    protected function configureIO(InputInterface $input, OutputInterface $output): void
    {
        parent::configureIO($input, $output);

        // Banner personalizzato
        if (!$input->hasParameterOption(['--quiet', '-q'], true)) {
            $output->writeln('');
            $output->writeln('<info>🐳 Docker Backup & Restore CLI Tool</info>');
            $output->writeln('<comment>Version ' . $this->getVersion() . '</comment>');
            $output->writeln('');
        }
    }

    protected function getDefaultCommands(): array
    {
        // Mantieni solo i comandi essenziali di Symfony Console
        return [
            new HelpCommand(),
            new ListCommand(),
        ];
    }

    private function registerCustomCommands(): void
    {
        $dockerService = new DockerService();
        $volumeBackupService = new VolumeBackupService($dockerService);
        $this->add(new Command\BackupVolumesCommand($volumeBackupService));
    }
}