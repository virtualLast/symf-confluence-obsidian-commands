<?php

declare(strict_types=1);

namespace App\Command;

use App\Confluence\FolderTraversal\ConfluenceFolderImportTraversal;
use App\Confluence\FolderTraversal\ConfluenceFolderTraversalResult;
use App\Confluence\FolderTraversal\FolderTraversalFailureType;
use App\Model\ConfluenceId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:confluence:import-folder',
    description: 'Import a Confluence folder hierarchy into Obsidian',
)]
final class ImportConfluenceFolderCommand extends Command
{
    private const string FOLDER_ARGUMENT = 'folder';
    private const string DESTINATION_ARGUMENT = 'destination';
    private const string WITH_ATTACHMENTS_OPTION = 'with-attachments';

    public function __construct(
        private readonly ConfluenceFolderImportTraversal $folderTraversal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                self::FOLDER_ARGUMENT,
                InputArgument::REQUIRED,
                'Confluence folder ID to import',
            )
            ->addArgument(
                self::DESTINATION_ARGUMENT,
                InputArgument::REQUIRED,
                'Destination directory relative to the Obsidian vault',
            )
            ->addOption(
                self::WITH_ATTACHMENTS_OPTION,
                null,
                InputOption::VALUE_NONE,
                'Import attachments for every discovered Confluence page',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $folderId = (string) $input->getArgument(self::FOLDER_ARGUMENT);

        if (!ConfluenceId::isValid($folderId)) {
            $io->error('The Confluence folder ID must be a positive integer within the supported range.');

            return Command::INVALID;
        }

        try {
            $result = $this->folderTraversal->traverse(
                $folderId,
                (string) $input->getArgument(self::DESTINATION_ARGUMENT),
                (bool) $input->getOption(self::WITH_ATTACHMENTS_OPTION),
            );
        } catch (\Exception $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->renderDetails($io, $result);
        $this->renderSummary($io, $result);

        return $result->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }

    private function renderDetails(
        SymfonyStyle $io,
        ConfluenceFolderTraversalResult $result,
    ): void {
        if ($result->hasFailures()) {
            $folderFailures = count(array_filter(
                $result->failures,
                static fn($failure): bool => $failure->type === FolderTraversalFailureType::FolderRetrieval,
            ));
            $io->warning(sprintf(
                '%d page import(s), %d folder retrieval(s), and %d page child discovery operation(s) failed.',
                $result->pagesFailed(),
                $folderFailures,
                $result->pageChildDiscoveryFailures(),
            ));
        }

        if (!$io->isVerbose()) {
            return;
        }

        foreach ($result->visitedFolders as $visited) {
            $io->writeln(sprintf(
                '[folder] %s (%s) — depth %d; %s',
                $visited->folder->title,
                $visited->folder->id,
                $visited->queuedFolder->depth,
                $visited->destination,
            ));
        }

        foreach ($result->importedPages as $imported) {
            $io->writeln(sprintf(
                '[imported] %s (%s) — %s',
                $imported->completedImport->page->title,
                $imported->page->id,
                $imported->destination,
            ));
        }

        foreach ($result->duplicates as $duplicate) {
            $io->writeln(sprintf(
                '[skipped] %s (%s) — already scheduled; %s',
                $duplicate->page->title,
                $duplicate->page->id,
                $duplicate->destination,
            ));
        }

        foreach ($result->failures as $failure) {
            $io->writeln(sprintf(
                '[failed] %s (%s) — %s: %s; %s',
                $failure->knownTitle ?? 'Folder',
                $failure->contentId,
                match ($failure->type) {
                    FolderTraversalFailureType::FolderRetrieval => 'folder retrieval',
                    FolderTraversalFailureType::PageImport => 'page import',
                    FolderTraversalFailureType::PageChildDiscovery => 'page child discovery',
                },
                $failure->message,
                $failure->destination,
            ));
        }
    }

    private function renderSummary(
        SymfonyStyle $io,
        ConfluenceFolderTraversalResult $result,
    ): void {
        $io->title('Confluence folder import complete');
        $io->definitionList(
            ['Folders visited' => (string) $result->foldersVisited],
            ['Pages discovered' => (string) $result->pagesDiscovered],
            ['Pages imported' => (string) $result->pagesImported()],
            ['Pages failed' => (string) $result->pagesFailed()],
            ['Duplicate pages' => (string) $result->duplicatePages()],
            ['Maximum folder depth' => (string) $result->maximumFolderDepth],
            ['Attachments downloaded' => (string) $result->attachmentsDownloaded],
            ['Page child discovery failures' => (string) $result->pageChildDiscoveryFailures()],
        );
    }
}
