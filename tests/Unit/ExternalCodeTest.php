<?php

declare(strict_types=1);

use Recombinator\Support\ExternalCode;

it('resolves extends and implements of outside files by namespace and use', function (): void {
    $external = new ExternalCode([
        'vendor/a.php'   => '<?php namespace Faker\Provider\en_US; use Faker\Provider\Internet as Base; class Internet extends Base implements \Countable, Local {}',
        'vendor/b.php'   => "<?php namespace App;\nclass E extends\n    \\Exception {}",
        'vendor/own.php' => '<?php namespace Faker; class Generator extends Thing {}',
    ], ['faker_generator' => true]);

    expect($external->extendsOrImplements('Faker_Provider_Internet'))->toBeTrue()
        ->and($external->extendsOrImplements('Faker_Provider_en_US_Local'))->toBeTrue()
        ->and($external->extendsOrImplements('Countable'))->toBeTrue()
        ->and($external->extendsOrImplements('Doctrine_DBAL_Exception'))->toBeFalse()
        ->and($external->extendsOrImplements('Faker_Thing'))->toBeFalse();
});

it('treats only Composer autoload files as always loaded among classless files', function (): void {
    $external = ExternalCode::forProgram([
        'vendor/composer/autoload_files.php' => "<?php return ['x' => \$vendorDir . '/lib/functions.php'];",
        'vendor/lib/functions.php'           => '<?php function lib_helper() { return new Lib\\Used(); }',
        'vendor/lib/Used.php'                => '<?php namespace Lib; class Used { function viaFunctions() {} }',
        'vendor/lib/Other.php'               => '<?php namespace Lib; class Other { function viaBootstrap() {} }',
        'bootstrap.php'                      => '<?php return new Lib\\Other();',
        'public/index.php'                   => '<?php require "../bootstrap.php";',
    ], [], []);

    expect($external->names())->toHaveKey('viafunctions')->not->toHaveKey('viabootstrap');
});
