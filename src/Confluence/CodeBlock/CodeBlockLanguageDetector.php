<?php

declare(strict_types=1);

namespace App\Confluence\CodeBlock;

final class CodeBlockLanguageDetector implements CodeBlockLanguageDetectorInterface
{
    private const array ALIASES = [
        'bash' => 'bash',
        'dotenv' => 'dotenv',
        'env' => 'dotenv',
        'html' => 'html',
        'java' => 'java',
        'javascript' => 'javascript',
        'js' => 'javascript',
        'json' => 'json',
        'markdown' => 'markdown',
        'md' => 'markdown',
        'php' => 'php',
        'py' => 'python',
        'python' => 'python',
        'shell' => 'bash',
        'sh' => 'bash',
        'sql' => 'sql',
        'ts' => 'typescript',
        'typescript' => 'typescript',
        'xml' => 'xml',
        'yaml' => 'yaml',
        'yml' => 'yaml',
    ];

    public function detect(?string $declaredLanguage, string $code): ?string
    {
        $declaredLanguage = $this->normaliseDeclaredLanguage($declaredLanguage);
        $detectedLanguage = $this->detectFromContent($code);

        if ($detectedLanguage !== null) {
            return $detectedLanguage;
        }

        if ($declaredLanguage === 'python' && $this->looksLikePython($code)) {
            return 'python';
        }

        return null;
    }

    private function normaliseDeclaredLanguage(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }

