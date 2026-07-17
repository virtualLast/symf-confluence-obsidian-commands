<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ImportConfluenceCommand;
use App\Confluence\ConfluenceImporter;
use App\Model\ImportedAttachment;
use App\Model\ImportedPage;
use App\Obsidian\ObsidianAttachmentWriter;
use App\Obsidian\ObsidianImageLinkBuilder;
use App\Obsidian\ObsidianImageLinkBuilderInterface;
use App\Obsidian\ObsidianPageWriter;
use App\Obsidian\ObsidianPathBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
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
            id: 123,
            title: 'SSO Overview',
            content: "# SSO Overview\n\nImported content.",
        );

        $confluenceImporter = $this->createMock(
            ConfluenceImporter::class,
        );

        $confluenceImporter
            ->expects(self::once())
            ->method('importPage')
            ->with(123, false)
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
            $importedPage->content,
            file_get_contents($expectedFilePath),
        );

        self::assertStringContainsString(
            'Imported "SSO Overview"',
            $commandTester->getDisplay(),
        );

        self::assertStringContainsString(
            basename($expectedFilePath),
            str_replace(
                [" ", "\t", "\n", "\r", "\0", "\x0B"],
                '',
                $commandTester->getDisplay(),
            ),
        );
    }

    /**
     * What: The command requests attachments when the option is supplied.
     * How: The importer expectation verifies the option is forwarded as true.
     * Why: Attachment fetching should be opt-in at the command boundary.
     */
    public function testItImportsAPageWithAttachments(): void
    {
        $importedPage = new ImportedPage(
            id: 123,
            title: 'SSO Overview',
            content: '# SSO Overview',
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
            ->with(123, true)
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
            "# SSO Overview\n\n![[Attachments/Confluence/sso-overview/architecture.png]]",
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
            id: 456,
            title: 'Authentication Architecture',
            content: '# Authentication Architecture',
        );

        $confluenceImporter = $this->createMock(
            ConfluenceImporter::class,
        );

        $confluenceImporter
            ->expects(self::once())
            ->method('importPage')
            ->with(456, false)
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
            $importedPage->content,
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
            id: 456,
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
            ->with(456, true)
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
            id: 123,
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
            ->with(123, true)
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
            ->with(123, false)
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
            id: 123,
            title: 'SSO Overview',
            content: 'Page content.',
        );

        $confluenceImporter = $this->createMock(
            ConfluenceImporter::class,
        );

        $confluenceImporter
            ->expects(self::once())
            ->method('importPage')
            ->with(123, false)
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
            id: 123,
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
            ->with(123, true)
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
    }

    private function createCommandTester(
        ConfluenceImporter $confluenceImporter,
        ?ObsidianImageLinkBuilderInterface $obsidianImageLinkBuilder = null,
    ): CommandTester {
        $pathBuilder = new ObsidianPathBuilder(new AsciiSlugger());
        $obsidianWriter = new ObsidianPageWriter(
            vaultPath: $this->vaultPath,
            pathBuilder: $pathBuilder,
            filesystem: $this->filesystem,
        );
        $obsidianAttachmentWriter = new ObsidianAttachmentWriter(
            vaultPath: $this->vaultPath,
            filesystem: $this->filesystem,
            pathBuilder: $pathBuilder,
        );

        $command = new ImportConfluenceCommand(
            confluenceImporter: $confluenceImporter,
            obsidianWriter: $obsidianWriter,
            obsidianAttachmentWriter: $obsidianAttachmentWriter,
            obsidianImageLinkBuilder: $obsidianImageLinkBuilder ?? new ObsidianImageLinkBuilder(),
        );

        return new CommandTester($command);
    }
}
