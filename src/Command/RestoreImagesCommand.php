<?php

declare(strict_types=1);

namespace DockerBackup\Command;

use DockerBackup\Service\ImageRestoreService;
use DockerBackup\ValueObject\ImageRestoreResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RestoreImagesCommand extends Command
{
    public function __construct(
        private readonly ImageRestoreService $imageRestoreService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $defaultDir = getcwd() . '/backups/images';

        $this->setName('restore:images')
            ->setDescription('Restore Docker images from tar.gz archives')
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
                'Overwrite existing images with the same name'
            )
            ->addOption(
                'list',
                'l',
                InputOption::VALUE_NONE,
                'List available backup archives and exit'
            )
            ->setHelp(
                <<<'HELP'
The <info>%command.name%</info> command restores Docker images from backup archives.

<info>Examples:</info>

  # Restore specific archives
  <info>php %command.full_name% nginx_latest.tar.gz mysql_8.0.tar.gz</info>

  # Restore with custom backup directory
  <info>php %command.full_name% nginx_latest.tar.gz --backup-dir=/tmp/backups</info>

  # Overwrite existing images
  <info>php %command.full_name% nginx_latest.tar.gz --overwrite</info>

  # List available backup archives
  <info>php %command.full_name% --list</info>

The command uses Docker's native load functionality to restore images from archives.
Both compressed (.tar.gz) and uncompressed (.tar) archives are supported.
By default, existing images with the same name will not be overwritten.
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

        // Check if archives argument is provided
        if (empty($archiveNames)) {
            $io->error('You must specify at least one archive file, or use --list to see available backups.');
            $io->text('Usage: restore:images archive1.tar.gz [archive2.tar.gz ...]');
            $io->text('   or: restore:images --list');

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

        // Validate archive integrity (quick check)
        $io->text('🔍 Validating archives...');
        $invalidArchives = $this->validateArchivesIntegrity($archivePaths);
        if (!empty($invalidArchives)) {
            $io->error('The following archives failed validation:');
            foreach ($invalidArchives as $invalid => $reason) {
                $io->text("  - {$invalid}: {$reason}");
            }

            return Command::FAILURE;
        }

        $io->text('<info>✅ All archives validated successfully</info>');
        $io->newLine();

        if (!$this->confirmDestructiveOperation($archivePaths, $overwrite, $io)) {
            $io->text('Operation cancelled by user.');

            return Command::SUCCESS;
        }

        $io->title('Docker Image Restore');
        $io->text("Restoring images from: <info>{$backupDir}</info>");

        if ($overwrite) {
            $io->text('<comment>⚠️  Overwrite mode enabled - existing images will be replaced</comment>');
        } else {
            $io->text('<info>ℹ️  Existing images will be skipped (use --overwrite to replace them)</info>');
        }

        // Perform restores
        $io->writeln(sprintf('Starting restore of <info>%d</info> archive(s)...', count($archivePaths)));
        $io->newLine();

        $results = $this->performRestoresWithProgress($archivePaths, $overwrite, $io);

        $this->displaySummary($io, $results);

        // Return appropriate exit code
        $failedCount = count(array_filter($results, fn ($r) => $r->isFailed()));

        return $failedCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function listAvailableBackups(InputInterface $input, SymfonyStyle $io): int
    {
        $backupDir = $input->getOption('backup-dir');
        $backups = $this->imageRestoreService->getAvailableBackups($backupDir);

        if (empty($backups)) {
            $io->warning("No backup archives found in: {$backupDir}");

            return Command::SUCCESS;
        }

        $io->title('Available Image Backup Archives');

        $tableData = [];
        foreach ($backups as $backup) {
            $tableData[] = [
                $backup['name'],
                basename($backup['path']),
                $backup['compressed'] ? 'Yes' : 'No',
                $this->formatFileSize($backup['size']),
            ];
        }

        $io->table(['Image Name', 'Archive File', 'Compressed', 'Size'], $tableData);
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

    private function performRestoresWithProgress(array $archivePaths, bool $overwrite, SymfonyStyle $io): array
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
            $result = $this->imageRestoreService->restoreSingleImage($archivePath, $overwrite);
            $duration = round(microtime(true) - $startTime, 2);

            // Clear the line and show result
            $this->clearCurrentLine($io);
            $this->displayImageResult($io, $currentIndex, $totalCount, $result, $duration);

            $results[] = $result;
        }

        return $results;
    }

    private function displayImageResult(
        SymfonyStyle $io,
        int $currentIndex,
        int $totalCount,
        ImageRestoreResult $result,
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
        $successCount = count(array_filter($results, fn (ImageRestoreResult $r) => $r->isSuccessful()));
        $failedCount = count(array_filter($results, fn (ImageRestoreResult $r) => $r->isFailed()));
        $skippedCount = count(array_filter($results, fn (ImageRestoreResult $r) => $r->isSkipped()));

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

    private function validateArchivesIntegrity(array $archivePaths): array
    {
        $invalidArchives = [];

        foreach ($archivePaths as $archivePath) {
            $archiveName = basename($archivePath);

            try {
                // Quick format check first
                if (!str_ends_with($archivePath, '.tar') && !str_ends_with($archivePath, '.tar.gz')) {
                    $invalidArchives[$archiveName] = 'Invalid file extension (expected .tar or .tar.gz)';

                    continue;
                }

                // Check file is readable
                if (!is_readable($archivePath)) {
                    $invalidArchives[$archiveName] = 'File is not readable';

                    continue;
                }

                // Check basic file integrity
                if (filesize($archivePath) === 0) {
                    $invalidArchives[$archiveName] = 'Archive file is empty';

                    continue;
                }

                // Full validation will happen during restore...
                // @see ImageRestoreService::validateArchive()
            } catch (\Throwable $e) {
                $invalidArchives[$archiveName] = $e->getMessage();
            }
        }

        return $invalidArchives;
    }

    private function confirmDestructiveOperation(array $archivePaths, bool $overwrite, SymfonyStyle $io): bool
    {
        $needsConfirmation = false;
        $reasons = [];

        // Check if overwrite mode is enabled
        if ($overwrite) {
            $needsConfirmation = true;
            $reasons[] = 'Overwrite mode enabled - existing images will be replaced';
        }

        // Check if restoring many archives
        if (count($archivePaths) > 5) {
            $needsConfirmation = true;
            $reasons[] = sprintf('Restoring %d archives', count($archivePaths));
        }

        // Check if any archive is very large (>500MB for images)
        $largeArchives = [];
        foreach ($archivePaths as $archivePath) {
            $size = filesize($archivePath) ?: 0;
            if ($size > 500 * 1024 * 1024) { // 500MB
                $largeArchives[] = basename($archivePath) . ' (' . $this->formatFileSize($size) . ')';
            }
        }

        if (!empty($largeArchives)) {
            $needsConfirmation = true;
            $reasons[] = 'Large archives detected: ' . implode(', ', $largeArchives);
        }

        // If no confirmation needed, proceed
        if (!$needsConfirmation) {
            return true;
        }

        // Show warning and ask for confirmation
        $io->warning('This operation may impact your Docker registry:');
        foreach ($reasons as $reason) {
            $io->text("  • {$reason}");
        }
        $io->newLine();

        return $io->confirm('Do you want to continue?', false);
    }
}
