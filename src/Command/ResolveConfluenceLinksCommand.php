<?php

declare(strict_types=1);

namespace App\Command;

use App\Obsidian\Index\ConfluenceNotesDirectoryResolver;
use App\Obsidian\Index\ConfluencePageIndexBuilder;
use App\Obsidian\Index\ConfluencePageIndexResult;
use App\Obsidian\Link\ConfluenceLinkResolver;
use App\Obsidian\Link\UnresolvedConfluenceRelationship;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:obsidian:resolve-confluence-links',
    description: 'Resolve links between imported Confluence notes',
)]
final class ResolveConfluenceLinksCommand extends Command
{
    private const string DIRECTORY_ARGUMENT = 'directory';
    private const string DEFAULT_DIRECTORY = 'Inbox/Confluence';

    public function __construct(
        private readonly ConfluenceNotesDirectoryResolver $directoryResolver,
        private readonly ConfluencePageIndexBuilder $indexBuilder,
        private readonly ConfluenceLinkResolver $linkResolver,
        private readonly Filesystem $filesystem,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            self::DIRECTORY_ARGUMENT,
            InputArgument::OPTIONAL,
            'Directory relative to the Obsidian vault containing imported Confluence notes',
            self::DEFAULT_DIRECTORY,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directoryArgument = (string) $input->getArgument(self::DIRECTORY_ARGUMENT);

        try {
            $directory = $this->directoryResolver->resolve($directoryArgument);
            $indexResult = $this->indexBuilder->build($directory);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $duplicateCount = count($indexResult->index->duplicates());

        if ($duplicateCount > 0) {
            $io->error('Duplicate Confluence page IDs make link resolution ambiguous. No files were changed.');
            $this->renderDetails($io, $indexResult, [], []);
            $this->renderSummary($io, $directory->relativePath, $indexResult, 0, 0, 0, 0);

            return Command::FAILURE;
        }

        $relationshipsChecked = 0;
        $linksResolved = 0;
        $unresolved = [];
        $updatedFiles = [];

        foreach ($indexResult->index->pages() as $page) {
            $content = @file_get_contents($page->absolutePath);

            if ($content === false) {
                $io->error(sprintf('Unable to read indexed note "%s".', $page->vaultPath));

                return Command::FAILURE;
            }

            try {
                $result = $this->linkResolver->resolve($page, $indexResult->index, $content);
            } catch (\UnexpectedValueException $exception) {
                $io->error(sprintf(
                    'Unable to resolve "%s": %s',
                    $page->vaultPath,
                    $exception->getMessage(),
                ));

                return Command::FAILURE;
            }

            $relationshipsChecked += $result->relationshipsChecked;
            $linksResolved += $result->linksResolved;
            array_push($unresolved, ...$result->unresolved);

            if (!$result->changed) {
                continue;
            }

            try {
                $this->filesystem->dumpFile($page->absolutePath, $result->content);
            } catch (IOExceptionInterface $exception) {
                $io->error(sprintf(
                    'Unable to write resolved note "%s": %s',
                    $page->vaultPath,
                    $exception->getMessage(),
                ));

                return Command::FAILURE;
            }

            $updatedFiles[] = $page->vaultPath;
        }

        $this->renderDetails($io, $indexResult, $unresolved, $updatedFiles);
        $this->renderSummary(
            $io,
            $directory->relativePath,
            $indexResult,
            $relationshipsChecked,
            $linksResolved,
            count($unresolved),
            count($updatedFiles),
        );

        return Command::SUCCESS;
    }

    /**
     * @param list<UnresolvedConfluenceRelationship> $unresolved
     * @param list<string> $updatedFiles
     */
    private function renderDetails(
        SymfonyStyle $io,
        ConfluencePageIndexResult $indexResult,
        array $unresolved,
        array $updatedFiles,
    ): void {
        if (!$io->isVerbose()) {
            return;
        }

        foreach ($indexResult->invalidNotes as $path => $reason) {
            $io->writeln(sprintf('Invalid note: %s — %s', $path, $reason));
        }

        foreach ($indexResult->index->duplicates() as $duplicate) {
            $io->writeln(sprintf(
                'Duplicate page ID %s: %s',
                $duplicate->pageId,
                implode(', ', $duplicate->paths),
            ));
        }

        foreach ($unresolved as $relationship) {
            $io->writeln(sprintf(
                'Unresolved page ID %s referenced by %s',
                $relationship->pageId,
                $relationship->sourcePath,
            ));
        }

        foreach ($updatedFiles as $path) {
            $io->writeln(sprintf('Updated file: %s', $path));
        }
    }

    private function renderSummary(
        SymfonyStyle $io,
        string $directory,
        ConfluencePageIndexResult $indexResult,
        int $relationshipsChecked,
        int $linksResolved,
        int $linksUnresolved,
        int $filesUpdated,
    ): void {
        $io->title('Confluence link resolution complete');
        $io->definitionList(
            ['Directory' => $directory],
            ['Notes discovered' => (string) $indexResult->notesDiscovered],
            ['Notes indexed' => (string) $indexResult->notesIndexed],
            ['Notes skipped' => (string) $indexResult->notesSkipped],
            ['Relationships checked' => (string) $relationshipsChecked],
            ['Links resolved' => (string) $linksResolved],
            ['Links unresolved' => (string) $linksUnresolved],
            ['Files updated' => (string) $filesUpdated],
            ['Duplicate page IDs' => (string) count($indexResult->index->duplicates())],
            ['Invalid notes' => (string) count($indexResult->invalidNotes)],
        );
    }
}
