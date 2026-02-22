<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\CodeBlockVisitor;

function applyCodeBlocks(string $code): string
{
    $parser  = new ParserFactory()->createForHostVersion();
    $printer = new StandardPrinter();

    $ast = $parser->parse($code) ?? [];

    $t = new NodeTraverser();
    $t->addVisitor(new CodeBlockVisitor());

    $ast = $t->traverse($ast);

    return $printer->prettyPrint($ast);
}

// ── Block headers ─────────────────────────────────────────────────────────────

it('adds "Чтение глобального состояния" header for superglobal assignment', function (): void {
    $result = applyCodeBlocks('<?php $x = $_GET["key"] ?? "default";');

    expect($result)->toContain('# Чтение глобального состояния');
});

it('adds "Вывод" header for echo', function (): void {
    $result = applyCodeBlocks('<?php echo "hello";');

    expect($result)->toContain('# Вывод');
});

it('adds "Вычисления" header for plain assignment', function (): void {
    $result = applyCodeBlocks('<?php $x = 1 + 2;');

    expect($result)->toContain('# Вычисления');
});

it('adds "Возврат значения" header for return', function (): void {
    $result = applyCodeBlocks('<?php return $x;');

    expect($result)->toContain('# Возврат значения');
});

it('adds "Управление потоком" header for if statement', function (): void {
    $result = applyCodeBlocks('<?php if ($x) { echo "yes"; }');

    expect($result)->toContain('# Управление потоком');
});

// ── Grouping — one header per consecutive run ─────────────────────────────────

it('emits one header for multiple consecutive superglobal reads', function (): void {
    $code = '<?php $a = $_GET["a"]; $b = $_POST["b"];';
    $result = applyCodeBlocks($code);

    expect(substr_count($result, '# Чтение глобального состояния'))->toBe(1);
});

it('emits one header for multiple consecutive echo statements', function (): void {
    $code = '<?php echo "a"; echo "b"; echo "c";';
    $result = applyCodeBlocks($code);

    expect(substr_count($result, '# Вывод'))->toBe(1);
});

// ── Blank lines between groups ────────────────────────────────────────────────

it('separates different groups with a blank line', function (): void {
    $code = '<?php $x = $_GET["x"]; echo $x;';
    $result = applyCodeBlocks($code);

    expect($result)->toContain("\n\n");
    expect($result)->toContain('# Чтение глобального состояния');
    expect($result)->toContain('# Вывод');
});

it('produces no blank line before the very first block', function (): void {
    $code = '<?php echo "hi";';
    $result = applyCodeBlocks($code);

    // Output starts directly with the comment (after ltrim)
    expect($result)->toStartWith('# Вывод');
});

// ── Full motivating example ───────────────────────────────────────────────────

it('correctly groups a typical recombinator output', function (): void {
    $code = <<<'PHP'
    <?php
    $tmp1 = $_GET['username'] ?? 'guest';
    $tmp2 = $_GET['pass'] ?? '';
    $ok = $tmp1 . '_' . $tmp2 === 'admin_secret';
    echo "result: {$ok}\n";
    PHP;

    $result = applyCodeBlocks($code);

    expect($result)->toContain('# Чтение глобального состояния');
    expect($result)->toContain('# Вычисления');
    expect($result)->toContain('# Вывод');

    // Each group boundary has a blank line
    expect(substr_count($result, "\n\n"))->toBeGreaterThanOrEqual(2);

    // Headers appear in the right order
    $posInput   = strpos($result, '# Чтение');
    $posCompute = strpos($result, '# Вычисления');
    $posOutput  = strpos($result, '# Вывод');
    expect($posInput)->toBeLessThan($posCompute);
    expect($posCompute)->toBeLessThan($posOutput);
});
