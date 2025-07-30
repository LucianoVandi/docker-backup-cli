<?php

declare(strict_types=1);

namespace DockerBackup\Command;

use DockerBackup\Service\ImageBackupService;
use DockerBackup\ValueObject\ImageBackupResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BackupImagesCommand extends Command
{
    public function __construct(
        private readonly ImageBackupService $imageBackupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $defaultDir = getcwd() . '/backups/images';

        $this->setName('backup:images')
            ->setDescription('Backup Docker images to tar.gz archives')
            ->addArgument(
                'images',
                InputArgument::IS_ARRAY,
                'Names or IDs of images to backup (e.g., nginx:latest, sha256:abc123...)'
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
                'List available images and exit'
            )
            ->setHelp(
                <<<'HELP'
The <info>%command.name%</info> command creates backups of Docker images.

<info>Examples:</info>

  # Backup specific images by name and tag
  <info>php %command.full_name% nginx:latest mysql:8.0 redis:alpine</info>

  # Backup image by ID
  <info>php %command.full_name% sha256:1234567890abcdef</info>

  # Backup with custom output directory
  <info>php %command.full_name% nginx:latest --output-dir=/tmp/backups</info>

  # Create uncompressed archives
  <info>php %command.full_name% nginx:latest --no-compression</info>

  # List available images
  <info>php %command.full_name% --list</info>

The command uses Docker's native save functionality to create portable image archives.
Compressed archives (.tar.gz) are created by default for space efficiency.
HELP
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Handle list option
        if ($input->getOption('list')) {
            return $this->listAvailableImages($io);
        }

        $imageReferences = $input->getArgument('images');
        $compress = !$input->getOption('no-compression');

        // Check if images argument is provided
        if (empty($imageReferences)) {
            $io->error('You must specify at least one image name or ID, or use --list to see available images.');
            $io->text('Usage: backup:images nginx:latest [mysql:8.0 ...]');
            $io->text('   or: backup:images --list');

            return Command::FAILURE;
        }

        $outputDir = $input->getOption('output-dir');

        $io->title('Docker Image Backup');
        $io->text("Backing up images to: <info>{$outputDir}</info>");

        // Validate images exist
        $availableImages = $this->imageBackupService->getAvailableImages();
        $availableImageRefs = $this->extractImageReferences($availableImages);

        $invalidImages = $this->findInvalidImageReferences($imageReferences, $availableImageRefs);
        if (!empty($invalidImages)) {
            $io->error('The following images do not exist:');
            foreach ($invalidImages as $invalid) {
                $io->text("  - {$invalid}");
            }
            $io->newLine();
            $io->text('💡 Tip: Use --list to see all available images');

            return Command::FAILURE;
        }

        // Perform backups
        $io->writeln(sprintf('Starting backup of <info>%d</info> image(s)...', count($imageReferences)));
        $io->newLine();

        $results = $this->performBackupsWithProgress($imageReferences, $outputDir, $compress, $io);

        $this->displaySummary($io, $results);

        // Return appropriate exit code
        $failedCount = count(array_filter($results, fn ($r) => $r->isFailed()));

        return $failedCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function listAvailableImages(SymfonyStyle $io): int
    {
        $images = $this->imageBackupService->getAvailableImages();

        if (empty($images)) {
            $io->warning('No Docker images found.');

            return Command::SUCCESS;
        }

        $io->title('Available Docker Images');

        $tableData = [];
        foreach ($images as $image) {
            $tags = empty($image->repoTags) ? ['<none>'] : $image->repoTags;

            foreach ($tags as $tag) {
                $tableData[] = [
                    $tag,
                    $image->getShortId(),
                    $image->getFormattedSize(),
                    $this->formatCreatedDate($image->created),
                ];
            }
        }

        $io->table(['Repository:Tag', 'Image ID', 'Size', 'Created'], $tableData);
        $io->text(sprintf('Total: <info>%d</info> images', count($images)));

        return Command::SUCCESS;
    }

    private function extractImageReferences(array $images): array
    {
        $references = [];

        foreach ($images as $image) {
            // Add image ID
            $references[] = $image->id;
            $references[] = $image->getShortId();

            // Add all repository tags
            foreach ($image->repoTags as $tag) {
                $references[] = $tag;
            }
        }

        return array_unique($references);
    }

    private function findInvalidImageReferences(array $requestedImages, array $availableRefs): array
    {
        $invalid = [];

        foreach ($requestedImages as $imageRef) {
            $found = false;

            // Check exact match first
            if (in_array($imageRef, $availableRefs, true)) {
                $found = true;
            } else {
                // Check if it's a partial ID match (at least 12 chars)
                if (strlen($imageRef) >= 12) {
                    foreach ($availableRefs as $availableRef) {
                        if (str_starts_with($availableRef, $imageRef)) {
                            $found = true;
                            break;
                        }
                    }
                }
            }

            if (!$found) {
                $invalid[] = $imageRef;
            }
        }

        return $invalid;
    }

    private function performBackupsWithProgress(array $imageReferences, string $outputDir, bool $compress, SymfonyStyle $io): array
    {
        $results = [];
        $totalCount = count($imageReferences);

        foreach ($imageReferences as $index => $imageReference) {
            $currentIndex = $index + 1;

            // Show what we're doing
            $io->write(sprintf('[%d/%d] 💾 Backing up <info>%s</info>... ', $currentIndex, $totalCount, $imageReference));

            // Perform the backup with timing
            $startTime = microtime(true);
            $result = $this->imageBackupService->backupSingleImage($imageReference, $outputDir, $compress);
            $duration = round(microtime(true) - $startTime, 2);

            // Clear the line and show result
            $this->clearCurrentLine($io);
            $this->displayImageResult($io, $currentIndex, $totalCount, $imageReference, $result, $duration);

            $results[] = $result;
        }

        return $results;
    }

    private function displayImageResult(
        SymfonyStyle $io,
        int $currentIndex,
        int $totalCount,
        string $imageReference,
        ImageBackupResult $result,
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
            $imageReference,
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
        $successCount = count(array_filter($results, fn (ImageBackupResult $r) => $r->isSuccessful()));
        $failedCount = count(array_filter($results, fn (ImageBackupResult $r) => $r->isFailed()));
        $skippedCount = count(array_filter($results, fn (ImageBackupResult $r) => $r->isSkipped()));

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

    /**
     * @throws \Exception
     */
    private function formatCreatedDate(int $timestamp): string
    {
        if ($timestamp === 0) {
            return 'Unknown';
        }

        $date = new \DateTimeImmutable('@' . $timestamp);
        $now = new \DateTimeImmutable();
        $diff = $now->diff($date);

        if ($diff->days === 0) {
            return 'Today';
        } elseif ($diff->days === 1) {
            return 'Yesterday';
        } elseif ($diff->days < 30) {
            return $diff->days . ' days ago';
        } else {
            return $date->format('M j, Y');
        }
    }
}