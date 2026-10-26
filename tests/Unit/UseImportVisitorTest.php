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

it('expands references for unresolved use to the full name', function (): void {
    $result = applyUseImport(
        '<?php use Unknown\Foo; $x = new Foo();',
        [],
    );
    // use удалён, класс не встроен — ссылка указывает на исходный класс полным именем
    expect($result)
        ->not->toContain('use Unknown\\Foo')
        ->toContain('new \\Unknown\\Foo()');
});

it('expands use function and use const into full names', function (): void {
    $result = applyUseImport(
        '<?php use function Lib\map as m; use const Lib\EOL; echo m([]), EOL, PHP_EOL;',
        [],
    );
    expect($result)
        ->not->toContain('use function')
        ->not->toContain('use const')
        ->toContain('echo \\Lib\\map([]), \\Lib\\EOL, PHP_EOL;');
});

it('renames calls to namespaced functions inlined from function files', function (): void {
    $result = applyUseImport(
        '<?php function FastRoute_simpleDispatcher() {} $d = \\FastRoute\\simpleDispatcher(); $e = function_exists("FastRoute\\simpleDispatcher");',
        [],
    );
    expect($result)
        ->toContain('$d = \\FastRoute_simpleDispatcher();')
        ->toContain('function_exists("FastRoute_simpleDispatcher")');
});

it('resolves classes referenced through an imported namespace', function (): void {
    $result = applyUseImport(
        '<?php use App\DI; $x = new DI\Doctrine();',
        ['src/DI/Doctrine.php' => "<?php\nnamespace App\\DI;\nclass Doctrine {}"],
    );
    expect($result)
        ->toContain('class App_DI_Doctrine')
        ->toContain('new App_DI_Doctrine()')
        ->not->toContain('DI\\Doctrine');
});

it('resolves namespace imports inside inlined files', function (): void {
    $result = applyUseImport(
        '<?php use App\Slim;',
        [
            'src/Slim.php'          => "<?php\nnamespace App;\nuse Faker;\nclass Slim { function f() { return Faker\\Factory::create(); } }",
            'vendor/Faker/Factory.php' => "<?php\nnamespace Faker;\nclass Factory { static function create() { return 1; } }",
        ],
    );
    expect($result)
        ->toContain('class Faker_Factory')
        ->toContain('Faker_Factory::create()');
});

it('renames short names per file: same short name may mean different classes', function (): void {
    $result = applyUseImport(
        '<?php use App\Provider;',
        [
            'vendor/Faker/Gen.php'               => "<?php\nnamespace Faker;\nuse Faker\\Container\\ContainerInterface;\n"
                . "class Gen { function f(ContainerInterface \$c) {} }",
            'vendor/Faker/Container/ContainerInterface.php' => "<?php\nnamespace Faker\\Container;\ninterface ContainerInterface {}",
            'vendor/Psr/Container/ContainerInterface.php'   => "<?php\nnamespace Psr\\Container;\ninterface ContainerInterface {}",
            'src/Provider.php'                   => "<?php\nnamespace App;\nuse Faker\\Gen;\nuse Psr\\Container\\ContainerInterface;\n"
                . "class Provider { function g(ContainerInterface \$c) {} }",
        ],
    );
    expect($result)
        ->toContain('function f(Faker_Container_ContainerInterface $c)')
        ->toContain('function g(Psr_Container_ContainerInterface $c)');
});

it('resolves same-namespace classes referenced without use', function (): void {
    $result = applyUseImport(
        '<?php use Zoo\Dog;',
        [
            'src/Dog.php'    => "<?php\nnamespace Zoo;\nfinal class Dog extends Animal { function s() { return Status::default(); } }",
            'src/Animal.php' => "<?php\nnamespace Zoo;\nabstract class Animal {}",
            'src/Status.php' => "<?php\nnamespace Zoo;\nenum Status { case A; static function default(): self { return self::A; } }",
        ],
    );
    expect($result)
        ->toContain('abstract class Zoo_Animal')
        ->toContain('final class Zoo_Dog extends Zoo_Animal')
        ->toContain('return Zoo_Status::default();')
        ->toContain('enum Zoo_Status');
});

