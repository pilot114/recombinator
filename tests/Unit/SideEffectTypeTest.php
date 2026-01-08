<?php

declare(strict_types=1);

use Recombinator\Domain\SideEffectType;

it('has correct priority values', function () {
    expect(SideEffectType::PURE->getPriority())->toBe(0)
        ->and(SideEffectType::NON_DETERMINISTIC->getPriority())->toBe(1)
        ->and(SideEffectType::EXTERNAL_STATE->getPriority())->toBe(2)
        ->and(SideEffectType::IO->getPriority())->toBe(3)
        ->and(SideEffectType::GLOBAL_STATE->getPriority())->toBe(4)
        ->and(SideEffectType::DATABASE->getPriority())->toBe(5)
        ->and(SideEffectType::HTTP->getPriority())->toBe(6)
        ->and(SideEffectType::MIXED->getPriority())->toBe(7);
});

it('correctly identifies pure code', function () {
    expect(SideEffectType::PURE->isPure())->toBeTrue()
        ->and(SideEffectType::IO->isPure())->toBeFalse()
        ->and(SideEffectType::DATABASE->isPure())->toBeFalse()
        ->and(SideEffectType::MIXED->isPure())->toBeFalse();
});

it('correctly identifies compile-time evaluable code', function () {
    expect(SideEffectType::PURE->isCompileTimeEvaluable())->toBeTrue()
        ->and(SideEffectType::NON_DETERMINISTIC->isCompileTimeEvaluable())->toBeFalse()
        ->and(SideEffectType::IO->isCompileTimeEvaluable())->toBeFalse()
        ->and(SideEffectType::DATABASE->isCompileTimeEvaluable())->toBeFalse();
});

it('correctly identifies cacheable code', function () {
    expect(SideEffectType::PURE->isCacheable())->toBeTrue()
        ->and(SideEffectType::EXTERNAL_STATE->isCacheable())->toBeTrue()
        ->and(SideEffectType::NON_DETERMINISTIC->isCacheable())->toBeFalse()
        ->and(SideEffectType::IO->isCacheable())->toBeFalse()
        ->and(SideEffectType::DATABASE->isCacheable())->toBeFalse()
        ->and(SideEffectType::HTTP->isCacheable())->toBeFalse();
});

it('returns correct descriptions', function () {
    expect(SideEffectType::PURE->getDescription())
        ->toContain('Чистый код')
        ->and(SideEffectType::IO->getDescription())
        ->toContain('I/O операции')
        ->and(SideEffectType::DATABASE->getDescription())
        ->toContain('базой данных');
});

it('combines PURE with other type correctly', function () {
    $result = SideEffectType::PURE->combine(SideEffectType::IO);
    expect($result)->toBe(SideEffectType::IO);
});

it('combines same types correctly', function () {
    $result = SideEffectType::IO->combine(SideEffectType::IO);
    expect($result)->toBe(SideEffectType::IO);
});

it('combines different types to MIXED', function () {
    $result = SideEffectType::IO->combine(SideEffectType::DATABASE);
    expect($result)->toBe(SideEffectType::MIXED);
});

it('combines MIXED with any type to MIXED', function () {
    expect(SideEffectType::MIXED->combine(SideEffectType::PURE))->toBe(SideEffectType::MIXED)
        ->and(SideEffectType::IO->combine(SideEffectType::MIXED))->toBe(SideEffectType::MIXED);
});

it('has all required enum cases', function () {
    $cases = SideEffectType::cases();
    $values = array_map(fn($case) => $case->value, $cases);

    expect($values)->toContain('pure')
        ->and($values)->toContain('io')
        ->and($values)->toContain('external_state')
        ->and($values)->toContain('global_state')
        ->and($values)->toContain('database')
        ->and($values)->toContain('http')
        ->and($values)->toContain('non_deterministic')
        ->and($values)->toContain('mixed');
});

it('can be created from string value', function () {
    $pure = SideEffectType::from('pure');
    expect($pure)->toBe(SideEffectType::PURE);

    $io = SideEffectType::from('io');
    expect($io)->toBe(SideEffectType::IO);
});

it('combines multiple effects correctly', function () {
    // PURE + PURE = PURE
    $result = SideEffectType::PURE
        ->combine(SideEffectType::PURE);
    expect($result)->toBe(SideEffectType::PURE);

    // PURE + IO = IO
    $result = SideEffectType::PURE
        ->combine(SideEffectType::IO);
    expect($result)->toBe(SideEffectType::IO);

    // IO + DATABASE = MIXED
    $result = SideEffectType::IO
        ->combine(SideEffectType::DATABASE);
    expect($result)->toBe(SideEffectType::MIXED);

    // Цепочка: PURE + IO + DATABASE = MIXED
    $result = SideEffectType::PURE
        ->combine(SideEffectType::IO)
        ->combine(SideEffectType::DATABASE);
    expect($result)->toBe(SideEffectType::MIXED);
});

it('has correct priority ordering', function () {
    $types = [
        SideEffectType::MIXED,
        SideEffectType::PURE,
        SideEffectType::HTTP,
        SideEffectType::DATABASE,
    ];

    usort($types, fn($a, $b) => $a->getPriority() <=> $b->getPriority());

    expect($types[0])->toBe(SideEffectType::PURE)
        ->and($types[1])->toBe(SideEffectType::DATABASE)
        ->and($types[2])->toBe(SideEffectType::HTTP)
        ->and($types[3])->toBe(SideEffectType::MIXED);
});
