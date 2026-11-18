<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Case 82. */
final class StaticCheckTest extends TestCase
{
    /** T80 */
    public function testNoEvalShellSuppressionOrUnserialize(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/post-embeddings.php', $root . '/functions.php', $root . '/uninstall.php'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        $banned = ['eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'unserialize', 'assert', 'create_function'];
        $found = [];
        foreach ($files as $file) {
            $tokens = \PhpToken::tokenize((string) file_get_contents($file));
            foreach ($tokens as $i => $t) {
                if ($t->text === '@' || $t->text === '`' || $t->is(T_EVAL)) {
                    $found[] = "{$t->text} in $file line {$t->line}";
                }
                if ($t->is(T_STRING) && in_array(strtolower($t->text), $banned, true)) {
                    $next = $tokens[$i + 1] ?? null;
                    $prev = $tokens[$i - 1] ?? null;
                    if ($next !== null && $next->text === '(' && ($prev === null || !in_array($prev->text, ['->', '::', 'function'], true))) {
                        $found[] = "{$t->text}() in $file line {$t->line}";
                    }
                }
            }
        }
        self::assertSame([], $found);
        self::assertGreaterThan(30, count($files));
    }
}
