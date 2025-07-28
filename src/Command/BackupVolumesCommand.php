<?php

declare(strict_types=1);

namespace DockerBackup\Command;

use DockerBackup\Service\VolumeBackupService;
use DockerBackup\ValueObject\BackupResult;
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
        $defaultDir = getcwd() . '/backups/volumes';

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
                $defaultDir
            )
            ->addOption(
                'no-compression',
                null,
                InputOption::VALUE_NONE,
                'Create uncompressed tar archives instead of gzip compressed'
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
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Handle list option
        if ($input->getOption('list')) {
            return $this->listAvailableVolumes($io);
        }

        $volumeNames = $input->getArgument('volumes');
        $compress = !$input->getOption('no-compression');

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
        $availableVolumeNames = array_map(fn ($vol) => $vol->name, $availableVolumes);

        $invalidVolumes = array_diff($volumeNames, $availableVolumeNames);
        if (!empty($invalidVolumes)) {
            $io->error('The following volumes do not exist: ' . implode(', ', $invalidVolumes));

            return Command::FAILURE;
        }

        // Perform backups
        $io->writeln(sprintf('Starting backup of <info>%d</info> volume(s)...', count($volumeNames)));
        $io->newLine();

        $results = $this->performBackupsWithProgress($volumeNames, $outputDir, $compress, $io);

        $this->displaySummary($io, $results);

        // Return appropriate exit code
        $failedCount = count(array_filter($results, fn ($r) => $r->isFailed()));

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
                $volume->mountpoint ?: 'N/A',
            ];
        }

        $io->table(['Name', 'Driver', 'Mount Point'], $tableData);
        $io->text(sprintf('Total: <info>%d</info> volumes', count($volumes)));

        return Command::SUCCESS;
    }

    private function performBackupsWithProgress(array $volumeNames, string $outputDir, bool $compress, SymfonyStyle $io): array
    {
        $results = [];
        $totalCount = count($volumeNames);

        foreach ($volumeNames as $index => $volumeName) {
            $currentIndex = $index + 1;

            // Show what we're doing
            $io->write(sprintf('[%d/%d] 📦 Backing up <info>%s</info>... ', $currentIndex, $totalCount, $volumeName));

            // Perform the backup with timing
            $startTime = microtime(true);
            $result = $this->volumeBackupService->backupSingleVolume($volumeName, $outputDir, $compress);
            $duration = round(microtime(true) - $startTime, 2);

            // Clear the line and show result
            $this->clearCurrentLine($io);
            $this->displayVolumeResult($io, $currentIndex, $totalCount, $volumeName, $result, $duration);

            $results[] = $result;
        }

        return $results;
    }

    private function displayVolumeResult(
        SymfonyStyle $io,
        int $currentIndex,
        int $totalCount,
        string $volumeName,
        BackupResult $result,
        float $duration
    ): void {
        // Format size info for successful backups
        $sizeInfo = $result->isSuccessful() && $result->filePath
            ? sprintf(' (%s)', $result->getFormattedFileSize())
            : '';

        // Main result line
        $io->writeln(sprintf(
            '[%d/%d] %s <info>%s</info>%s <comment>(%ss)</comment>',
            $currentIndex,
            $totalCount,
            $result->getStatusIcon(),
            $volumeName,
            $sizeInfo,
            $duration
        ));

        // Additional message for errors or skips
        if ($result->message && !$result->isSuccessful()) {
            $io->writeln(sprintf('      <comment>→ %s</comment>', $result->message));
        }
    }

    private function displaySummary(SymfonyStyle $io, array $results): void
    {
        $successCount = count(array_filter($results, fn (BackupResult $r) => $r->isSuccessful()));
        $failedCount = count(array_filter($results, fn (BackupResult $r) => $r->isFailed()));
        $skippedCount = count(array_filter($results, fn (BackupResult $r) => $r->isSkipped()));

        $io->newLine();
        $io->text([
            sprintf('<info>✅ Successful:</info> %d', $successCount),
            sprintf('<comment>⚠️ Skipped:</comment> %d', $skippedCount),
            sprintf('<error>❌ Failed:</error> %d', $failedCount),
        ]);

        if ($failedCount > 0) {
            $io->warning('Some backups failed.');
        } elseif ($successCount > 0) {
            $io->success('All backups completed successfully!');
        }
    }

    private function clearCurrentLine(SymfonyStyle $io): void
    {
        $io->write("\r"); // Return to start of line
        $io->write(str_repeat(' ', 100));   // Clear the line
        $io->write("\r"); // Return to start again
    }
}
