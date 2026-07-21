<?php

declare(strict_types=1);

namespace App\Command;

use App\Confluence\ConfluencePageImportPipelineInterface;
use App\Confluence\Traversal\ConfluenceImportTraversal;
use App\Confluence\Traversal\ConfluenceTraversalOptions;
use App\Confluence\Traversal\ConfluenceTraversalResult;
use App\Confluence\Traversal\PageDiscoverySource;
use Http\Client\Exception as HttpClientException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:confluence:import',
    description: 'Import content from Confluence into Obsidian',
)]
final class ImportConfluenceCommand extends Command
{
    private const string PAGE_ARGUMENT = 'page';
    private const string DESTINATION_ARGUMENT = 'destination';
    private const string WITH_ATTACHMENTS_OPTION = 'with-attachments';
    private const string RECURSIVE_OPTION = 'recursive';
    private const string FOLLOW_LINKS_OPTION = 'follow-links';
    private const string DEPTH_OPTION = 'depth';

    public function __construct(
        private readonly ConfluencePageImportPipelineInterface $importPipeline,
        private readonly ConfluenceImportTraversal $importTraversal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                self::PAGE_ARGUMENT,
                InputArgument::REQUIRED,
                'Confluence page ID to import',
            )
            ->addArgument(
                self::DESTINATION_ARGUMENT,
                InputArgument::OPTIONAL,
                'Destination directory relative to the Obsidian vault',
                '',
            )
            ->addOption(
                self::WITH_ATTACHMENTS_OPTION,
                null,
                InputOption::VALUE_NONE,
                'Import attachments for the Confluence page',
            )
            ->addOption(
                self::RECURSIVE_OPTION,
                null,
                InputOption::VALUE_NONE,
                'Follow the Confluence child-page hierarchy',
            )
            ->addOption(
                self::FOLLOW_LINKS_OPTION,
                null,
                InputOption::VALUE_NONE,
                'Follow linked Confluence pages discovered during import',
            )
            ->addOption(
                self::DEPTH_OPTION,
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum traversal depth from the requested root page',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $pageArgument = (string) $input->getArgument(self::PAGE_ARGUMENT);

        if (preg_match('/^[1-9]\d*$/', $pageArgument) !== 1) {
            $io->error('The Confluence page ID must be a positive integer.');

            return Command::INVALID;
        }

        $destination = (string) $input->getArgument(
            self::DESTINATION_ARGUMENT,
        );
        $withAttachments = (bool) $input->getOption(
            self::WITH_ATTACHMENTS_OPTION,
        );
        $followChildren = (bool) $input->getOption(self::RECURSIVE_OPTION);
        $followLinks = (bool) $input->getOption(self::FOLLOW_LINKS_OPTION);
        $depthOption = $input->getOption(self::DEPTH_OPTION);

        if ($depthOption !== null && !$followChildren && !$followLinks) {
            $io->error('The --depth option requires --recursive or --follow-links.');

            return Command::INVALID;
        }

        $maximumDepth = null;

        if ($depthOption !== null) {
            $depth = (string) $depthOption;

            if (preg_match('/^\d+$/', $depth) !== 1) {
                $io->error('The traversal depth must be a non-negative integer.');

                return Command::INVALID;
            }

            $normalisedDepth = ltrim($depth, '0');
            $normalisedDepth = $normalisedDepth === '' ? '0' : $normalisedDepth;
            $maximumInteger = (string) PHP_INT_MAX;

            if (
                strlen($normalisedDepth) > strlen($maximumInteger)
                || (
                    strlen($normalisedDepth) === strlen($maximumInteger)
                    && strcmp($normalisedDepth, $maximumInteger) > 0
                )
            ) {
                $io->error('The traversal depth is outside the supported integer range.');

                return Command::INVALID;
            }

            $maximumDepth = (int) $normalisedDepth;
        }

        if (!$followChildren && !$followLinks) {
            return $this->importSinglePage(
                $io,
                $pageArgument,
                $destination,
                $withAttachments,
            );
        }

        try {
            $result = $this->importTraversal->traverse(
                $pageArgument,
                new ConfluenceTraversalOptions(
                    $followChildren,
                    $followLinks,
                    $maximumDepth,
                ),
                $destination,
                $withAttachments,
            );
        } catch (\Exception|HttpClientException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->renderTraversalDetails($io, $result);
        $this->renderTraversalSummary(
            $io,
            $pageArgument,
            $followChildren,
            $followLinks,
            $maximumDepth,
            $result,
        );

        return $result->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }

    private function importSinglePage(
        SymfonyStyle $io,
        string $pageId,
        string $destination,
        bool $withAttachments,
    ): int {
        try {
            $completedImport = $this->importPipeline->import(
                $pageId,
                $destination,
                $withAttachments,
            );
        } catch (\Exception|HttpClientException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Imported "%s" to "%s".',
            $completedImport->page->title,
            $completedImport->filePath,
        ));

        return Command::SUCCESS;
    }