        return self::ALIASES[strtolower(trim($language))] ?? null;
    }

    private function detectFromContent(string $code): ?string
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        foreach ([
            'json' => $this->looksLikeJson(...),
            'dotenv' => $this->looksLikeDotenv(...),
            'php' => $this->looksLikePhp(...),
            'sql' => $this->looksLikeSql(...),
            'bash' => $this->looksLikeBash(...),
            'typescript' => $this->looksLikeTypeScript(...),
            'javascript' => $this->looksLikeJavaScript(...),
            'java' => $this->looksLikeJava(...),
            'html' => $this->looksLikeHtml(...),
            'xml' => $this->looksLikeXml(...),
            'yaml' => $this->looksLikeYaml(...),
            'markdown' => $this->looksLikeMarkdown(...),
        ] as $language => $matches) {
            if ($matches($code)) {
                return $language;
            }
        }

        return null;
    }

    private function looksLikeJson(string $code): bool
    {
        if (!str_starts_with($code, '{') && !str_starts_with($code, '[')) {
            return false;
        }

        json_decode($code, true);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function looksLikeDotenv(string $code): bool
    {
        $lines = $this->significantLines($code);

        if ($lines === []) {
            return false;
        }

        foreach ($lines as $line) {
            if (preg_match('/^(?:export\s+)?[A-Z_][A-Z0-9_]*\s*=.*$/', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function looksLikePhp(string $code): bool
    {
        return preg_match(
            '/<\?php\b|\bnamespace\s+[A-Za-z_\\\\]|\buse\s+[A-Za-z_\\\\]+;|'
            . '\b(?:public|protected|private)\s+function\b|\$[A-Za-z_]\w*\s*(?:=|->)|'
            . '\b(?:echo|print)\s+[^;\r\n]+;|\b(?:require|include)(?:_once)?\b|::[A-Za-z_]\w*/',
            $code,
        ) === 1;
    }

    private function looksLikeSql(string $code): bool
    {
        return preg_match(
            '/^\s*(?:SELECT\b.+\bFROM\b|INSERT\s+INTO\b|UPDATE\b.+\bSET\b|'
            . 'DELETE\s+FROM\b|CREATE\s+(?:TABLE|INDEX|VIEW)\b|ALTER\s+TABLE\b|'
            . 'DROP\s+(?:TABLE|INDEX|VIEW)\b|WITH\b.+\bSELECT\b)/is',
            $code,
        ) === 1;
    }

    private function looksLikeBash(string $code): bool
    {
        return preg_match('/^#!.*\b(?:ba|z|k)?sh\b/m', $code) === 1
            || preg_match('/^\s*(?:set\s+-[a-zA-Z]+|if\s+\[|for\s+\w+\s+in\b|while\s+.+;\s*do)\b/m', $code) === 1
            || preg_match(
                '/^\s*(?:docker(?:\s+compose)?|composer|npm|yarn|pnpm|git|curl|wget|'
                . 'php|bin\/console|make|chmod|mkdir|cp|mv|rm|echo)\s+\S+/m',
                $code,
            ) === 1;
    }

    private function looksLikeTypeScript(string $code): bool
    {
        return preg_match('/\b(?:interface|type|enum)\s+[A-Za-z_]\w*|'
            . '\b(?:const|let|var)\s+\w+\s*:\s*[A-Za-z_{[]|'
            . '\bfunction\s+\w+\s*\([^)]*:\s*[A-Za-z_{[]|'
            . '\)\s*:\s*[A-Za-z_{[]\s*(?:=>|\{)/', $code) === 1;
    }

    private function looksLikeJavaScript(string $code): bool
    {
        return preg_match(
            '/\b(?:const|let|var)\s+[A-Za-z_$]\w*\s*=|'
            . '\bfunction\s+[A-Za-z_$]\w*\s*\(|'
            . '\bconsole\.(?:log|error|warn)\s*\(|'
            . '(?:^|[=(,]\s*)\([^)]*\)\s*=>/',
            $code,
        ) === 1;
    }

    private function looksLikeJava(string $code): bool
    {
        return preg_match(
            '/\b(?:public\s+)?(?:class|interface|record|enum)\s+[A-Z]\w*|'
            . '\bimport\s+java(?:x)?\.|'
            . '\bpublic\s+static\s+void\s+main\s*\(|'
            . '\bnew\s+[A-Z]\w*(?:<[^>]+>)?\s*\(/',
            $code,
        ) === 1;
    }

    private function looksLikeHtml(string $code): bool
    {
        return preg_match('/<!DOCTYPE\s+html\b|<\/?(?:html|head|body|main|section|article|'
            . 'div|span|p|a|img|table|form|input|script|style)\b/i', $code) === 1;
    }

    private function looksLikeXml(string $code): bool
    {
        return preg_match('/^<\?xml\b/i', $code) === 1
            || preg_match('/^<([A-Za-z_][\w:.-]*)(?:\s[^>]*)?>[\s\S]*<\/\1>$/', $code) === 1;
    }

    private function looksLikeYaml(string $code): bool
    {
        $mappingLines = preg_match_all('/^(?: {0,3})[A-Za-z_][\w.-]*:\s*(?:.*)$/m', $code);
        $nestedLines = preg_match_all('/^(?: {2,})\S+/m', $code);
        $listLines = preg_match_all('/^\s*-\s+\S+/m', $code);

        return $mappingLines !== false
            && $mappingLines > 0
            && (($nestedLines !== false && $nestedLines > 0) || ($listLines !== false && $listLines > 0));
    }

    private function looksLikeMarkdown(string $code): bool
    {
        return preg_match('/^ {0,3}#{1,6}\s+\S+/m', $code) === 1
            || preg_match('/^ {0,3}(?:>|[-+*]\s+|\d+\.\s+)\S+/m', $code) === 1
            || preg_match('/\[[^\]]+]\([^)]+\)/', $code) === 1
            || preg_match('/^ {0,3}(?:`{3,}|~{3,})/m', $code) === 1;
    }

    private function looksLikePython(string $code): bool
    {
        return preg_match('/^\s*(?:def|class)\s+\w+.*:\s*$/m', $code) === 1
            || preg_match('/^\s*(?:from\s+\S+\s+import|import\s+\S+)/m', $code) === 1;
    }

    /** @return list<string> */
    private function significantLines(string $code): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', $code) ?: []),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
    }
}