it('does not rename function calls that share a name with an imported class', function (): void {
    $result = applyUseImport(
        "<?php\nnamespace App;\nuse Lib\\Count;\nclass A { function f(\$x) { return count(\$x) + Count::of(\$x); } }",
        ['src/Count.php' => "<?php\nnamespace Lib;\nclass Count { static function of(\$x) { return 1; } }"],
    );
    expect($result)
        ->toContain('count($x) + Lib_Count::of($x)');
});

it('renames string literals naming inlined classes', function () use ($kernelFile): void {
    $result = applyUseImport(
        '<?php use App\Kernel; $k = new Kernel(); $ok = class_exists(\'App\Kernel\'); $c = "\\\\App\\\\Kernel";',
        ['src/Kernel.php' => $kernelFile],
    );

    expect($result)
        ->toContain("class_exists('App_Kernel')")
        ->toContain('$c = "App_Kernel"')
        ->not->toContain('App\Kernel');
});

it('inlines a class referenced only by a string', function () use ($kernelFile): void {
    $result = applyUseImport(
        '<?php $cls = \'App\Kernel\'; $k = new $cls();',
        ['src/Kernel.php' => $kernelFile],
    );

    expect($result)
        ->toContain('class App_Kernel')
        ->toContain("\$cls = 'App_Kernel';");
    expect(strpos($result, 'class App_Kernel'))->toBeLessThan(strpos($result, '$cls ='));
});

it('does not take a same-named class from another namespace for a string', function () use ($kernelFile): void {
    $result = applyUseImport(
        '<?php $ok = class_exists(\'Other\Vendor\Kernel\');',
        ['src/Kernel.php' => $kernelFile],
    );

    expect($result)
        ->not->toContain('class ')
        ->toContain("'Other\\Vendor\\Kernel'");
});

it('does not inline a class already declared on a previous pass', function () use ($kernelFile): void {
    $result = applyUseImport(
        '<?php class App_Kernel {} use App\Kernel; $k = new Kernel(); $s = \'App\Kernel\';',
        ['src/Kernel.php' => $kernelFile],
    );

    expect(substr_count($result, 'class App_Kernel'))->toBe(1)
        ->and($result)->toContain('new App_Kernel()')
        ->and($result)->toContain("'App_Kernel'");
});

it('does not resolve a name imported from the global namespace as a same-namespace class', function (): void {
    $driver = '<?php namespace Db\Driver; use Generator; use Db\Base; class Driver extends Base { function f(): Generator { yield 1; } }';
    $result = applyUseImport(
        '<?php use Db\Driver\Driver; new Driver();',
        [
            'src/Driver/Driver.php' => $driver,
            'src/Base.php'          => '<?php namespace Db; class Base {}',
            'vendor/faker/src/Generator.php' => '<?php namespace Faker; class Generator {}',
        ],
    );

    expect($result)->toContain('function f(): \\Generator')->not->toContain('class Db_Driver_Generator');
});

it('expands aliases of classes outside the file map', function (): void {
    $file   = '<?php namespace App; use InvalidArgumentException as BaseException; class Err extends BaseException {}';
    $result = applyUseImport('<?php use App\Err; use Ext\Thing as T; throw new Err(); new T();', ['src/Err.php' => $file]);

    expect($result)->toContain('class App_Err extends \InvalidArgumentException')->toContain('new \Ext\Thing()');
});

it('resolves fully qualified class names and checks the namespace of the found file', function (): void {
    $orm = '<?php namespace Doctrine\ORM; class Configuration extends \Doctrine\DBAL\Configuration { function orm() {} }';
    $dbal = '<?php namespace Doctrine\DBAL; class Configuration { function dbal() {} }';
    $result = applyUseImport(
        '<?php use Doctrine\ORM\Configuration; $c = new Configuration(); $x = \strlen("a");',
        [
            'vendor/doctrine/orm/src/Configuration.php'  => $orm,
            'vendor/doctrine/dbal/src/Configuration.php' => $dbal,
        ],
    );

    expect($result)
        ->toContain('class Doctrine_ORM_Configuration extends \Doctrine_DBAL_Configuration')
        ->toMatch('/class Doctrine_DBAL_Configuration\s*\{\s*function dbal/')
        ->toContain('\strlen("a")');
});

