<?php

declare(strict_types=1);

namespace App\Tests\Obsidian\Index;

use App\Obsidian\Index\ConfluenceNotesDirectoryResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ConfluenceNotesDirectoryResolverTest extends TestCase
{
    private string $vaultPath;
    private string $outsidePath;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $suffix = bin2hex(random_bytes(8));
        $this->vaultPath = sys_get_temp_dir() . '/resolver-vault-' . $suffix;
        $this->outsidePath = sys_get_temp_dir() . '/resolver-outside-' . $suffix;
        $this->filesystem->mkdir([$this->vaultPath . '/Inbox/Confluence', $this->outsidePath]);
        $this->vaultPath = realpath($this->vaultPath) ?: $this->vaultPath;
        $this->outsidePath = realpath($this->outsidePath) ?: $this->outsidePath;
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove([$this->vaultPath, $this->outsidePath]);
    }

    public function testItResolvesAnExistingVaultRelativeDirectory(): void
    {
        $directory = $this->resolver()->resolve('Inbox\\Confluence/');

        self::assertSame('Inbox/Confluence', $directory->relativePath);
        self::assertSame($this->vaultPath . '/Inbox/Confluence', $directory->absolutePath);
    }

    public function testItRejectsAbsoluteAndTraversalPaths(): void
    {
        foreach (['/tmp', '../outside', 'Inbox/../outside', 'Inbox//Confluence'] as $path) {
            try {
                $this->resolver()->resolve($path);
                self::fail(sprintf('Expected "%s" to be rejected.', $path));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testItRejectsASymlinkWhoseTargetIsOutsideTheVault(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Symlink creation is not reliably available on Windows.');
        }

        $this->filesystem->symlink($this->outsidePath, $this->vaultPath . '/linked');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('within OBSIDIAN_VAULT');

        $this->resolver()->resolve('linked');
    }

    private function resolver(): ConfluenceNotesDirectoryResolver
    {
        return new ConfluenceNotesDirectoryResolver($this->vaultPath, $this->filesystem);
    }
}
