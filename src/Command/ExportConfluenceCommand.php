<?php

declare(strict_types=1);

namespace App\Command;

use App\Confluence\Export\ConfluenceAttachmentPublisherInterface;
use App\Confluence\Export\ConfluenceExportGatewayInterface;
use App\Confluence\Export\ConfluencePageUrl;
use App\Confluence\Export\ExportContentConverter;
use App\Confluence\Export\ExportNoteReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:confluence:export', description: 'Preview or publish an imported Obsidian note to its original Confluence page')]
final class ExportConfluenceCommand extends Command
{
    public function __construct(
        private readonly ExportNoteReader $reader,
        private readonly ExportContentConverter $converter,
        private readonly ConfluenceExportGatewayInterface $gateway,
        private readonly ConfluencePageUrl $pageUrl,
        private readonly ConfluenceAttachmentPublisherInterface $attachments,
    ) {
        parent::__construct();
    }

    /** Define explicit publication and optional vault selection. */
    protected function configure(): void
    {
        $this->addArgument('note', InputArgument::REQUIRED, 'Markdown path, absolute or relative to the vault')
            ->addOption('vault', null, InputOption::VALUE_REQUIRED, 'Override OBSIDIAN_VAULT')
            ->addOption('publish', null, InputOption::VALUE_NONE, 'Replace the existing Confluence page body');
    }

    /** Validate locally, display the complete replacement, then optionally publish once. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $attachmentWritesStarted = false;
        try {
            $vault = $input->getOption('vault');
            $note = $this->reader->read((string) $input->getArgument('note'), is_string($vault) ? $vault : null);
            $converted = $this->converter->convert($note);
            $page = $this->gateway->getPage($note->pageId);
            $url = $this->pageUrl->forPage($page->id);
            $io->title('Confluence export');
            $io->text(OutputFormatter::escape(sprintf("Destination: %s\nTitle: %s\nCurrent version: %d", $url, $page->title, $page->version)));
            $io->warning('The local body replaces all remote content, including direct Confluence edits. Referenced local images are uploaded; unused attachments remain.');
            foreach ($converted->warnings as $warning) {
                $io->warning(OutputFormatter::escape($warning));
            }
            foreach ($converted->images as $image) {
                $io->text(OutputFormatter::escape(sprintf('Upload/update image: %s -> %s (%d bytes)', $image->vaultPath, $image->filename, strlen($image->content))));
            }
            if (preg_match('/<(?:ac:|ri:)/i', $page->storage) === 1) {
                $io->warning('The current page contains Confluence macros, layouts, or resource references. They will be replaced by the local body.');
            }
            $io->section('Before: current Confluence storage body');
            $output->writeln($page->storage, OutputInterface::OUTPUT_RAW);
            $io->section('After: proposed Confluence storage body');
            $output->writeln($converted->storage, OutputInterface::OUTPUT_RAW);
            if (!$input->getOption('publish')) {
                $io->success('Preview only. Run with --publish to replace the page body.');

                return Command::SUCCESS;
            }
            foreach ($converted->images as $image) {
                $attachmentWritesStarted = true;
                $this->attachments->publish($page->id, $image);
            }
            $version = $this->gateway->publish($page, $converted->storage);
            $io->success(OutputFormatter::escape(sprintf('Published version %d: %s', $version, $url)));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error(OutputFormatter::escape($exception->getMessage()));
            if ($attachmentWritesStarted) {
                $io->warning('Some image attachments may already have been updated. The page body was not confirmed published; inspect Confluence before retrying.');
            }

            return Command::FAILURE;
        }
    }
}
