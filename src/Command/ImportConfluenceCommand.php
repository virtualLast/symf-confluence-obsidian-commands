<?php

namespace App\Command;

use App\Confluence\ConfluenceImporter;
use App\Obsidian\ObsidianWriter;
use Http\Client\Exception as HttpClientException;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $pageId = (int) $input->getArgument(self::PAGE_ARGUMENT);
        $destination = (string) $input->getArgument(
            self::DESTINATION_ARGUMENT,
        );

        try {
            $importedPage = $this->confluenceImporter->importPage($pageId);
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
