<?php

declare(strict_types=1);

namespace App\Tests\Obsidian;

use App\Model\ImportedAttachment;
use App\Model\ImportedPage;
use App\Obsidian\ObsidianAttachmentWriter;
use App\Obsidian\ObsidianImageLinkBuilder;
use App\Obsidian\ObsidianPathBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ObsidianAttachmentWriterTest extends TestCase
{
    private string $vaultPath;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->vaultPath = sprintf(
            '%s/obsidian-attachment-writer-test-%s',
            sys_get_temp_dir(),
            bin2hex(random_bytes(8)),
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->vaultPath);
    }

    public function testItWritesAnAttachmentWithItsOriginalFilenameAndContent(): void
    {
        $attachment = new ImportedAttachment(
            id: '123',
            filename: 'icon.jpg',
            content: "\x89PNG\r\n\x1a\n\x00binary-content",
        );

        $vaultPath = $this->createWriter()->write($this->createPage(), $attachment);

        self::assertSame(
            'Attachments/Confluence/mike-test-document/icon.jpg',
            $vaultPath,
        );
        self::assertStringNotContainsString($this->vaultPath, $vaultPath);
        $absolutePath = $this->vaultPath . DIRECTORY_SEPARATOR . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $vaultPath,
        );
        self::assertFileExists($absolutePath);
        self::assertSame($attachment->content, file_get_contents($absolutePath));
    }

    public function testNoteDestinationDoesNotAffectTheAttachmentPath(): void
    {
        $attachment = new ImportedAttachment('123', 'design.pdf', 'PDF content');
        $writer = $this->createWriter();

        $inboxPath = $writer->write($this->createPage(), $attachment);
        $projectsPath = $writer->write($this->createPage(), $attachment);

        self::assertSame(
            'Attachments/Confluence/mike-test-document/design.pdf',
            $inboxPath,
        );
        self::assertSame($inboxPath, $projectsPath);
        self::assertDirectoryExists(
            $this->vaultPath . '/Attachments/Confluence/mike-test-document',
        );
    }

    public function testItBuildsAVaultRelativeObsidianImageEmbed(): void
    {
        $link = (new ObsidianImageLinkBuilder())->build(
            'Attachments/Confluence/mike-test-document/icon.jpg',
        );

        self::assertSame(
            '![[Attachments/Confluence/mike-test-document/icon.jpg]]',
            $link,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeFilenames(): iterable
    {
        yield 'parent traversal' => ['../../escaped.txt'];
        yield 'forward-slash path' => ['nested/file.txt'];
        yield 'backslash path' => ['nested\\file.txt'];
        yield 'empty filename' => [''];
        yield 'current directory' => ['.'];
        yield 'parent directory' => ['..'];
        yield 'null byte' => ["unsafe\0.txt"];
    }

    #[DataProvider('unsafeFilenames')]
    public function testItRejectsUnsafeAttachmentFilenames(string $filename): void
    {
        $attachment = new ImportedAttachment('123', $filename, 'content');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('without path segments');

        $this->createWriter()->write($this->createPage(), $attachment);
    }

    public function testItOverwritesAnExistingAttachment(): void
    {
        $writer = $this->createWriter();

        $vaultPath = $writer->write(
            $this->createPage(),
            new ImportedAttachment('123', 'document.pdf', 'old content'),
        );
        $writer->write(
            $this->createPage(),
            new ImportedAttachment('123', 'document.pdf', 'new content'),
        );

        self::assertSame(
            'new content',
            file_get_contents($this->vaultPath . '/' . $vaultPath),
        );
    }

    private function createWriter(): ObsidianAttachmentWriter
    {
        return new ObsidianAttachmentWriter(
            vaultPath: $this->vaultPath,
            filesystem: $this->filesystem,
            pathBuilder: new ObsidianPathBuilder(new AsciiSlugger()),
        );
    }

    private function createPage(): ImportedPage
    {
        return new ImportedPage('123', 'Mike Test Document', 'Content');
    }
}
