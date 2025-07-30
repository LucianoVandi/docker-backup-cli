<?php

declare(strict_types=1);

namespace DockerBackup;

use DockerBackup\Service\DockerService;
use DockerBackup\Service\ImageBackupService;
use DockerBackup\Service\VolumeBackupService;
use DockerBackup\Service\VolumeRestoreService;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Application extends BaseApplication
{
    private const APP_NAME = 'Docker Backup & Restore CLI Tool';
    private const APP_VERSION = '1.0.0';
    private const APP_BANNER = '🐳 ' . self::APP_NAME;

    public function __construct()
    {
        parent::__construct(self::APP_NAME, self::APP_VERSION);

        $this->registerCustomCommands();
    }

    protected function configureIO(InputInterface $input, OutputInterface $output): void
    {
        parent::configureIO($input, $output);

        // Custom command banner
        if (!$input->hasParameterOption(['--quiet', '-q'], true)) {
            $output->writeln('');
            $output->writeln('<info>' . self::APP_BANNER . '</info>');
            $output->writeln('<comment>Version ' . $this->getVersion() . '</comment>');
            $output->writeln('');
        }
    }

    protected function getDefaultCommands(): array
    {
        // Keep only essential Symfony Console commands
        return [
            new HelpCommand(),
            new ListCommand(),
        ];
    }

    private function registerCustomCommands(): void
    {
        $dockerService = new DockerService();
        $volumeBackupService = new VolumeBackupService($dockerService);
        $volumeRestoreService = new VolumeRestoreService($dockerService);
        $imageBackupService = new ImageBackupService($dockerService);

        $commands = [
            new Command\BackupVolumesCommand($volumeBackupService),
            new Command\RestoreVolumesCommand($volumeRestoreService),
            new Command\BackupImagesCommand($imageBackupService),
        ];

        foreach ($commands as $command) {
            $this->add($command);
        }
    }
}
