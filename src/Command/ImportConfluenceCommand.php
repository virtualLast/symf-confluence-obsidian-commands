<?php

declare(strict_types=1);

namespace App\Command;

use App\Confluence\ConfluenceImporter;
use App\Obsidian\ObsidianWriter;
use Http\Client\Exception as HttpClientException;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

#[AsCommand(
    name: 'app:confluence:import',
    description: 'Import content from Confluence into Obsidian',
)]
final class ImportConfluenceCommand extends Command
{
    private const string PAGE_ARGUMENT = 'page';
    private const string DESTINATION_ARGUMENT = 'destination';
    private const string WITH_ATTACHMENTS_OPTION = 'with-attachments';

    public function __construct(
        private readonly ConfluenceImporter $confluenceImporter,
        private readonly ObsidianWriter $obsidianWriter,
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

        $pageId = filter_var(
            $pageArgument,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($pageId === false) {
            $io->error('The Confluence page ID is outside the supported integer range.');

            return Command::INVALID;
        }

        $destination = (string) $input->getArgument(
            self::DESTINATION_ARGUMENT,
        );
        $withAttachments = (bool) $input->getOption(
            self::WITH_ATTACHMENTS_OPTION,
        );

        try {
            $importedPage = $this->confluenceImporter->importPage(
                $pageId,
                $withAttachments,
            );
        } catch (\Exception|HttpClientException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        try {
            $filePath = $this->obsidianWriter->write(
                $importedPage,
                $destination,
            );
        } catch (IOExceptionInterface | InvalidArgumentException $exception) {
            $io->error(sprintf(
                'Unable to write the imported page: %s',
                $exception->getMessage(),
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Imported "%s" to "%s".',
            $importedPage->title,
            $filePath,
        ));

        return Command::SUCCESS;
    }
}
