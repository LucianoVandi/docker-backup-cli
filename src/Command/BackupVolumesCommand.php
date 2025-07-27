<?php

declare(strict_types=1);

namespace DockerBackup\Command;

use DockerBackup\Service\VolumeBackupService;
use DockerBackup\ValueObject\BackupStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BackupVolumesCommand extends Command
{
    public function __construct(
        private readonly VolumeBackupService $volumeBackupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $defaultDir = $_ENV['BACKUP_DEFAULT_DIR'] ?? './backups/volumes';

        $this->setName('backup:volumes')
            ->setDescription('Backup Docker volumes to tar.gz archives')
            ->addArgument(
                'volumes',
                InputArgument::IS_ARRAY,  // Rimuovo REQUIRED
                'Names of volumes to backup'
            )
            ->addOption(
                'output-dir',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory for backup files',
                $defaultDir  // Usa la variabile d'ambiente
            )
            ->addOption(
                'list',
                'l',
                InputOption::VALUE_NONE,
                'List available volumes and exit'
            )
            ->setHelp(
                <<<'HELP'
The <info>%command.name%</info> command creates backups of Docker volumes.

<info>Examples:</info>

  # Backup specific volumes
  <info>php %command.full_name% volume1 volume2 volume3</info>

  # Backup with custom output directory
  <info>php %command.full_name% volume1 --output-dir=/tmp/backups</info>

  # List available volumes
  <info>php %command.full_name% --list</info>

The command creates compressed tar.gz archives of volume contents.
Each volume is backed up using a temporary Alpine container to ensure consistency.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Handle list option
        if ($input->getOption('list')) {
            return $this->listAvailableVolumes($io);
        }

        $volumeNames = $input->getArgument('volumes');

        // Check if volumes argument is provided
        if (empty($volumeNames)) {
            $io->error('You must specify at least one volume name, or use --list to see available volumes.');
            $io->text('Usage: docker:backup:volumes volume1 [volume2 ...]');
            $io->text('   or: docker:backup:volumes --list');
            return Command::FAILURE;
        }

        $outputDir = $input->getOption('output-dir');

        $io->title('Docker Volume Backup');
        $io->text("Backing up volumes to: <info>{$outputDir}</info>");

        // Validate volumes exist
        $availableVolumes = $this->volumeBackupService->getAvailableVolumes();
        $availableVolumeNames = array_map(fn($vol) => $vol->name, $availableVolumes);

        $invalidVolumes = array_diff($volumeNames, $availableVolumeNames);
        if (!empty($invalidVolumes)) {
            $io->error('The following volumes do not exist: ' . implode(', ', $invalidVolumes));
            return Command::FAILURE;
        }

        // Perform backups
        $io->progressStart(count($volumeNames));

        $results = [];
        foreach ($volumeNames as $volumeName) {
            $result = $this->volumeBackupService->backupSingleVolume($volumeName, $outputDir);
            $results[] = $result;
            $io->progressAdvance();
        }

        $io->progressFinish();

        // Display results
        $this->displayResults($io, $results);

        // Return appropriate exit code
        $failedCount = count(array_filter($results, fn($r) => $r->isFailed()));
        return $failedCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function listAvailableVolumes(SymfonyStyle $io): int
    {
        $volumes = $this->volumeBackupService->getAvailableVolumes();

        if (empty($volumes)) {
            $io->warning('No Docker volumes found.');
            return Command::SUCCESS;
        }

        $io->title('Available Docker Volumes');

        $tableData = [];
        foreach ($volumes as $volume) {
            $tableData[] = [
                $volume->name,
                $volume->driver,
                $volume->mountpoint ?: 'N/A'
            ];
        }

        $io->table(['Name', 'Driver', 'Mount Point'], $tableData);
        $io->text(sprintf('Total: <info>%d</info> volumes', count($volumes)));

        return Command::SUCCESS;
    }

    private function displayResults(SymfonyStyle $io, array $results): void
    {
        $io->newLine();
        $io->section('Backup Results');

        $tableData = [];
        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($results as $result) {
            $tableData[] = [
                $result->getStatusIcon(),
                $result->resourceName,
                $result->status->value,
                $result->filePath ? basename($result->filePath) : 'N/A',
                $result->getFormattedFileSize(),
                $result->message ?? 'N/A'
            ];

            match ($result->status) {
                BackupStatus::SUCCESS => $successCount++,
                BackupStatus::FAILED => $failedCount++,
                BackupStatus::SKIPPED => $skippedCount++,
            };
        }

        $io->table(
            ['Status', 'Volume', 'Result', 'File', 'Size', 'Message'],
            $tableData
        );

        // Summary
        $io->newLine();
        $io->text([
            sprintf('<info>✅ Successful:</info> %d', $successCount),
            sprintf('<comment>⚠️  Skipped:</comment> %d', $skippedCount),
            sprintf('<error>❌ Failed:</error> %d', $failedCount),
        ]);

        if ($failedCount > 0) {
            $io->warning('Some backups failed. Check the error messages above.');
        } elseif ($successCount > 0) {
            $io->success('All backups completed successfully!');
        }
    }
}