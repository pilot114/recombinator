<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\ProgramEvaluatorVisitor;

function applyProgramEvaluator(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new ProgramEvaluatorVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('replaces a deterministic program with its output', function (): void {
    $result = applyProgramEvaluator(
        '<?php declare(strict_types=1);
        final class Log { private static array $e = []; static function add(string $m): void { self::$e[] = $m; }
            static function dump(): string { return implode(",", self::$e); } }
        try { throw new RuntimeException("boom"); } catch (RuntimeException $e) { Log::add($e->getMessage()); }
        Log::add("done"); echo Log::dump(), PHP_EOL;',
    );

    expect($result)->toBe("echo 'boom,done\n';");
});

it('leaves programs with side effects, environment or failures untouched', function (string $code): void {
    $original = new Printer()->prettyPrint(new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? []);

    expect(applyProgramEvaluator('<?php ' . $code))->toBe($original);
})->with([
    'time'          => 'echo time();',
    'superglobal'   => 'echo $_GET["x"] ?? "";',
    'file'          => 'echo file_get_contents("/etc/hostname");',
    'callable name' => '$f = "system"; $f("id");',
    'directory'     => 'echo __DIR__;',
    'exception'     => 'echo "a"; throw new LogicException("x");',
    'warning'       => '$a = []; echo $a["missing"];',
]);
