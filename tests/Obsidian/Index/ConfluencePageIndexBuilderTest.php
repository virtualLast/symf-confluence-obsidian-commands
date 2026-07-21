<?php

declare(strict_types=1);

namespace App\Tests\Obsidian\Index;

use App\Obsidian\Frontmatter\FrontmatterParser;
use App\Obsidian\Index\ConfluencePageIndexBuilder;
use App\Obsidian\Index\ResolvedNotesDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ConfluencePageIndexBuilderTest extends TestCase
{
    private string $vaultPath;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->vaultPath = sys_get_temp_dir() . '/confluence-index-' . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->vaultPath . '/Inbox/Confluence/Nested');
        $this->vaultPath = realpath($this->vaultPath) ?: $this->vaultPath;
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->vaultPath);
    }

    public function testItBuildsARecursiveVaultRelativeIndexAndClassifiesSkippedNotes(): void
    {
        $this->write('Inbox/Confluence/page.md', $this->note('123'));
        $this->write('Inbox/Confluence/Nested/target.md', $this->note('456'));
        $this->write('Inbox/Confluence/personal.md', "---\nsource: personal\n---\nBody");
        $this->write('Inbox/Confluence/broken.md', "---\nsource: [broken\n---\nBody");
        $this->write('Inbox/Confluence/ignored.txt', $this->note('789'));

        $result = $this->builder()->build($this->directory());

        self::assertSame(4, $result->notesDiscovered);
        self::assertSame(2, $result->notesIndexed);
        self::assertSame(2, $result->notesSkipped);
        self::assertCount(1, $result->invalidNotes);
        self::assertSame('Inbox/Confluence/page.md', $result->index->find('123')?->vaultPath);
        self::assertSame('Inbox/Confluence/Nested/target.md', $result->index->find('456')?->vaultPath);
        self::assertNull($result->index->find('789'));
    }

    public function testItReportsEachDistinctDuplicateIdAndEveryPath(): void
    {
        $this->write('Inbox/Confluence/one.md', $this->note('123'));
        $this->write('Inbox/Confluence/two.md', $this->note('123'));
        $this->write('Inbox/Confluence/Nested/three.md', $this->note('123'));

        $duplicates = $this->builder()->build($this->directory())->index->duplicates();

        self::assertCount(1, $duplicates);
        self::assertSame('123', $duplicates[0]->pageId);
        self::assertCount(3, $duplicates[0]->paths);
        self::assertContains('Inbox/Confluence/one.md', $duplicates[0]->paths);
        self::assertContains('Inbox/Confluence/two.md', $duplicates[0]->paths);
        self::assertContains('Inbox/Confluence/Nested/three.md', $duplicates[0]->paths);
    }

    private function builder(): ConfluencePageIndexBuilder
    {
        return new ConfluencePageIndexBuilder(new FrontmatterParser());
    }

    private function directory(): ResolvedNotesDirectory
    {
        return new ResolvedNotesDirectory(
            $this->vaultPath,
            $this->vaultPath . '/Inbox/Confluence',
            'Inbox/Confluence',
        );
    }

    private function note(string $pageId): string
    {
        return sprintf(
            "---\nsource: confluence\nconfluence:\n  pageId: '%s'\nrelationships:\n  linkedPages: []\n---\nBody",
            $pageId,
        );
    }

    private function write(string $path, string $content): void
    {
        $this->filesystem->dumpFile($this->vaultPath . '/' . $path, $content);
    }
}
