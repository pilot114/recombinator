<?php

declare(strict_types=1);

it('unrolls loops with break into do-while(false)', function (): void {
    $code = 'class A { static function ok() { return false; } } class B { static function ok() { return true; } }
        $r = null;
        do { foreach (["A", "B"] as $c) { if ($c::ok()) { $r = $c; break 2; } } $r = "none"; } while (false);
        echo $r;';
    $ast    = new PhpParser\ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $result = new PhpParser\PrettyPrinter\Standard()->prettyPrint(
        new PhpParser\NodeTraverser(new Recombinator\Transformation\Visitor\ForeachLiteralUnrollerVisitor())->traverse($ast),
    );

    expect($result)->not->toContain('foreach')->toContain('\A::ok()')->toContain('\B::ok()')
        ->and(shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($result)))->toBe('B');
});
