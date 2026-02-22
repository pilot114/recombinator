<?php

declare(strict_types=1);

namespace Recombinator\Support;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as Printer;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * Вспомогательный класс для консольного вывода конвейера трансформаций.
 *
 * Инкапсулирует: баннеры, разделители, заголовки шагов, цветной diff,
 * применение visitor-ов к AST.
 */
class PipelineConsole
{
    private readonly int $width;

    public function __construct(
        private readonly ColorDiffer $cd,
        ?int $width = null,
    ) {
        $this->width = $width ?? $this->detectWidth();
    }

    // ── Цвет ─────────────────────────────────────────────────────────────────

    public function colorize(string $text, string $color): string
    {
        return $this->cd->getColoredString($text, $color);
    }

    // ── Компоновка вывода ─────────────────────────────────────────────────────

    public function banner(string $title): void
    {
        $bar = str_repeat('═', $this->width);
        echo "\n" . $this->cd->getColoredString($bar, 'cyan') . "\n";
        echo $this->cd->getColoredString('  ' . $title . "\n", 'cyan');
        echo $this->cd->getColoredString($bar, 'cyan') . "\n";
    }

    public function hr(string $char = '─'): void
    {
        echo $this->cd->getColoredString(str_repeat($char, $this->width) . "\n", 'dark_gray');
    }

    public function stepHeader(string $name, string $desc, string $diffText, int $diffCount): void
    {
        $leftWidth = 50;
        $sep       = ' ' . $this->cd->getColoredString('│', 'dark_gray') . ' ';

        // ── Left column: name + wrapped description ───────────────────────────
        $leftLines   = [];
        $leftLines[] = $this->cd->getColoredString('  ' . $name, 'yellow');

        $wrapped = wordwrap($desc, $leftWidth - 2, "\n", true);
        foreach (explode("\n", $wrapped) as $dLine) {
            $leftLines[] = $this->cd->getColoredString('  ' . $dLine, 'light_gray');
        }

        // ── Right column: diff lines wrapped to fit, then count ──────────────
        $rightWidth = max(10, $this->width - $leftWidth - 3); // 3 = strlen(' │ ')
        $rightLines = [];
        foreach ($diffText !== '' ? explode("\n", rtrim($diffText, "\n")) : [] as $line) {
            foreach ($this->wrapLine($line, $rightWidth) as $chunk) {
                $rightLines[] = $chunk;
            }
        }

        $rightLines[] = $this->cd->getColoredString(
            sprintf('  ✓  %d строк изменено', $diffCount),
            'light_green'
        );

        // ── Render side by side ───────────────────────────────────────────────
        echo "\n";
        $this->hr('─');

        $maxLines = max(count($leftLines), count($rightLines));
        for ($i = 0; $i < $maxLines; $i++) {
            $left  = $leftLines[$i] ?? '';
            $right = $rightLines[$i] ?? '';
            echo $this->padToVisible($left, $leftWidth) . $sep . $right . "\n";
        }

        $this->hr('─');
    }

    // ── Diff ─────────────────────────────────────────────────────────────────

    /**
     * Форматирует unified diff с ANSI-цветами.
     * Возвращает [раскрашенная_строка, количество_изменённых_строк].
     *
     * @return array{0: string, 1: int}
     */
    public function colorDiff(string $before, string $after): array
    {
        $differ = new Differ(new UnifiedDiffOutputBuilder(''));
        $raw    = $differ->diff($before, $after);

        $text  = '';
        $count = 0;

        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }

            // skip "--- a" / "+++ b" file headers produced by some builder versions
            if (str_starts_with($line, '--- ')) {
                continue;
            }

            if (str_starts_with($line, '+++ ')) {
                continue;
            }

            if (str_starts_with($line, '@@')) {
                $text .= $this->cd->getColoredString($line, 'dark_gray') . "\n";
            } elseif ($line[0] === '+') {
                $text .= $this->cd->getColoredString($line, 'light_green') . "\n";
                $count++;
            } elseif ($line[0] === '-') {
                $text .= $this->cd->getColoredString($line, 'red') . "\n";
                $count++;
            } else {
                $text .= $this->cd->getColoredString($line, 'dark_gray') . "\n";
            }
        }

        return [$text, $count];
    }

    // ── AST ──────────────────────────────────────────────────────────────────

    /**
     * Применяет NodeConnectingVisitor (родительские ссылки), затем переданный
     * visitor к $ast. Возвращает pretty-print кода *до* трансформации.
     *
     * @param array<Node> $ast
     */
    public function applyVisitor(array &$ast, NodeVisitorAbstract $visitor): string
    {
        $printer = new Printer();
        $before  = $printer->prettyPrint($ast);

        $t1 = new NodeTraverser();
        $t1->addVisitor(new NodeConnectingVisitor());

        $ast = $t1->traverse($ast);

        $t2 = new NodeTraverser();
        $t2->addVisitor($visitor);

        $ast = $t2->traverse($ast);

        return $before;
    }

    // ── Утилиты ───────────────────────────────────────────────────────────────

    /**
     * Pad $s to $width visible characters, ignoring ANSI escape sequences.
     */
    private function padToVisible(string $s, int $width): string
    {
        $visible = mb_strlen(preg_replace('/\e\[[0-9;]*m/', '', $s) ?? '');
        return $s . str_repeat(' ', max(0, $width - $visible));
    }

    /**
     * Splits a (possibly ANSI-coloured) line into chunks of at most $maxWidth
     * visible characters. Each chunk re-applies the original colour code so the
     * terminal state is always properly closed.
     *
     * @return list<string>
     */
    private function wrapLine(string $line, int $maxWidth): array
    {
        // Single-colour line produced by colorDiff: ESC[Xm … content … ESC[0m
        if (preg_match('/^(\e\[[0-9;]*m)(.*?)(\e\[0m)$/su', $line, $m)) {
            $prefix  = $m[1];
            $content = $m[2];
            $suffix  = $m[3];

            if (mb_strlen($content) <= $maxWidth) {
                return [$line];
            }

            $chunks = [];
            while ($content !== '') {
                $chunks[] = $prefix . mb_substr($content, 0, $maxWidth) . $suffix;
                $content   = mb_substr($content, $maxWidth);
            }

            return $chunks;
        }

        // Plain string (no ANSI codes)
        if (mb_strlen($line) <= $maxWidth) {
            return [$line];
        }

        $chunks = [];
        while ($line !== '') {
            $chunks[] = mb_substr($line, 0, $maxWidth);
            $line      = mb_substr($line, $maxWidth);
        }

        return $chunks;
    }

    private function detectWidth(): int
    {
        $w = (int) shell_exec('tput cols 2>/dev/null');

        return $w > 20 ? $w : 80;
    }
}
