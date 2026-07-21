<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ResolveConfluenceLinksCommand;
use App\Obsidian\Frontmatter\FrontmatterParser;
use App\Obsidian\Index\ConfluenceNotesDirectoryResolver;
use App\Obsidian\Index\ConfluencePageIndexBuilder;
use App\Obsidian\Link\ConfluenceLinkResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class ResolveConfluenceLinksCommandTest extends TestCase
{
    private string $vaultPath;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $path = sys_get_temp_dir() . '/resolve-confluence-links-' . bin2hex(random_bytes(8));
        $this->filesystem->mkdir([$path . '/Inbox/Confluence', $path . '/Inbox/Testing']);
        $this->vaultPath = realpath($path) ?: $path;
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->vaultPath);
    }

    public function testItUsesTheDefaultDirectoryResolvesLinksAndPrintsASummary(): void
    {
        $url = 'https://example.atlassian.net/wiki/spaces/SD/pages/456/Target';
        $this->write('Inbox/Confluence/source.md', $this->note('123', "[Read target]($url)", [
            ['pageId' => '456', 'title' => 'Target', 'url' => $url],
        ]));
        $this->write('Inbox/Confluence/target.md', $this->note('456', 'Target body'));
        $this->write('Inbox/Confluence/personal.md', "---\nsource: personal\n---\nPersonal");
        $this->write('Inbox/Confluence/broken.md', "---\nsource: confluence\nconfluence: {}\n---\nBroken");

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $sourceContent = file_get_contents($this->vaultPath . '/Inbox/Confluence/source.md');
        self::assertIsString($sourceContent);
        self::assertStringContainsString(
            '[[Inbox/Confluence/target|Read target]]',
            $sourceContent,
        );
        self::assertStringContainsString('Inbox/Confluence', $tester->getDisplay());
        self::assertStringContainsString('Notes discovered', $tester->getDisplay());
        self::assertStringContainsString('Links resolved', $tester->getDisplay());
        self::assertStringContainsString('Files updated', $tester->getDisplay());
    }

    public function testItHonoursACustomDirectoryAndIsIdempotent(): void
    {
        $url = 'https://example.atlassian.net/wiki/pages/456';
        $this->write('Inbox/Testing/source.md', $this->note('123', "[Target]($url)", [
            ['pageId' => '456', 'title' => 'Target', 'url' => $url],
        ]));
        $this->write('Inbox/Testing/target.md', $this->note('456', 'Target'));

        $first = $this->tester();
        $second = $this->tester();

        self::assertSame(Command::SUCCESS, $first->execute(['directory' => 'Inbox/Testing']));
        self::assertSame(Command::SUCCESS, $second->execute(['directory' => 'Inbox/Testing']));
        self::assertMatchesRegularExpression('/Files updated\s+0/', $second->getDisplay());
    }

    public function testItRejectsTraversalAndAMissingDirectory(): void
    {
        $traversal = $this->tester();
        $missing = $this->tester();

        self::assertSame(Command::FAILURE, $traversal->execute(['directory' => '../outside']));
        self::assertStringContainsString('unsafe path segment', $traversal->getDisplay());
        self::assertSame(Command::FAILURE, $missing->execute(['directory' => 'Inbox/Missing']));
        self::assertStringContainsString('does not exist', $missing->getDisplay());
    }

    public function testDuplicatesFailBeforeAnyFilesAreChangedAndVerboseOutputListsPaths(): void
    {
        $url = 'https://example.atlassian.net/wiki/pages/456';
        $source = $this->note('123', "[Target]($url)", [
            ['pageId' => '456', 'title' => 'Target', 'url' => $url],
        ]);
        $this->write('Inbox/Confluence/source.md', $source);
        $this->write('Inbox/Confluence/duplicate-one.md', $this->note('456', 'One'));
        $this->write('Inbox/Confluence/duplicate-two.md', $this->note('456', 'Two'));

        $tester = $this->tester();
        $exitCode = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame($source, file_get_contents($this->vaultPath . '/Inbox/Confluence/source.md'));
        self::assertStringContainsString('Duplicate page ID 456', $tester->getDisplay());
        self::assertStringContainsString('duplicate-one.md', $tester->getDisplay());
        self::assertStringContainsString('duplicate-two.md', $tester->getDisplay());
    }

    public function testVerboseOutputListsInvalidUnresolvedAndUpdatedNotes(): void
    {
        $url = 'https://example.atlassian.net/wiki/pages/999';
        $this->write('Inbox/Confluence/source.md', $this->note('123', "[Missing]($url)", [
            ['pageId' => '999', 'title' => 'Missing', 'url' => $url],
        ]));
        $this->write('Inbox/Confluence/broken.md', "---\nsource: [broken\n---\nBody");

        $tester = $this->tester();
        $exitCode = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Invalid note: Inbox/Confluence/broken.md', $tester->getDisplay());
        self::assertStringContainsString('Unresolved page ID 999', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Links unresolved\s+1/', $tester->getDisplay());
    }

    public function testItReturnsFailureWhenAChangedNoteCannotBeWritten(): void
    {
        $url = 'https://example.atlassian.net/wiki/pages/456';
        $this->write('Inbox/Confluence/source.md', $this->note('123', "[Target]($url)", [
            ['pageId' => '456', 'title' => 'Target', 'url' => $url],
        ]));
        $this->write('Inbox/Confluence/target.md', $this->note('456', 'Target'));

        $writer = $this->createMock(Filesystem::class);
        $writer
            ->expects(self::once())
            ->method('dumpFile')
            ->willThrowException(new IOException('Disk full.'));

        $tester = $this->tester($writer);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unable to write resolved note', $tester->getDisplay());
    }

    private function tester(?Filesystem $writer = null): CommandTester
    {
        return new CommandTester(new ResolveConfluenceLinksCommand(
            new ConfluenceNotesDirectoryResolver($this->vaultPath, $this->filesystem),
            new ConfluencePageIndexBuilder(new FrontmatterParser()),
            new ConfluenceLinkResolver(),
            $writer ?? $this->filesystem,
        ));
    }

    /** @param list<array{pageId: string, title: string, url: string}> $relationships */
    private function note(string $pageId, string $body, array $relationships = []): string
    {
        $linkedPages = '';

        foreach ($relationships as $relationship) {
            $linkedPages .= sprintf(
                "\n    - pageId: '%s'\n      title: '%s'\n      url: '%s'",
                $relationship['pageId'],
                $relationship['title'],
                $relationship['url'],
            );
        }

        return sprintf(
            "---\nsource: confluence\nconfluence:\n  pageId: '%s'\nrelationships:\n  linkedPages:%s\n---\n%s",
            $pageId,
            $linkedPages === '' ? ' []' : $linkedPages,
            $body,
        );
    }

    private function write(string $path, string $content): void
    {
        $this->filesystem->dumpFile($this->vaultPath . '/' . $path, $content);
    }
}
