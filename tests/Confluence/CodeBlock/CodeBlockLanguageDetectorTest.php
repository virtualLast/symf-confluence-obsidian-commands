<?php

declare(strict_types=1);

namespace App\Tests\Confluence\CodeBlock;

use App\Confluence\CodeBlock\CodeBlockLanguageDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeBlockLanguageDetectorTest extends TestCase
{
    private CodeBlockLanguageDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new CodeBlockLanguageDetector();
    }

    #[DataProvider('detectionProvider')]
    public function testItDetectsSupportedCodeBlocks(
        ?string $declaredLanguage,
        string $code,
        ?string $expectedLanguage,
    ): void {
        self::assertSame($expectedLanguage, $this->detector->detect($declaredLanguage, $code));
    }

    public function testDetectionIsDeterministic(): void
    {
        $code = "pipeline:\n  steps:\n    - install\n    - test";

        for ($iteration = 0; $iteration < 10; ++$iteration) {
            self::assertSame('yaml', $this->detector->detect('java', $code));
        }
    }

    /** @return iterable<string, array{?string, string, ?string}> */
    public static function detectionProvider(): iterable
    {
        yield 'valid Java remains Java' => [
            'java',
            "public class Example {\n    public static void main(String[] args) {}\n}",
            'java',
        ];
        yield 'PHP declared as Java' => [
            'java',
            "public function cross(int \$id, int \$multiple): int\n{\n    return \$id * \$multiple;\n}",
            'php',
        ];
        yield 'shell declared as Java' => [
            'java',
            "docker compose down\ndocker compose up -d",
            'bash',
        ];
        yield 'environment variables' => [
            'java',
            "AUTH_CLIENT_ID=client-id\nAUTH_CLIENT_SECRET=<pull from DB>",
            'dotenv',
        ];
        yield 'SQL' => ['java', 'SELECT * FROM accounts WHERE id = 8765;', 'sql'];
        yield 'Markdown' => ['java', "## SSO\n\n- OAuth\n- SAML", 'markdown'];
        yield 'JSON declared as Java' => [
            'java',
            '{"name":"demo","features":["imports","links"]}',
            'json',
        ];
        yield 'YAML declared as Java' => [
            'java',
            "pipeline:\n  name: demo\n  steps:\n    - test",
            'yaml',
        ];
        yield 'valid JavaScript alias' => [
            'js',
            "function greet(name) {\n  const message = `Hello, \${name}`;\n}",
            'javascript',
        ];
        yield 'valid TypeScript' => [
            'typescript',
            "interface User { name: string }\nconst user: User = { name: 'Mike' };",
            'typescript',
        ];
        yield 'valid HTML' => ['html', '<div><p>Hello</p></div>', 'html'];
        yield 'valid XML' => ['xml', '<?xml version="1.0"?><config><name>demo</name></config>', 'xml'];
        yield 'valid Python declaration is preserved' => [
            'py',
            "def greet(name):\n    print(name)",
            'python',
        ];
        yield 'unknown content is unlabelled' => ['java', 'some ambiguous example text', null];
        yield 'unknown declaration is ignored' => ['csharp', 'some ambiguous example text', null];
    }
}
