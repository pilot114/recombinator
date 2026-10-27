<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Core\TargetEnvironment;
use Recombinator\Transformation\Visitor\TargetEnvironmentVisitor;

/**
 * @param array<string, mixed>  $environment
 * @param array<string, string> $files
 */
function applyEnvironment(string $code, array $environment, array $files = [], ?string $projectDir = null): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new TargetEnvironmentVisitor(TargetEnvironment::fromArray($environment), $files, $projectDir))->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('folds environment checks by the target server description', function (): void {
    $result = applyEnvironment(
        '$a = class_exists("Relay\Relay"); $b = extension_loaded("pdo_sqlite"); $c = extension_loaded("redis");
        $d = class_exists("ArrayObject"); $e = interface_exists("Countable"); $f = function_exists("strlen");
        $g = function_exists("apcu_fetch"); $h = \PHP_VERSION_ID >= 80400; $i = getenv("APP_ENV"); $j = getenv("OTHER");
        $k = PHP_OS_FAMILY; $l = class_exists("Vendor\Lib\Thing"); $m = interface_exists("Vendor\Lib\Thing");
        $n = phpversion("relay"); $o = version_compare(phpversion("relay"), "0.20.0", ">="); $p = version_compare(PHP_VERSION, "8.4.0");',
        ['php' => '8.5.10', 'os' => 'Linux', 'extensions' => ['Core', 'SPL', 'standard', 'pdo_sqlite'], 'env' => ['APP_ENV' => 'prod']],
        ['vendor/lib/Thing.php' => "<?php\nnamespace Vendor\\Lib;\nclass Thing {}"],
    );

    expect($result)
        ->toContain('$a = false;')
        ->toContain('$b = true;')
        ->toContain('$c = false;')
        ->toContain('$d = true;')
        ->toContain('$e = true;')
        ->toContain('$f = true;')
        ->toContain('$g = false;')
        ->toContain('$h = 80510 >= 80400;')
        ->toContain("\$i = 'prod';")
        ->toContain('$j = getenv("OTHER");')
        ->toContain("\$k = 'Linux';")
        ->toContain('$l = true;')
        ->toContain('$m = false;')
        ->toContain('$n = false;')
        ->toContain('$o = false;')
        ->toContain('$p = 1;');
});

it('assumes nothing without a description and keeps program declarations to other rules', function (): void {
    expect(applyEnvironment('$a = class_exists("Relay\Relay"); $b = PHP_VERSION_ID;', []))
        ->toContain('$a = class_exists("Relay\\\\Relay");')
        ->toContain('$b = PHP_VERSION_ID;');

    expect(applyEnvironment('class Own {} $a = class_exists("Own");', ['extensions' => ['Core']]))
        ->toContain('$a = class_exists("Own");');
});

it('does not decide on extensions the analyzer cannot inspect', function (): void {
    expect(applyEnvironment('$a = class_exists("Relay\Relay");', ['extensions' => ['Core', 'relay']]))
        ->toContain('$a = class_exists("Relay\\\\Relay");');
});

it('folds checks against program declarations and unknown ini directives', function (): void {
    $result = applyEnvironment(
        'class Base { const ASC = 1; } class Criteria extends Base {} function helper() {}
        if (!function_exists("poly")) { function poly() {} }
        $a = defined("Criteria::ASC"); $b = defined("Criteria::DESC"); $c = function_exists("helper"); $d = function_exists("poly");
        $e = ini_get("apc.enable_cli"); $f = ini_get("memory_limit"); $g = ini_get("display_errors");',
        ['extensions' => ['Core', 'standard', 'SPL'], 'ini' => ['memory_limit' => '256M']],
    );

    expect($result)
        ->toContain('$a = true;')
        ->toContain('$b = false;')
        ->toContain('$c = true;')
        ->toContain('$d = function_exists("poly");')
        ->toContain('if (!false) {')
        ->toContain('$e = false;')
        ->toContain("\$f = '256M';")
        ->toContain('$g = ini_get("display_errors");');
});

it('resolves __DIR__ and existing source files from the entry path', function (): void {
    $result = applyEnvironment(
        '$a = __DIR__ . "/../vendor/autoload.php"; $b = file_exists("/srv/app/public/../settings.php"); $c = file_exists("/srv/app/var/cache");
        require_once __DIR__ . "/../vendor/autoload.php";',
        ['entry' => '/srv/app/public/index.php'],
        ['settings.php' => '<?php return [];', 'public/index.php' => '<?php'],
        '/srv/app',
    );

    expect($result)->toContain("\$a = '/srv/app/public' . \"/../vendor/autoload.php\";")
        ->toContain('$b = true;')
        ->toContain('$c = file_exists("/srv/app/var/cache");')
        ->toContain('require_once __DIR__ . "/../vendor/autoload.php";');
});
