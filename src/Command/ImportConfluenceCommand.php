<?php

declare(strict_types=1);

namespace App\Command;

use App\Confluence\Traversal\ConfluenceImportTraversal;
use App\Confluence\Traversal\ConfluenceTraversalOptions;
use App\Confluence\Traversal\ConfluenceTraversalResult;
use App\Confluence\Traversal\PageDiscoverySource;
use App\Confluence\Traversal\TraversalFailureType;
use App\Model\ConfluenceId;
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

        if (!ConfluenceId::isValid($pageArgument)) {
            $io->error('The Confluence page ID must be a positive integer within the supported range.');

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
        } catch (\Exception $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->renderTraversalDetails($io, $result);
        $this->renderTraversalSummary($io, $result);

        return $result->hasFailures() ? Command::FAILURE : Command::SUCCESS;
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
                '[imported] %s (%s) — %s',
                $imported->completedImport->page->title,
                $page->pageId,
                $this->discoveryDescription($page->source, $page->referringPageId),
            ));
        }

        foreach ($result->duplicates as $duplicate) {
            $page = $duplicate->page;
            $io->writeln(sprintf(
                '[skipped] %s (%s) — already scheduled; %s',
                $page->knownTitle ?? $page->pageId,
                $page->pageId,
                $this->discoveryDescription($page->source, $page->referringPageId),
            ));
        }

        foreach ($result->failures as $failure) {
            $io->writeln(sprintf(
                '[failed] %s (%s) — %s%s; %s',
                $failure->page->knownTitle ?? $failure->page->pageId,
                $failure->page->pageId,
                $failure->type === TraversalFailureType::ChildDiscovery ? 'child discovery: ' : '',
                $failure->message,
                $this->discoveryDescription(
                    $failure->page->source,
                    $failure->page->referringPageId,
                ),
            ));
        }
    }

    private function renderTraversalSummary(
        SymfonyStyle $io,
        ConfluenceTraversalResult $result,
    ): void {
        $definitions = [
            ['Root pages' => (string) $result->rootPages],
            ['Child traversal (--recursive)' => $result->childTraversalEnabled ? 'enabled' : 'disabled'],
            ['Linked-page traversal (--follow-links)' => $result->linkedPageTraversalEnabled ? 'enabled' : 'disabled'],
            ['Maximum depth limit (--depth)' => $result->maximumDepthConfigured === null
                ? 'unlimited'
                : (string) $result->maximumDepthConfigured],
            ['Child relationships discovered' => (string) $result->childRelationshipsDiscovered],
            ['Linked-page relationships discovered' => (string) $result->linkedPageRelationshipsDiscovered],
            ['Unique pages scheduled' => (string) $result->uniquePagesScheduled],
            ['Pages imported' => (string) $result->pagesImported()],
            ['Pages failed' => (string) $result->pagesFailed()],
            ['Duplicate discoveries' => (string) $result->duplicateDiscoveries()],
            ['Maximum traversal depth reached' => (string) $result->maximumDepthReached],
            ['Child discovery failures' => (string) $result->childDiscoveryFailures()],
        ];

        if ($result->attachmentsRequested) {
            $definitions[] = ['Attachments downloaded' => (string) $result->attachmentsDownloaded];
        }

        $io->title('Confluence import complete');
        $io->definitionList(...$definitions);
    }

    private function discoveryDescription(
        PageDiscoverySource $source,
        ?string $referringPageId,
    ): string {
        return match ($source) {
            PageDiscoverySource::Root => 'root',
            PageDiscoverySource::Child => 'child of ' . $referringPageId,
            PageDiscoverySource::Linked => 'linked from ' . $referringPageId,
        };
    }
}
