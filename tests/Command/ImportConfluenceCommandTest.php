<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ImportConfluenceCommand;
use App\Confluence\ConfluenceGatewayInterface;
use App\Confluence\ConfluenceImporter;
use App\Confluence\ConfluencePageImportPipeline;
use App\Confluence\Traversal\ConfluenceImportTraversal;
use App\Model\ConfluencePageReference;
use App\Model\ImportedAttachment;
use App\Model\ImportedLinkedPage;
use App\Model\ImportedPage;
use App\Obsidian\ObsidianAttachmentPlaceholderRewriter;
use App\Obsidian\ObsidianAttachmentWriter;
use App\Obsidian\ObsidianFrontmatterRenderer;
use App\Obsidian\ObsidianImageLinkBuilder;
use App\Obsidian\ObsidianImageLinkBuilderInterface;
use App\Obsidian\ObsidianPageWriter;
use App\Obsidian\ObsidianPathBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ImportConfluenceCommandTest extends TestCase
{
    private string $vaultPath;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();

        $this->vaultPath = sprintf(
            '%s/import-confluence-command-test-%s',
            sys_get_temp_dir(),
            bin2hex(random_bytes(8)),
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->vaultPath);
    }

    /**
     * What: The command imports a Confluence page into the vault root.
     * How: A mocked importer returns a page, then the command's file and output are inspected.
     * Why: The primary command path must persist the requested page and report its location.
     */
    public function testItImportsAPageIntoTheVault(): void
    {
        $importedPage = new ImportedPage(
            id: '123',
            title: 'SSO Overview',
            content: "# SSO Overview\n\nImported content.",
        );

        $confluenceImporter = $this->createMock(
            ConfluenceImporter::class,
        );

        $confluenceImporter
            ->expects(self::once())
            ->method('importPage')
            ->with('123', false)
            ->willReturn($importedPage);

        $imageLinkBuilder = $this->createMock(
            ObsidianImageLinkBuilderInterface::class,
        );
        $imageLinkBuilder
            ->expects(self::never())
            ->method('build');

        $commandTester = $this->createCommandTester(
            $confluenceImporter,
            $imageLinkBuilder,
        );

        $exitCode = $commandTester->execute([
            'page' => '123',
        ]);

        $expectedFilePath = $this->vaultPath
            . DIRECTORY_SEPARATOR
            . 'sso-overview.md';

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($expectedFilePath);

        self::assertSame(
            new ObsidianFrontmatterRenderer()->render($importedPage),
            file_get_contents($expectedFilePath),
        );

        self::assertStringContainsString(
            'Confluence import complete',
            $commandTester->getDisplay(),
        );
        self::assertMatchesRegularExpression('/Root pages\s+1/', $commandTester->getDisplay());
        self::assertMatchesRegularExpression('/Unique pages scheduled\s+1/', $commandTester->getDisplay());
    }

    /**
     * What: The command requests attachments when the option is supplied.
     * How: The importer expectation verifies the option is forwarded as true.
     * Why: Attachment fetching should be opt-in at the command boundary.
     */
    public function testItImportsAPageWithAttachments(): void
    {
        $importedPage = new ImportedPage(
            id: '123',
            title: 'SSO Overview',
            content: "# SSO Overview\n\n@@OBSIDIAN_ATTACHMENT:att456@@",
            attachments: [
                new ImportedAttachment(
                    id: '456',
                    filename: 'architecture.png',
                    content: "\x89PNG\r\n\x1a\n\x00binary-content",
                ),
            ],
        );

        $confluenceImporter = $this->createMock(
            ConfluenceImporter::class,
        );

        $confluenceImporter
            ->expects(self::once())
            ->method('importPage')
            ->with('123', true)
            ->willReturn($importedPage);

        $imageLinkBuilder = $this->createMock(
            ObsidianImageLinkBuilderInterface::class,
        );
        $imageLinkBuilder
            ->expects(self::once())
            ->method('build')
            ->with('Attachments/Confluence/sso-overview/architecture.png')
            ->willReturn('![[Attachments/Confluence/sso-overview/architecture.png]]');

        $commandTester = $this->createCommandTester(
            $confluenceImporter,
            $imageLinkBuilder,
        );

        $exitCode = $commandTester->execute([
            'page' => '123',
            '--with-attachments' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            $importedPage->attachments[0]->content,
            file_get_contents(
                $this->vaultPath
                . DIRECTORY_SEPARATOR
                . 'Attachments'
                . DIRECTORY_SEPARATOR
                . 'Confluence'
                . DIRECTORY_SEPARATOR
                . 'sso-overview'
                . DIRECTORY_SEPARATOR
                . 'architecture.png',
            ),
        );
        self::assertSame(
            new ObsidianFrontmatterRenderer()->render(new ImportedPage(
                id: $importedPage->id,
                title: $importedPage->title,
                content: "# SSO Overview\n\n![[Attachments/Confluence/sso-overview/architecture.png]]",
                attachments: $importedPage->attachments,
            )),
            file_get_contents(
                $this->vaultPath
                . DIRECTORY_SEPARATOR
                . 'sso-overview.md',
            ),
        );
    }

    /**
     * What: The command honours a nested destination supplied by the user.
     * How: The command is executed with a destination and the resulting file path and content are checked.
     * Why: Users need to place imported pages into the correct area of an Obsidian vault.
     */
    public function testItImportsAPageIntoTheRequestedDestination(): void
    {
        $importedPage = new ImportedPage(
            id: '456',
            title: 'Authentication Architecture',
            content: '# Authentication Architecture',
        );

        $confluenceImporter = $this->createMock(
            ConfluenceImporter::class,
        );

        $confluenceImporter
            ->expects(self::once())
            ->method('importPage')
            ->with('456', false)
            ->willReturn($importedPage);

        $commandTester = $this->createCommandTester(
            $confluenceImporter,
        );

        $exitCode = $commandTester->execute([
            'page' => '456',
            'destination' => 'Areas/Engineering',
        ]);

        $expectedFilePath = implode(
            DIRECTORY_SEPARATOR,
            [
                $this->vaultPath,
                'Areas',
                'Engineering',
                'authentication-architecture.md',
            ],
        );

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($expectedFilePath);

        self::assertSame(
            new ObsidianFrontmatterRenderer()->render($importedPage),
            file_get_contents($expectedFilePath),
        );
    }

    /**
     * What: Attachments use a page-scoped directory independent of the note destination.
     * How: A page with an attachment is imported to a nested destination and both files are checked.
     * Why: Attachment storage is stable when a note is moved or imported elsewhere.
     */
    public function testItImportsAttachmentsIntoTheRequestedDestination(): void
    {
        $importedPage = new ImportedPage(
            id: '456',
            title: 'Authentication Architecture',
            content: '# Authentication Architecture',
            attachments: [
                new ImportedAttachment('789', 'design.pdf', 'PDF content'),
            ],
        );

        $confluenceImporter = $this->createMock(ConfluenceImporter::class);
        $confluenceImporter
            ->expects(self::once())
            ->method('importPage')
            ->with('456', true)
            ->willReturn($importedPage);

        $commandTester = $this->createCommandTester($confluenceImporter);

        $exitCode = $commandTester->execute([
            'page' => '456',
            'destination' => 'Areas/Engineering',
            '--with-attachments' => true,
        ]);

        $attachmentPath = implode(
            DIRECTORY_SEPARATOR,
            [
                $this->vaultPath,
                'Attachments',
                'Confluence',
                'authentication-architecture',
                'design.pdf',
            ],
        );

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($attachmentPath);
        self::assertSame('PDF content', file_get_contents($attachmentPath));
    }

    /**
     * What: Attachment write failures make the command fail with an actionable error.
     * How: The importer returns an unsafe attachment filename and the command output is inspected.
     * Why: A successful page write must not hide a failed or rejected attachment write.
     */
    public function testItReturnsFailureWhenAnAttachmentCannotBeWritten(): void
    {
        $importedPage = new ImportedPage(
            id: '123',
            title: 'SSO Overview',
            content: 'Page content.',
            attachments: [
                new ImportedAttachment('456', '../unsafe.txt', 'content'),
            ],
        );

        $confluenceImporter = $this->createMock(ConfluenceImporter::class);
        $confluenceImporter
            ->expects(self::once())
            ->method('importPage')
            ->with('123', true)
            ->willReturn($importedPage);

        $commandTester = $this->createCommandTester($confluenceImporter);

        $exitCode = $commandTester->execute([
            'page' => '123',
            '--with-attachments' => true,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString(
            'Unable to write an imported attachment',
            $commandTester->getDisplay(),
        );
    }

    /**
     * What: The command fails cleanly when Confluence cannot supply the requested page.
     * How: The importer mock throws an exception and the exit code and displayed error are asserted.
     * Why: Import failures must be visible to both users and scripts instead of appearing successful.
     */
    public function testItReturnsFailureWhenThePageCannotBeImported(): void
    {
        $confluenceImporter = $this->createMock(
            ConfluenceImporter::class,
        );

        $confluenceImporter
            ->expects(self::once())
            ->method('importPage')
            ->with('123', false)
            ->willThrowException(
                new RuntimeException('Unable to fetch Confluence page.'),
            );

        $commandTester = $this->createCommandTester(
            $confluenceImporter,
        );

        $exitCode = $commandTester->execute([
            'page' => '123',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);

        self::assertStringContainsString(
            'Unable to fetch Confluence page.',
            $commandTester->getDisplay(),
        );
    }

    /**
     * What: The command fails cleanly when the imported page cannot be written.
     * How: A file is placed where the vault directory should be, forcing the writer to fail.
     * Why: Filesystem errors must produce a non-zero result and an actionable message.
     */
    public function testItReturnsFailureWhenThePageCannotBeWritten(): void
    {
        /*
         * Creating a file at the configured vault path means Symfony cannot
         * create a Markdown file beneath it as though it were a directory.
         */
        $this->filesystem->dumpFile(
            $this->vaultPath,
            'This path is a file rather than a directory.',
        );

        $importedPage = new ImportedPage(
            id: '123',
            title: 'SSO Overview',
            content: 'Page content.',
        );

        $confluenceImporter = $this->createMock(
            ConfluenceImporter::class,
        );

        $confluenceImporter
            ->expects(self::once())
            ->method('importPage')
            ->with('123', false)
            ->willReturn($importedPage);

        $commandTester = $this->createCommandTester(
            $confluenceImporter,
        );

        $exitCode = $commandTester->execute([
            'page' => '123',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);

        self::assertStringContainsString(
            'Unable to write the imported page',
            $commandTester->getDisplay(),
        );
    }

    /**
     * What: The command rejects an unsafe destination before writing attachments.
     * How: An import with an attachment is directed through `..`, then the vault is inspected.
     * Why: Destination validation must happen before the command produces any imported files.
     */
    public function testItRejectsADestinationOutsideTheVault(): void
    {
        $importedPage = new ImportedPage(
            id: '123',
            title: 'SSO Overview',
            content: 'Page content.',
            attachments: [
                new ImportedAttachment('456', 'architecture.png', 'image'),
            ],
        );

        $confluenceImporter = $this->createMock(
            ConfluenceImporter::class,
        );
        $confluenceImporter
            ->expects(self::once())
            ->method('importPage')
            ->with('123', true)
            ->willReturn($importedPage);

        $commandTester = $this->createCommandTester(
            $confluenceImporter,
        );

        $exitCode = $commandTester->execute([
            'page' => '123',
            'destination' => '../outside',
            '--with-attachments' => true,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString(
            'path segment.',
            $commandTester->getDisplay(),
        );
        self::assertDirectoryDoesNotExist(
            $this->vaultPath . DIRECTORY_SEPARATOR . 'Attachments',
        );
    }

    /**
     * What: Invalid Confluence page IDs are rejected before an import is attempted.
     * How: Invalid argument shapes are executed and the importer is expected never to run.
     * Why: Mistyped identifiers must not become unintended or misleading Confluence requests.
     */
    #[DataProvider('invalidPageIdProvider')]
    public function testItRejectsInvalidPageIds(string $pageId): void
    {
        $confluenceImporter = $this->createMock(
            ConfluenceImporter::class,
        );

        $confluenceImporter
            ->expects(self::never())
            ->method('importPage');

        $commandTester = $this->createCommandTester(
            $confluenceImporter,
        );

        $exitCode = $commandTester->execute([
            'page' => $pageId,
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString(
            'must be a positive integer',
            $commandTester->getDisplay(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPageIdProvider(): iterable
    {
        yield 'non-numeric' => ['abc'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'surrounding whitespace' => [' 123 '];
        yield 'decimal' => ['1.5'];
        yield 'outside integer range' => ['999999999999999999999999'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidTraversalOptionProvider')]
    public function testItRejectsInvalidTraversalOptions(array $input, string $message): void
    {
        $importer = $this->createMock(ConfluenceImporter::class);
        $importer->expects(self::never())->method('importPage');

        $tester = $this->createCommandTester($importer);
        $exitCode = $tester->execute(['page' => '123', ...$input]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidTraversalOptionProvider(): iterable
    {
        yield 'depth without strategy' => [['--depth' => '2'], 'requires'];
        yield 'negative depth' => [['--recursive' => true, '--depth' => '-1'], 'non-negative integer'];
        yield 'non-integer depth' => [['--follow-links' => true, '--depth' => 'abc'], 'non-negative integer'];
        yield 'depth outside integer range' => [
            ['--recursive' => true, '--depth' => '999999999999999999999999'],
            'outside the supported integer range',
        ];
    }

    public function testDepthZeroImportsOnlyTheRootAndPrintsATraversalSummary(): void
    {
        $importer = $this->createMock(ConfluenceImporter::class);
        $importer
            ->expects(self::once())
            ->method('importPage')
            ->with('123', false)
            ->willReturn(new ImportedPage('123', 'Root', 'Content'));
        $client = $this->createMock(ConfluenceGatewayInterface::class);
        $client->expects(self::never())->method('getChildPages');

        $tester = $this->createCommandTester($importer, confluenceGateway: $client);
        $exitCode = $tester->execute([
            'page' => '123',
            '--recursive' => true,
            '--depth' => '0',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Confluence import complete', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Unique pages scheduled\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Maximum depth limit \\(--depth\\)\s+0/', $tester->getDisplay());
    }

    public function testItImportsDirectChildrenIntoTheSameDestinationWithAttachments(): void
    {
        $importer = $this->createMock(ConfluenceImporter::class);
        $importer
            ->expects(self::exactly(2))
            ->method('importPage')
            ->willReturnCallback(static fn(string $pageId, bool $withAttachments): ImportedPage => new ImportedPage(
                $pageId,
                $pageId === '1' ? 'Root' : 'Child',
                'Content',
                attachments: $withAttachments ? [new ImportedAttachment('att', $pageId . '.txt', 'file')] : [],
            ));
        $client = $this->createMock(ConfluenceGatewayInterface::class);
        $client
            ->expects(self::once())
            ->method('getChildPages')
            ->with('1')
            ->willReturn([new ConfluencePageReference('2', 'Child')]);

        $tester = $this->createCommandTester($importer, confluenceGateway: $client);
        $exitCode = $tester->execute([
            'page' => '1',
            'destination' => 'Inbox/Confluence',
            '--with-attachments' => true,
            '--recursive' => true,
            '--depth' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($this->vaultPath . '/Inbox/Confluence/root.md');
        self::assertFileExists($this->vaultPath . '/Inbox/Confluence/child.md');
        self::assertFileExists($this->vaultPath . '/Attachments/Confluence/root/1.txt');
        self::assertFileExists($this->vaultPath . '/Attachments/Confluence/child/2.txt');
        self::assertMatchesRegularExpression('/Pages imported\s+2/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Child relationships discovered\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Attachments downloaded\s+2/', $tester->getDisplay());
    }

    public function testItImportsDirectlyLinkedPagesWhenRequested(): void
    {
        $importer = $this->createMock(ConfluenceImporter::class);
        $importer
            ->expects(self::exactly(2))
            ->method('importPage')
            ->willReturnCallback(static fn(string $pageId): ImportedPage => $pageId === '1'
                ? new ImportedPage('1', 'Root', 'Content', linkedPages: [
                    new ImportedLinkedPage('2', 'Linked', 'https://example.atlassian.net/wiki/pages/2'),
                ])
                : new ImportedPage('2', 'Linked', 'Content'));
        $client = $this->createMock(ConfluenceGatewayInterface::class);
        $client->expects(self::never())->method('getChildPages');

        $tester = $this->createCommandTester($importer, confluenceGateway: $client);
        $exitCode = $tester->execute([
            'page' => '1',
            '--follow-links' => true,
            '--depth' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertMatchesRegularExpression('/Linked-page relationships discovered\s+1/', $tester->getDisplay());
        self::assertFileExists($this->vaultPath . '/linked.md');
    }

    public function testADescendantFailureReturnsFailureAfterIndependentPagesComplete(): void
    {
        $importer = $this->createStub(ConfluenceImporter::class);
        $importer
            ->method('importPage')
            ->willReturnCallback(static function (string $pageId): ImportedPage {
                if ($pageId === '2') {
                    throw new RuntimeException('Page unavailable.');
                }

                return $pageId === '1'
                    ? new ImportedPage('1', 'Root', 'Content', linkedPages: [
                        new ImportedLinkedPage('2', 'Failed', 'url-2'),
                        new ImportedLinkedPage('3', 'Independent', 'url-3'),
                    ])
                    : new ImportedPage('3', 'Independent', 'Content');
            });

        $tester = $this->createCommandTester($importer);
        $exitCode = $tester->execute(
            [
                'page' => '1',
                '--follow-links' => true,
                '--depth' => '1',
            ],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE],
        );

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFileExists($this->vaultPath . '/independent.md');
        self::assertMatchesRegularExpression('/Pages failed\s+1/', $tester->getDisplay());
        self::assertStringContainsString('[failed] Failed (2) — Page unavailable.; linked from 1', $tester->getDisplay());
    }

    public function testVerboseCombinedTraversalReportsDiscoveryOriginsAndDuplicates(): void
    {
        $importer = $this->createMock(ConfluenceImporter::class);
        $importer
            ->expects(self::exactly(3))
            ->method('importPage')
            ->willReturnCallback(static fn(string $pageId): ImportedPage => match ($pageId) {
                '1' => new ImportedPage('1', 'Root', 'Content', linkedPages: [
                    new ImportedLinkedPage('3', 'Linked', 'url-3'),
                ]),
                '2' => new ImportedPage('2', 'Child', 'Content', linkedPages: [
                    new ImportedLinkedPage('3', 'Linked', 'url-3'),
                ]),
                '3' => new ImportedPage('3', 'Linked', 'Content'),
                default => throw new RuntimeException('Unexpected page ID ' . $pageId),
            });
        $client = $this->createMock(ConfluenceGatewayInterface::class);
        $client
            ->expects(self::exactly(3))
            ->method('getChildPages')
            ->willReturnCallback(static fn(string $pageId): array => $pageId === '1'
                ? [new ConfluencePageReference('2', 'Child')]
                : []);

        $tester = $this->createCommandTester($importer, confluenceGateway: $client);
        $exitCode = $tester->execute(
            [
                'page' => '1',
                '--recursive' => true,
                '--follow-links' => true,
            ],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE],
        );

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('[imported] Root (1) — root', $tester->getDisplay());
        self::assertStringContainsString('[imported] Child (2) — child of 1', $tester->getDisplay());
        self::assertStringContainsString('[imported] Linked (3) — linked from 1', $tester->getDisplay());
        self::assertStringContainsString(
            '[skipped] Linked (3) — already scheduled; linked from 2',
            $tester->getDisplay(),
        );
        self::assertMatchesRegularExpression('/Child relationships discovered\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Linked-page relationships discovered\s+2/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Unique pages scheduled\s+3/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Duplicate discoveries\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Maximum traversal depth reached\s+1/', $tester->getDisplay());
    }

    private function createCommandTester(
        ConfluenceImporter $confluenceImporter,
        ?ObsidianImageLinkBuilderInterface $obsidianImageLinkBuilder = null,
        ?ConfluenceGatewayInterface $confluenceGateway = null,
    ): CommandTester {
        $pathBuilder = new ObsidianPathBuilder(new AsciiSlugger());
        $obsidianWriter = new ObsidianPageWriter(
            vaultPath: $this->vaultPath,
            pathBuilder: $pathBuilder,
            filesystem: $this->filesystem,
            frontmatterRenderer: new ObsidianFrontmatterRenderer(),
        );
        $obsidianAttachmentWriter = new ObsidianAttachmentWriter(
            vaultPath: $this->vaultPath,
            filesystem: $this->filesystem,
            pathBuilder: $pathBuilder,
        );

        $pipeline = new ConfluencePageImportPipeline(
            confluenceImporter: $confluenceImporter,
            pageWriter: $obsidianWriter,
            attachmentWriter: $obsidianAttachmentWriter,
            attachmentPlaceholderRewriter: new ObsidianAttachmentPlaceholderRewriter(
                $pathBuilder,
                $obsidianImageLinkBuilder ?? new ObsidianImageLinkBuilder(),
            ),
        );
        $command = new ImportConfluenceCommand(
            importTraversal: new ConfluenceImportTraversal(
                $pipeline,
                $confluenceGateway ?? $this->createStub(ConfluenceGatewayInterface::class),
            ),
        );

        return new CommandTester($command);
    }
}
