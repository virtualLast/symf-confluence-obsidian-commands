<?php

namespace App\Tests\Obsidian;

use App\Model\ImportedPage;
use App\Obsidian\ObsidianWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ObsidianWriterTest extends TestCase
{
    private string $vaultPath;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();

        $this->vaultPath = sprintf(
            '%s/obsidian-writer-test-%s',
            sys_get_temp_dir(),
            bin2hex(random_bytes(8)),
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->vaultPath);
    }

    /**
     * What: An imported page is written as Markdown at the vault root.
     * How: A page is written and its returned path, existence, and exact content are asserted.
     * Why: Persisting converted pages is the writer's core responsibility.
     */
    public function testItWritesAnImportedPageToTheVault(): void
    {
        $writer = $this->createWriter();

        $page = new ImportedPage(
            id: 123,
            title: 'SSO Overview',
            content: "# SSO Overview\n\nPage content.",
        );

        $filePath = $writer->write($page);

        self::assertSame(
            $this->vaultPath . DIRECTORY_SEPARATOR . 'sso-overview.md',
            $filePath,
        );

        self::assertFileExists($filePath);

        self::assertSame(
            $page->content,
            file_get_contents($filePath),
        );
    }

    /**
     * What: An imported page can be written beneath a nested vault destination.
     * How: A multi-level destination is passed and the final path and content are inspected.
     * Why: Vault users organise notes into folders rather than storing every page at the root.
     */
    public function testItWritesAnImportedPageToADestinationDirectory(): void
    {
        $writer = $this->createWriter();

        $page = new ImportedPage(
            id: 123,
            title: 'SSO Overview',
            content: "# SSO Overview\n\nPage content.",
        );

        $filePath = $writer->write(
            $page,
            'Areas/Engineering/SSO',
        );

        self::assertSame(
            implode(
                DIRECTORY_SEPARATOR,
                [
                    $this->vaultPath,
                    'Areas',
                    'Engineering',
                    'SSO',
                    'sso-overview.md',
                ],
            ),
            $filePath,
        );

        self::assertFileExists($filePath);

        self::assertSame(
            $page->content,
            file_get_contents($filePath),
        );
    }

    /**
     * What: Missing destination directories are created automatically.
     * How: A page is written to a nonexistent nested path and both directory and file are checked.
     * Why: Imports should work without requiring users to prepare the vault structure manually.
     */
    public function testItCreatesMissingDestinationDirectories(): void
    {
        $writer = $this->createWriter();

        $page = new ImportedPage(
            id: 123,
            title: 'SSO Overview',
            content: 'Page content.',
        );

        $filePath = $writer->write(
            $page,
            'Projects/Authentication',
        );

        self::assertDirectoryExists(
            $this->vaultPath
            . DIRECTORY_SEPARATOR
            . 'Projects'
            . DIRECTORY_SEPARATOR
            . 'Authentication',
        );

        self::assertFileExists($filePath);
    }

    /**
     * What: Page titles are converted into safe, predictable Markdown filenames.
     * How: A title containing punctuation and an ampersand is written and its path is asserted.
     * Why: Confluence titles may contain characters unsuitable for consistent vault filenames.
     */
    public function testItSlugifiesThePageTitle(): void
    {
        $writer = $this->createWriter();

        $page = new ImportedPage(
            id: 123,
            title: 'OAuth, OIDC & SSO Implementation',
            content: 'Page content.',
        );

        $filePath = $writer->write($page);

        self::assertSame(
            $this->vaultPath
            . DIRECTORY_SEPARATOR
            . 'oauth-oidc-sso-implementation.md',
            $filePath,
        );
    }

    /**
     * What: Re-importing a page replaces the existing Markdown content.
     * How: Two versions with the same title are written and the file is checked for the update.
     * Why: Repeated imports must refresh stale notes instead of preserving obsolete content.
     */
    public function testItOverwritesAnExistingPage(): void
    {
        $writer = $this->createWriter();

        $originalPage = new ImportedPage(
            id: 123,
            title: 'SSO Overview',
            content: 'Original content.',
        );

        $updatedPage = new ImportedPage(
            id: 123,
            title: 'SSO Overview',
            content: 'Updated content.',
        );

        $filePath = $writer->write($originalPage);

        $writer->write($updatedPage);

        self::assertSame(
            'Updated content.',
            file_get_contents($filePath),
        );
    }

    /**
     * What: Leading and trailing directory separators are normalised in destinations.
     * How: A separator-wrapped folder is passed and the returned path and file are checked.
     * Why: Minor path formatting differences must not create malformed or misplaced notes.
     */
    public function testItHandlesDestinationSeparatorsAtTheEdges(): void
    {
        $writer = $this->createWriter();

        $page = new ImportedPage(
            id: 123,
            title: 'SSO Overview',
            content: 'Page content.',
        );

        $filePath = $writer->write(
            $page,
            DIRECTORY_SEPARATOR . 'Projects' . DIRECTORY_SEPARATOR,
        );

        self::assertSame(
            implode(
                DIRECTORY_SEPARATOR,
                [
                    $this->vaultPath,
                    'Projects',
                    'sso-overview.md',
                ],
            ),
            $filePath,
        );

        self::assertFileExists($filePath);
    }

    private function createWriter(): ObsidianWriter
    {
        return new ObsidianWriter(
            vaultPath: $this->vaultPath,
            slugger: new AsciiSlugger(),
            filesystem: $this->filesystem,
        );
    }
}
