<?php

declare(strict_types=1);

use Recombinator\Support\Inliner;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Write a temporary PHP file and return its absolute path.
 */
function writeFile(string $dir, string $name, string $phpCode): string
{
    $path = $dir . '/' . $name;
    file_put_contents($path, $phpCode);

    return $path;
}

// ---------------------------------------------------------------------------
// Setup / teardown
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir() . '/inliner_test_' . uniqid('', true);
    mkdir($this->dir, 0777, true);
});

afterEach(function (): void {
    // Remove all files then the directory
    foreach (glob($this->dir . '/*') as $file) {
        unlink($file);
    }

    rmdir($this->dir);
});

// ---------------------------------------------------------------------------
// Basic include / require
// ---------------------------------------------------------------------------

it(
    'inlines a simple include statement',
    function (): void {
        writeFile($this->dir, 'lib.php', '<?php function greet() { return "hello"; }');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "lib.php"; echo greet();');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('function f1_greet()')
            ->not->toContain('include')
            ->not->toContain('"lib.php"');
    }
);

it(
    'inlines a require statement',
    function (): void {
        writeFile($this->dir, 'lib.php', '<?php function add($a, $b) { return $a + $b; }');
        $entry = writeFile($this->dir, 'entry.php', '<?php require "lib.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('function f1_add(')
            ->not->toContain('require');
    }
);

it(
    'inlines an include_once statement',
    function (): void {
        writeFile($this->dir, 'lib.php', '<?php function helper() {}');
        $entry = writeFile($this->dir, 'entry.php', '<?php include_once "lib.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('function f1_helper()')
            ->not->toContain('include_once');
    }
);

it(
    'inlines a require_once statement',
    function (): void {
        writeFile($this->dir, 'lib.php', '<?php function util() {}');
        $entry = writeFile($this->dir, 'entry.php', '<?php require_once "lib.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('function f1_util()')
            ->not->toContain('require_once');
    }
);

// ---------------------------------------------------------------------------
// Prefix application — functions
// ---------------------------------------------------------------------------

it(
    'prefixes function definition and all its call-sites',
    function (): void {
        writeFile($this->dir, 'math.php', '<?php
function square($n) { return $n * $n; }
$result = square(5);
');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "math.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('function f1_square(')
            ->toContain('f1_square(5)')
            ->not->toContain('function square(')
            ->not->toContain('= square(5)');
    }
);

it(
    'does not prefix built-in PHP function calls',
    function (): void {
        writeFile($this->dir, 'lib.php', '<?php function fmt($s) { return strtoupper(trim($s)); }');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "lib.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('strtoupper(')
            ->toContain('trim(')
            ->not->toContain('f1_strtoupper')
            ->not->toContain('f1_trim');
    }
);

// ---------------------------------------------------------------------------
// Prefix application — classes
// ---------------------------------------------------------------------------

it(
    'prefixes class definition and new expressions',
    function (): void {
        writeFile($this->dir, 'model.php', '<?php
class User {
    public function __construct(public string $name) {}
}
$u = new User("Alice");
');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "model.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('class f1_User')
            ->toContain('new f1_User(')
            ->not->toContain('class User')
            ->not->toContain('new User(');
    }
);

it(
    'prefixes static method calls on included classes',
    function (): void {
        writeFile($this->dir, 'factory.php', '<?php
class Builder {
    public static function make(): static { return new static(); }
}
$b = Builder::make();
');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "factory.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('class f1_Builder')
            ->toContain('f1_Builder::make()')
            ->not->toContain('class Builder {');
    }
);

it(
    'prefixes static property access on included classes',
    function (): void {
        writeFile($this->dir, 'registry.php', '<?php
class Registry {
    public static int $count = 0;
}
$n = Registry::$count;
');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "registry.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('f1_Registry::$count')
            ->not->toContain('class Registry {');
    }
);

it(
    'prefixes class constant access on included classes',
    function (): void {
        writeFile($this->dir, 'status.php', '<?php
class Status {
    const ACTIVE = 1;
}
$s = Status::ACTIVE;
');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "status.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('class f1_Status')
            ->toContain('f1_Status::ACTIVE')
            ->not->toContain('class Status {');
    }
);

it(
    'prefixes instanceof checks for included classes',
    function (): void {
        writeFile($this->dir, 'shape.php', '<?php
class Circle {}
function isCircle($obj) { return $obj instanceof Circle; }
');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "shape.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('instanceof f1_Circle')
            ->not->toContain('instanceof Circle');
    }
);

// ---------------------------------------------------------------------------
// Prefix application — interfaces and traits
// ---------------------------------------------------------------------------

it(
    'prefixes interface definition',
    function (): void {
        writeFile($this->dir, 'contract.php', '<?php
interface Serializable {
    public function serialize(): string;
}
class Doc implements Serializable {
    public function serialize(): string { return ""; }
}
');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "contract.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('interface f1_Serializable')
            ->not->toContain('interface Serializable {');
    }
);

it(
    'prefixes trait definition',
    function (): void {
        writeFile($this->dir, 'traits.php', '<?php
trait Loggable {
    public function log(string $msg): void { echo $msg; }
}
class Service {
    use Loggable;
}
');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "traits.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('trait f1_Loggable')
            ->not->toContain('trait Loggable {');
    }
);

// ---------------------------------------------------------------------------
// Prefix application — constants
// ---------------------------------------------------------------------------

it(
    'prefixes const keyword constant definitions and usages',
    function (): void {
        writeFile($this->dir, 'config.php', '<?php
const VERSION = "1.0";
echo VERSION;
');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "config.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('f1_VERSION')
            ->not->toContain("const VERSION =")
            ->not->toContain("echo VERSION;");
    }
);

it(
    'prefixes define() constant definitions and usages',
    function (): void {
        writeFile($this->dir, 'defs.php', '<?php
define("MAX_RETRY", 3);
$x = MAX_RETRY;
');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "defs.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain("'f1_MAX_RETRY'")
            ->toContain('f1_MAX_RETRY')
            ->not->toContain("'MAX_RETRY'");
    }
);

it(
    'does not prefix built-in constants like true, false, null',
    function (): void {
        writeFile($this->dir, 'flags.php', '<?php
const DEBUG = false;
$a = true;
$b = null;
$c = DEBUG;
');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "flags.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('true')
            ->toContain('null')
            ->toContain('f1_DEBUG')
            ->not->toContain('f1_true')
            ->not->toContain('f1_null');
    }
);

// ---------------------------------------------------------------------------
// Deduplication — include_once / require_once
// ---------------------------------------------------------------------------

it(
    'includes a file only once when include_once is used twice',
    function (): void {
        writeFile($this->dir, 'lib.php', '<?php function shared() {}');
        $entry = writeFile($this->dir, 'entry.php', '<?php
include_once "lib.php";
include_once "lib.php";
');

        $result = new Inliner($entry)->inline();

        // The function definition should appear exactly once
        expect(substr_count($result, 'function f1_shared()'))->toBe(1);
    }
);

it(
    'includes a file only once when require_once is used twice',
    function (): void {
        writeFile($this->dir, 'lib.php', '<?php function sharedReq() {}');
        $entry = writeFile($this->dir, 'entry.php', '<?php
require_once "lib.php";
require_once "lib.php";
');

        $result = new Inliner($entry)->inline();

        expect(substr_count($result, 'function f1_sharedReq()'))->toBe(1);
    }
);

it(
    'includes a file every time when plain include is used twice',
    function (): void {
        writeFile($this->dir, 'lib.php', '<?php function dupFunc() {}');
        $entry = writeFile($this->dir, 'entry.php', '<?php
include "lib.php";
include "lib.php";
');

        $result = new Inliner($entry)->inline();

        // Both occurrences are inlined (different prefix each time)
        expect($result)
            ->toContain('function f1_dupFunc()')
            ->toContain('function f2_dupFunc()');
    }
);

// ---------------------------------------------------------------------------
// Nested includes
// ---------------------------------------------------------------------------

it(
    'inlines nested includes recursively',
    function (): void {
        writeFile($this->dir, 'deep.php', '<?php function deepFunc() { return 42; }');
        writeFile($this->dir, 'middle.php', '<?php include "deep.php"; function midFunc() {}');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "middle.php";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('function f2_deepFunc()')  // deep.php gets f2 (nested)
            ->toContain('function f1_midFunc()')   // middle.php gets f1
            ->not->toContain('include');
    }
);

// ---------------------------------------------------------------------------
// Dynamic / unresolvable includes
// ---------------------------------------------------------------------------

it(
    'leaves dynamic include with variable path unchanged',
    function (): void {
        $entry = writeFile($this->dir, 'entry.php', '<?php
$path = "some_file.php";
include $path;
');

        $result = new Inliner($entry)->inline();

        expect($result)->toContain('include $path');
    }
);

it(
    'leaves include with concatenated path unchanged',
    function (): void {
        $entry = writeFile($this->dir, 'entry.php', '<?php
$dir = __DIR__;
include $dir . "/lib.php";
');

        $result = new Inliner($entry)->inline();

        expect($result)->toContain('include $dir');
    }
);

it(
    'leaves include for a non-existent file unchanged',
    function (): void {
        $entry = writeFile($this->dir, 'entry.php', '<?php include "ghost.php";');

        $result = new Inliner($entry)->inline();

        expect($result)->toContain('"ghost.php"');
    }
);

// ---------------------------------------------------------------------------
// Multiple distinct included files get distinct prefixes
// ---------------------------------------------------------------------------

it(
    'assigns different prefixes to different included files',
    function (): void {
        writeFile($this->dir, 'a.php', '<?php function funcA() {}');
        writeFile($this->dir, 'b.php', '<?php function funcB() {}');
        $entry = writeFile($this->dir, 'entry.php', '<?php
include "a.php";
include "b.php";
');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('function f1_funcA()')
            ->toContain('function f2_funcB()');
    }
);

// ---------------------------------------------------------------------------
// Entry point symbols are NOT prefixed
// ---------------------------------------------------------------------------

it(
    'does not prefix symbols defined in the entry point itself',
    function (): void {
        writeFile($this->dir, 'lib.php', '<?php function libHelper() {}');
        $entry = writeFile($this->dir, 'entry.php', '<?php
function entryFunc() {}
class EntryClass {}
const ENTRY_CONST = 1;
include "lib.php";
');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('function entryFunc()')
            ->toContain('class EntryClass')
            ->toContain('ENTRY_CONST = 1')
            ->toContain('function f1_libHelper()');
    }
);

// ---------------------------------------------------------------------------
// Absolute path in include
// ---------------------------------------------------------------------------

it(
    'resolves absolute paths in include strings',
    function (): void {
        $lib   = writeFile($this->dir, 'abs.php', '<?php function absFunc() {}');
        $entry = writeFile($this->dir, 'entry.php', '<?php include "' . $lib . '";');

        $result = new Inliner($entry)->inline();

        expect($result)
            ->toContain('function f1_absFunc()')
            ->not->toContain('include');
    }
);
