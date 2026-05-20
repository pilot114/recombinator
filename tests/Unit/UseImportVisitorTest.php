<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\UseImportVisitor;

function applyUseImport(string $code, array $fileMap): string
{
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $ast    = $parser->parse($code) ?? [];

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());

    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new UseImportVisitor($fileMap));

    $ast = $t2->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

$kernelFile = <<<'PHP'
<?php
namespace App;
class Kernel {
    public function boot(): void {}
}
PHP;

$controllerFile = <<<'PHP'
<?php
namespace App\Controller;
class BlogController {
    public function index(): string { return 'blog'; }
}
PHP;

$traitFile = <<<'PHP'
<?php
namespace App\Support;
trait Loggable {
    public function log(string $msg): void {}
}
PHP;

it('replaces use with class definition using FQCN as name', function () use ($kernelFile): void {
    $result = applyUseImport(
        '<?php use App\Kernel;',
        ['src/Kernel.php' => $kernelFile],
    );
    expect($result)
        ->toContain('class App_Kernel')
        ->not->toContain('use App\Kernel')
        ->not->toContain('namespace App');
});

it('removes namespace wrapper', function () use ($controllerFile): void {
    $result = applyUseImport(
        '<?php use App\Controller\BlogController;',
        ['src/Controller/BlogController.php' => $controllerFile],
    );
    expect($result)
        ->toContain('class App_Controller_BlogController')
        ->not->toContain('namespace App');
});

it('removes use even when class not in fileMap', function (): void {
    $result = applyUseImport(
        '<?php use Some\Unknown\Foo; echo 1;',
        [],
    );
    expect($result)
        ->not->toContain('use Some\Unknown\Foo')
        ->toContain('echo 1');
});

it('does not duplicate already-inlined class', function () use ($kernelFile): void {
    $result = applyUseImport(
        '<?php use App\Kernel; use App\Kernel;',
        ['src/Kernel.php' => $kernelFile],
    );
    expect(substr_count($result, 'class App_Kernel'))->toBe(1);
});

it('handles trait and uses FQCN name', function () use ($traitFile): void {
    $result = applyUseImport(
        '<?php use App\Support\Loggable;',
        ['src/Support/Loggable.php' => $traitFile],
    );
    expect($result)
        ->toContain('trait App_Support_Loggable')
        ->not->toContain('namespace');
});

it('resolves by longer suffix', function () use ($controllerFile): void {
    $result = applyUseImport(
        '<?php use App\Controller\BlogController;',
        ['src/Controller/BlogController.php' => $controllerFile],
    );
    expect($result)->toContain('class App_Controller_BlogController');
});

it('preserves use statements from inlined file', function (): void {
    $kernelFile = <<<'PHP'
    <?php
    namespace App;
    use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
    use Symfony\Component\HttpKernel\Kernel as BaseKernel;
    class Kernel extends BaseKernel {
        use MicroKernelTrait;
    }
    PHP;

    $result = applyUseImport(
        '<?php use App\Kernel;',
        ['src/Kernel.php' => $kernelFile],
    );

    expect($result)
        ->toContain('class App_Kernel')
        ->toContain('use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait')
        ->toContain('use Symfony\Component\HttpKernel\Kernel as BaseKernel')
        ->not->toContain('use App\Kernel')
        ->not->toContain('namespace App');
});

it('renames short-name references in code after inlining', function () use ($kernelFile): void {
    $result = applyUseImport(
        '<?php use App\Kernel; $k = new Kernel(); $x = Kernel::class; function f(Kernel $k): Kernel {}',
        ['src/Kernel.php' => $kernelFile],
    );
    expect($result)
        ->toContain('class App_Kernel')
        ->toContain('new App_Kernel()')
        ->toContain('App_Kernel::class')
        ->toContain('App_Kernel $k')
        ->toContain(': App_Kernel')
        // 'Kernel::class' is a substring of 'App_Kernel::class', so check absence of short name standalone
        ->not->toContain('new Kernel()')
        ->not->toMatch('/(?<!App_)Kernel::class/');
});

it('renames explicit alias after inlining', function () use ($kernelFile): void {
    $result = applyUseImport(
        '<?php use App\Kernel as BaseKernel; $k = new BaseKernel();',
        ['src/Kernel.php' => $kernelFile],
    );
    expect($result)
        ->toContain('class App_Kernel')
        ->toContain('new App_Kernel()')
        ->not->toContain('new BaseKernel()');
});

it('does not rename references for unresolved use', function (): void {
    $result = applyUseImport(
        '<?php use Unknown\Foo; $x = new Foo();',
        [],
    );
    // use removed, but Foo reference stays as-is (class not inlined)
    expect($result)
        ->not->toContain('use Unknown\\Foo')
        ->toContain('new Foo()');
});

it('ignores use function and use const', function (): void {
    $result = applyUseImport(
        '<?php use function array_map; use const PHP_EOL;',
        [],
    );
    expect($result)
        ->toContain('use function array_map')
        ->toContain('use const PHP_EOL');
});
