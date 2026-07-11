<?php

namespace App\Command;

use App\Confluence\ConfluenceImporter;
use CloudPlayDev\ConfluenceClient\Exception\ConfluencePhpClientException;
use Http\Client\Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:confluence:import',
    description: 'Import content from confluence into obsidian',
)]
class ImportConfluenceCommand extends Command
{
    private const string PAGE_ARGUMENT = 'page';
    private const string OUTPUT_ARGUMENT = 'output';

    public function __construct(private readonly ConfluenceImporter $confluenceImporter)
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
        $page = $input->getArgument(self::PAGE_ARGUMENT);
        $outputLocation = $input->getArgument(self::OUTPUT_ARGUMENT);

        try {
            $pageContent = $this->confluenceImporter->importPage($page);
        } catch (Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln($pageContent->content);

        return Command::SUCCESS;
    }
}
