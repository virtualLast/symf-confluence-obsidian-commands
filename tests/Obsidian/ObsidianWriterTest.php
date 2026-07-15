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