    private function renderTraversalDetails(
        SymfonyStyle $io,
        ConfluenceTraversalResult $result,
    ): void {
        if ($result->rootFailed) {
            $failure = $result->failures[0];
            $io->error(sprintf(
                'Root page %s failed: %s',
                $failure->page->pageId,
                $failure->message,
            ));
        } elseif ($result->hasFailures()) {
            $io->warning(sprintf(
                '%d page import(s) and %d child discovery operation(s) failed.',
                $result->pagesFailed(),
                $result->childDiscoveryFailures(),
            ));
        }

        if (!$io->isVerbose()) {
            return;
        }

        foreach ($result->importedPages as $imported) {
            $page = $imported->queuedPage;
            $io->writeln(sprintf(
                'Imported page %s "%s" at depth %d via %s%s',
                $page->pageId,
                $imported->completedImport->page->title,
                $page->depth,
                $page->source->value,
                $page->referringPageId === null ? '' : ' from ' . $page->referringPageId,
            ));
        }

        foreach ($result->duplicates as $duplicate) {
            $page = $duplicate->page;
            $io->writeln(sprintf(
                'Duplicate discovery %s%s at depth %d via %s%s',
                $page->pageId,
                $page->knownTitle === null ? '' : sprintf(' "%s"', $page->knownTitle),
                $page->depth,
                $page->source->value,
                $page->referringPageId === null ? '' : ' from ' . $page->referringPageId,
            ));
        }

        foreach ($result->failures as $failure) {
            $page = $failure->page;
            $io->writeln(sprintf(
                '%s failure for page %s%s at depth %d via %s%s: %s',
                $failure->type->value,
                $page->pageId,
                $page->knownTitle === null ? '' : sprintf(' "%s"', $page->knownTitle),
                $page->depth,
                $page->source->value,
                $page->referringPageId === null ? '' : ' from ' . $page->referringPageId,
                $failure->message,
            ));
        }
    }

    private function renderTraversalSummary(
        SymfonyStyle $io,
        string $rootPageId,
        bool $followChildren,
        bool $followLinks,
        ?int $maximumDepth,
        ConfluenceTraversalResult $result,
    ): void {
        $io->title('Recursive Confluence import complete');
        $io->definitionList(
            ['Root page' => $rootPageId],
            ['Follow child pages' => $followChildren ? 'yes' : 'no'],
            ['Follow linked pages' => $followLinks ? 'yes' : 'no'],
            ['Maximum depth' => $maximumDepth === null ? 'unlimited' : (string) $maximumDepth],
            ['Pages scheduled' => (string) $result->pagesScheduled],
            ['Pages imported' => (string) $result->pagesImported()],
            ['Pages failed' => (string) $result->pagesFailed()],
            ['Duplicate discoveries' => (string) count($result->duplicates)],
            ['Child pages found' => (string) $result->childPagesFound],
            ['Linked pages found' => (string) $result->linkedPagesFound],
            ['Maximum depth reached' => (string) $result->maximumDepthReached],
            ['Child discovery failures' => (string) $result->childDiscoveryFailures()],
        );
    }

}
