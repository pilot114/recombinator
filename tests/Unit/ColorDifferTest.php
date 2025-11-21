<?php

declare(strict_types=1);

use Recombinator\ColorDiffer;

beforeEach(function () {
    $this->differ = new ColorDiffer();
});

it('can create colored string with foreground color', function () {
    $result = $this->differ->getColoredString('test', 'red');

    expect($result)->toContain('test')
        ->and($result)->toContain("\033[");
});

it('can create colored string with background color', function () {
    $result = $this->differ->getColoredString('test', null, 'red');

    expect($result)->toContain('test')
        ->and($result)->toContain("\033[");
});

it('returns foreground colors', function () {
    $colors = $this->differ->getForegroundColors();

    expect($colors)->toBeArray()
        ->and($colors)->toContain('red')
        ->and($colors)->toContain('green')
        ->and($colors)->toContain('blue');
});

it('returns background colors', function () {
    $colors = $this->differ->getBackgroundColors();

    expect($colors)->toBeArray()
        ->and($colors)->toContain('red')
        ->and($colors)->toContain('green')
        ->and($colors)->toContain('blue');
});

it('detects diff between strings', function () {
    $a = "hello world";
    $b = "hello universe";

    $result = $this->differ->diff($a, $b);

    expect($this->differ->hasDiff)->toBeTrue()
        ->and($result)->toBeString();
});

it('detects no diff for identical strings', function () {
    $a = "hello world";
    $b = "hello world";

    $result = $this->differ->diff($a, $b);

    expect($this->differ->hasDiff)->toBeFalse();
});

it('can show not modified lines', function () {
    $a = "hello\nworld";
    $b = "hello\nuniverse";

    $result = $this->differ->diff($a, $b, true);

    expect($result)->toBeString();
});
