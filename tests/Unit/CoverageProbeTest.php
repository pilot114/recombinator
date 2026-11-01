<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Support\CoverageProbe;

it('replaces bodies of functions and methods without executed lines', function (): void {
    $code = <<<'PHP'
        <?php
        function used($x)
        {
            return $x + 1;
        }
        function unused($x)
        {
            return $x * 2;
        }
        abstract class A
        {
            abstract public function m();
            public function ran()
            {
                return 1;
            }
            public function never()
            {
                return 2;
            }
            public function empty()
            {
            }
            public function __destruct()
            {
                echo 3;
            }
        }
        PHP;
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];

    expect(CoverageProbe::stubUnexecuted($ast, [4 => true, 16 => true]))->toBeTrue();

    $result = new Printer()->prettyPrint($ast);
    expect($result)->toContain("return \$x + 1;")->not->toContain('return $x * 2;')
        ->toContain('return 1;')->not->toContain('return 2;')
        ->toContain('abstract public function m();')->toContain('echo 3;')
        ->and(substr_count($result, CoverageProbe::NOT_PROFILED))->toBe(2);
});
