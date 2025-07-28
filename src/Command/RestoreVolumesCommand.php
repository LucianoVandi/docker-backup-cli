<?php

declare(strict_types=1);

namespace DockerBackup\Command;

use DockerBackup\Service\VolumeRestoreService;
use DockerBackup\ValueObject\RestoreResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RestoreVolumesCommand extends Command
{
    public function __construct(
        private readonly VolumeRestoreService $volumeRestoreService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $defaultDir = getcwd() . '/backups/volumes';

        $this->setName('restore:volumes')
            ->setDescription('Restore Docker volumes from tar.gz archives')
            ->addArgument(
                'archives',
                InputArgument::IS_ARRAY,
                'Backup archive files to restore (.tar or .tar.gz)'
            )
            ->addOption(
                'backup-dir',
                'b',
                InputOption::VALUE_REQUIRED,
                'Directory containing backup files',
                $defaultDir
            )
            ->addOption(
                'overwrite',
                null,
                InputOption::VALUE_NONE,
                'Overwrite existing volumes'
            )
            ->addOption(
                'no-create-volume',
                null,
                InputOption::VALUE_NONE,
                'Do not create volumes if they don\'t exist'
            )
            ->addOption(
                'list',
                'l',
                InputOption::VALUE_NONE,
                'List available backup archives and exit'
            )
            ->setHelp(
                <<<'HELP'
The <info>%command.name%</info> command restores Docker volumes from backup archives.

<info>Examples:</info>

  # Restore specific archives
  <info>php %command.full_name% volume1.tar.gz volume2.tar.gz</info>

  # Restore with custom backup directory
  <info>php %command.full_name% volume1.tar.gz --backup-dir=/tmp/backups</info>

  # Overwrite existing volumes
  <info>php %command.full_name% volume1.tar.gz --overwrite</info>

  # List available backup archives
  <info>php %command.full_name% --list</info>

The command extracts compressed or uncompressed tar archives to Docker volumes.
Each volume is restored using a temporary Alpine container to ensure consistency.
HELP
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Handle list option
        if ($input->getOption('list')) {
            return $this->listAvailableBackups($input, $io);
        }

        $archiveNames = $input->getArgument('archives');
        $backupDir = $input->getOption('backup-dir');
        $overwrite = $input->getOption('overwrite');
        $createVolumes = !$input->getOption('no-create-volume');

        // Check if archives argument is provided
        if (empty($archiveNames)) {
            $io->error('You must specify at least one archive file, or use --list to see available backups.');
            $io->text('Usage: restore:volumes archive1.tar.gz [archive2.tar.gz ...]');
            $io->text('   or: restore:volumes --list');

            return Command::FAILURE;
        }

        // Resolve full paths for archives
        $archivePaths = $this->resolveArchivePaths($archiveNames, $backupDir);

        // Validate all archives exist
        $missingArchives = array_filter($archivePaths, fn ($path) => !file_exists($path));
        if (!empty($missingArchives)) {
            $io->error('The following archive files do not exist:');
            foreach ($missingArchives as $missing) {
                $io->text("  - {$missing}");
            }

            return Command::FAILURE;
        }

        $io->title('Docker Volume Restore');
        $io->text("Restoring volumes from: <info>{$backupDir}</info>");

        if ($overwrite) {
            $io->text('<comment>⚠️  Overwrite mode enabled - existing volumes will be replaced</comment>');
        }

        if (!$createVolumes) {
            $io->text('<comment>⚠️  Volume creation disabled - only existing volumes will be restored</comment>');
        }

        // Perform restores
        $io->writeln(sprintf('Starting restore of <info>%d</info> archive(s)...', count($archivePaths)));
        $io->newLine();

        $results = $this->performRestoresWithProgress($archivePaths, $overwrite, $createVolumes, $io);

        $this->displaySummary($io, $results);

        // Return appropriate exit code
        $failedCount = count(array_filter($results, fn ($r) => $r->isFailed()));

        return $failedCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function listAvailableBackups(InputInterface $input, SymfonyStyle $io): int
    {
        $backupDir = $input->getOption('backup-dir');
        $backups = $this->volumeRestoreService->getAvailableBackups($backupDir);

        if (empty($backups)) {
            $io->warning("No backup archives found in: {$backupDir}");

            return Command::SUCCESS;
        }

        $io->title('Available Backup Archives');

        $tableData = [];
        foreach ($backups as $backup) {
            $tableData[] = [
                $backup['volume'],
                basename($backup['path']),
                $backup['compressed'] ? 'Yes' : 'No',
                $this->formatFileSize($backup['size']),
            ];
        }

        $io->table(['Volume Name', 'Archive File', 'Compressed', 'Size'], $tableData);
        $io->text(sprintf('Total: <info>%d</info> backup archives in <info>%s</info>', count($backups), $backupDir));

        return Command::SUCCESS;
    }

    private function resolveArchivePaths(array $archiveNames, string $backupDir): array
    {
        $paths = [];

        foreach ($archiveNames as $archiveName) {
            // If it's already an absolute path, use it as-is
            if (str_starts_with($archiveName, '/')) {
                $paths[] = $archiveName;
            } else {
                // Resolve relative to backup directory
                $paths[] = $backupDir . DIRECTORY_SEPARATOR . $archiveName;
            }
        }

        return $paths;
    }

    private function performRestoresWithProgress(array $archivePaths, bool $overwrite, bool $createVolumes, SymfonyStyle $io): array
    {
        $results = [];
        $totalCount = count($archivePaths);

        foreach ($archivePaths as $index => $archivePath) {
            $currentIndex = $index + 1;
            $archiveName = basename($archivePath);

            // Show what we're doing
            $io->write(sprintf('[%d/%d] 📦 Restoring <info>%s</info>... ', $currentIndex, $totalCount, $archiveName));

            // Perform the restore with timing
            $startTime = microtime(true);
            $result = $this->volumeRestoreService->restoreSingleVolume($archivePath, $overwrite, $createVolumes);
            $duration = round(microtime(true) - $startTime, 2);

            // Clear the line and show result
            $this->clearCurrentLine($io);
            $this->displayVolumeResult($io, $currentIndex, $totalCount, $result, $duration);

            $results[] = $result;
        }

        return $results;
    }

    private function displayVolumeResult(
        SymfonyStyle $io,
        int $currentIndex,
        int $totalCount,
        RestoreResult $result,
        float $duration
    ): void {
        // Format size info for successful restores
        $sizeInfo = $result->isSuccessful() && $result->fileSize
            ? sprintf(' (%s)', $result->getFormattedFileSize())
            : '';

        // Main result line
        $io->writeln(sprintf(
            '[%d/%d] %s <info>%s</info>%s <comment>(%ss)</comment>',
            $currentIndex,
            $totalCount,
            $result->getStatusIcon(),
            $result->resourceName,
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
        $successCount = count(array_filter($results, fn (RestoreResult $r) => $r->isSuccessful()));
        $failedCount = count(array_filter($results, fn (RestoreResult $r) => $r->isFailed()));
        $skippedCount = count(array_filter($results, fn (RestoreResult $r) => $r->isSkipped()));

        $io->newLine();
        $io->text([
            sprintf('<info>✅ Successful:</info> %d', $successCount),
            sprintf('<comment>⚠️ Skipped:</comment> %d', $skippedCount),
            sprintf('<error>❌ Failed:</error> %d', $failedCount),
        ]);

        if ($failedCount > 0) {
            $io->warning('Some restores failed.');
        } elseif ($successCount > 0) {
            $io->success('All restores completed successfully!');
        }
    }

    private function clearCurrentLine(SymfonyStyle $io): void
    {
        $io->write("\r");
        $io->write(str_repeat(' ', 100));
        $io->write("\r");
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $bytes;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return sprintf('%.2f %s', $size, $units[$unitIndex]);
    }
}