it('resolves group use statements', function (): void {
    $factory = '<?php namespace Ny\Factory; use Psr\Msg\{ResponseFactoryInterface, StreamFactoryInterface as SF}; '
        . 'class F implements ResponseFactoryInterface, SF {}';
    $result = applyUseImport(
        '<?php use Ny\Factory\F; use Psr\Msg\{ResponseFactoryInterface as R}; new F(); function f(R $r) {}',
        [
            'vendor/ny/src/Factory/F.php'                    => $factory,
            'vendor/psr/src/ResponseFactoryInterface.php'    => '<?php namespace Psr\Msg; interface ResponseFactoryInterface {}',
            'vendor/psr/src/StreamFactoryInterface.php'      => '<?php namespace Psr\Msg; interface StreamFactoryInterface {}',
        ],
    );

    expect($result)
        ->toContain('class Ny_Factory_F implements Psr_Msg_ResponseFactoryInterface, Psr_Msg_StreamFactoryInterface')
        ->toContain('function f(Psr_Msg_ResponseFactoryInterface $r)');
});

it('inlines conditionally declared classes with every branch', function (): void {
    $trait  = '<?php namespace Ny; if (\PHP_VERSION_ID >= 70400) { trait T { function a(): string {} } } else { trait T { function a() {} } }';
    $result = applyUseImport(
        '<?php use Ny\S; new S();',
        ['src/S.php' => '<?php namespace Ny; class S { use T; }', 'src/T.php' => $trait],
    );

    expect($result)
        ->toContain("if (\\PHP_VERSION_ID >= 70400) {\n    trait Ny_T")
        ->toContain("} else {\n    trait Ny_T")
        ->toContain('use Ny_T;');
});

it('drops strict_types of the entry when a non-strict file is inlined', function (): void {
    $strict    = applyUseImport('<?php declare(strict_types=1); use App\Kernel; new Kernel();', [
        'src/Kernel.php' => '<?php declare(strict_types=1); namespace App; class Kernel {}',
    ]);
    $nonStrict = applyUseImport('<?php declare(strict_types=1); use App\Kernel; new Kernel();', [
        'src/Kernel.php' => '<?php namespace App; class Kernel {}',
    ]);

    expect($strict)->toContain('declare (strict_types=1);')
        ->and($nonStrict)->not->toContain('strict_types');
});

it('declares parents before children', function (): void {
    $result = applyUseImport(
        '<?php class Child extends Mid implements I {} echo 1; class Mid extends Base {} interface I {} class Base {}',
        [],
    );

    expect(strpos($result, 'class Base'))->toBeLessThan(strpos($result, 'class Mid'))
        ->and(strpos($result, 'class Mid'))->toBeLessThan(strpos($result, 'class Child'))
        ->and(strpos($result, 'interface I'))->toBeLessThan(strpos($result, 'class Child'))
        ->and(strpos($result, 'class Child'))->toBeLessThan(strpos($result, 'echo 1'));
});

it('orders conditional declarations before their users', function (): void {
    $result = applyUseImport('<?php class C { use T; } if (PHP_VERSION_ID > 1) { trait T {} } else { trait T {} }', []);

    expect(strpos($result, 'trait T'))->toBeLessThan(strpos($result, 'class C'));
});

it('renames the class part of Class::member strings', function () use ($kernelFile): void {
    $result = applyUseImport(
        '<?php use App\Kernel; new Kernel(); $c = constant(\'App\Kernel::TYPE_\' . $t);',
        ['src/Kernel.php' => $kernelFile],
    );

    expect($result)->toContain("constant('App_Kernel::TYPE_' . \$t)");
});

it('resolves a use that names both a class and a namespace', function (): void {
    $persister = '<?php namespace Orm\P; use Orm\Query; class Persister { function f() { return [new Query(), new Query\Rsm()]; } }';
    $result    = applyUseImport('<?php use Orm\P\Persister; new Persister();', [
        'src/P/Persister.php' => $persister,
        'src/Query.php'       => '<?php namespace Orm; class Query {}',
        'src/Query/Rsm.php'   => '<?php namespace Orm\Query; class Rsm {}',
    ]);

    expect($result)->toContain('new Orm_Query(), new Orm_Query_Rsm()')->toContain('class Orm_Query_Rsm');
});
