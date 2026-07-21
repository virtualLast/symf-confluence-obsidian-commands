<?php

declare(strict_types=1);

namespace App\Tests\Obsidian;

use App\Model\ImportedPage;
use App\Obsidian\ObsidianFrontmatterRenderer;
use App\Obsidian\ObsidianPageWriter;
use App\Obsidian\ObsidianPathBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ObsidianWriterTest extends TestCase
{
    private string $vaultPath;

    private Filesystem $filesystem;

    /** @var list<string> */
    private array $additionalCleanupPaths = [];

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
        $this->filesystem->remove([
            $this->vaultPath,
            ...$this->additionalCleanupPaths,
        ]);
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
            realpath($this->vaultPath)
            . DIRECTORY_SEPARATOR
            . 'sso-overview.md',
            $filePath,
        );

        self::assertFileExists($filePath);

        self::assertSame(
            new ObsidianFrontmatterRenderer()->render($page),
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
                    realpath($this->vaultPath),
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
            new ObsidianFrontmatterRenderer()->render($page),
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
            realpath($this->vaultPath)
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
            realpath($this->vaultPath)
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
            new ObsidianFrontmatterRenderer()->render($updatedPage),
            file_get_contents($filePath),
        );
    }

    /**
     * What: Absolute destinations are rejected rather than interpreted beneath the vault.
     * How: A separator-prefixed destination is passed and an invalid-argument error is expected.
     * Why: Every generated Markdown file must remain relative to OBSIDIAN_VAULT.
     */
    public function testItRejectsAbsoluteDestinations(): void
    {
        $writer = $this->createWriter();

        $page = new ImportedPage(
            id: 123,
            title: 'SSO Overview',
            content: 'Page content.',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be relative');

        $writer->write(
            $page,
            DIRECTORY_SEPARATOR . 'Projects',
        );
    }

    /**
     * What: Parent-directory traversal cannot escape the configured vault.
     * How: An existing sibling is targeted with `..` and an invalid-argument error is expected.
     * Why: Imports must never write Markdown outside OBSIDIAN_VAULT.
     */
    public function testItRejectsParentDirectoryTraversal(): void
    {
        $writer = $this->createWriter();
        $page = new ImportedPage(123, 'SSO Overview', 'Page content.');

        $outsidePath = $this->vaultPath . '-outside';
        $this->additionalCleanupPaths[] = $outsidePath;
        $this->filesystem->mkdir($outsidePath);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe path segment');

        $writer->write($page, '../' . basename($outsidePath));
    }

    /**
     * What: Symbolic links cannot redirect a destination outside the vault.
     * How: A vault symlink points to an external directory and is used as the destination.
     * Why: Lexically safe paths can still escape the vault when symlinks are followed.
     */
    public function testItRejectsSymbolicLinkDestinations(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Symlink creation is not reliably available on Windows.');
        }

        $writer = $this->createWriter();
        $page = new ImportedPage(123, 'SSO Overview', 'Page content.');
        $outsidePath = $this->vaultPath . '-outside';
        $this->additionalCleanupPaths[] = $outsidePath;

        $this->filesystem->mkdir([$this->vaultPath, $outsidePath]);
        $this->filesystem->symlink($outsidePath, $this->vaultPath . '/linked');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain symbolic links');

        $writer->write($page, 'linked');
    }

    /**
     * What: Windows-style separators are handled as relative destination separators.
     * How: A backslash-delimited path is written and its canonical vault path is asserted.
     * Why: Destination input should behave consistently across platforms without escaping the vault.
     */
    public function testItHandlesMixedDestinationSeparators(): void
    {
        $writer = $this->createWriter();
        $page = new ImportedPage(123, 'SSO Overview', 'Page content.');

        $filePath = $writer->write($page, 'Areas\\Engineering/SSO');

        self::assertSame(
            realpath($this->vaultPath)
            . '/Areas/Engineering/SSO/sso-overview.md',
            $filePath,
        );
        self::assertFileExists($filePath);
    }

    /**
     * What: Titles without slug characters receive a dated untitled filename.
     * How: A punctuation-only title is written and its filename is matched to the fallback format.
     * Why: Blank filenames must not create a hidden `.md` file or collide immediately.
     */
    public function testItUsesADatedFallbackForAnEmptySlug(): void
    {
        $writer = $this->createWriter();
        $page = new ImportedPage(123, '!!!', 'Page content.');

        $filePath = $writer->write($page);

        self::assertMatchesRegularExpression(
            '/^untitled-\d{2}-\d{2}-\d{2}-\d{2}:\d{2}:\d{2}\.md$/',
            basename($filePath),
        );
        self::assertFileExists($filePath);
    }

    /**
     * What: A relative vault configuration is rejected.
     * How: The writer is constructed with a relative path and asked to persist a page.
     * Why: Vault containment cannot be guaranteed without an absolute root boundary.
     */
    public function testItRejectsARelativeVaultPath(): void
    {
        $writer = new ObsidianPageWriter(
            vaultPath: 'relative/vault',
            pathBuilder: $this->createPathBuilder(),
            filesystem: $this->filesystem,
            frontmatterRenderer: new ObsidianFrontmatterRenderer(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a non-empty absolute path');

        $writer->write(new ImportedPage(123, 'Page', 'Content'));
    }

    private function createWriter(): ObsidianPageWriter
    {
        return new ObsidianPageWriter(
            vaultPath: $this->vaultPath,
            pathBuilder: $this->createPathBuilder(),
            filesystem: $this->filesystem,
            frontmatterRenderer: new ObsidianFrontmatterRenderer(),
        );
    }

    private function createPathBuilder(): ObsidianPathBuilder
    {
        return new ObsidianPathBuilder(new AsciiSlugger());
    }
}
