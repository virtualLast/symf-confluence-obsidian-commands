<?php

declare(strict_types=1);

namespace App\Tests\Obsidian;

use App\Model\ImportedAttachment;
use App\Obsidian\ObsidianAttachmentWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

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
            filename: 'architecture.png',
            content: "\x89PNG\r\n\x1a\n\x00binary-content",
        );

        $filePath = $this->createWriter()->write($attachment);

        self::assertSame(
            realpath($this->vaultPath)
            . DIRECTORY_SEPARATOR
            . 'Attachments'
            . DIRECTORY_SEPARATOR
            . 'architecture.png',
            $filePath,
        );
        self::assertFileExists($filePath);
        self::assertSame($attachment->content, file_get_contents($filePath));
    }

    public function testItWritesAnAttachmentToANestedAttachmentDestination(): void
    {
        $attachment = new ImportedAttachment('123', 'design.pdf', 'PDF content');

        $filePath = $this->createWriter()->write(
            $attachment,
            'Projects/Authentication',
        );

        self::assertSame(
            realpath($this->vaultPath)
            . DIRECTORY_SEPARATOR
            . 'Attachments'
            . DIRECTORY_SEPARATOR
            . 'Projects'
            . DIRECTORY_SEPARATOR
            . 'Authentication'
            . DIRECTORY_SEPARATOR
            . 'design.pdf',
            $filePath,
        );
        self::assertFileExists($filePath);
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

        $this->createWriter()->write($attachment);
    }

    public function testItOverwritesAnExistingAttachment(): void
    {
        $writer = $this->createWriter();

        $filePath = $writer->write(
            new ImportedAttachment('123', 'document.pdf', 'old content'),
        );
        $writer->write(
            new ImportedAttachment('123', 'document.pdf', 'new content'),
        );

        self::assertSame('new content', file_get_contents($filePath));
    }

    private function createWriter(): ObsidianAttachmentWriter
    {
        return new ObsidianAttachmentWriter(
            vaultPath: $this->vaultPath,
            filesystem: $this->filesystem,
        );
    }
}
