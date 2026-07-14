<?php

namespace App\Command;

use App\Confluence\ConfluenceImporter;
use App\Obsidian\ObsidianWriter;
use CloudPlayDev\ConfluenceClient\Exception\ConfluencePhpClientException;
use Http\Client\Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

#[AsCommand(
    name: 'app:confluence:import',
    description: 'Import content from confluence into obsidian',
)]
class ImportConfluenceCommand extends Command
{
    private const string PAGE_ARGUMENT = 'page';
    private const string OUTPUT_ARGUMENT = 'output';

    public function __construct(
        private readonly ConfluenceImporter $confluenceImporter,
        private readonly ObsidianWriter $obsidianWriter,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(self::PAGE_ARGUMENT, InputArgument::REQUIRED, 'page to import')
            ->addArgument(self::OUTPUT_ARGUMENT, InputArgument::REQUIRED, 'where to put putput, added to Obsidian vault location')
        ;
    }

    /**
     * @throws Exception
     * @throws ConfluencePhpClientException
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageId = $input->getArgument(self::PAGE_ARGUMENT);

        try {
            $importedPage = $this->confluenceImporter->importPage($pageId);
        } catch (Exception $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        try {
            $filePath = $this->obsidianWriter->write($importedPage);
        } catch (IOExceptionInterface $exception) {
            $io->error(sprintf(
                'Unable to write the imported page: %s',
                $exception->getMessage(),
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Imported "%s" to %s',
            $importedPage->title,
            $filePath,
        ));

        return Command::SUCCESS;
    }
}
