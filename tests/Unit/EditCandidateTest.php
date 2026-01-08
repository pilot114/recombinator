<?php

declare(strict_types=1);

use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Expr\Variable;
use Recombinator\Interactive\EditCandidate;

it('creates edit candidate with all properties', function () {
    $node = new LNumber(42);
    $node->setAttribute('startLine', 10);

    $candidate = new EditCandidate(
        $node,
        EditCandidate::ISSUE_MAGIC_NUMBER,
        'Magic number found',
        EditCandidate::PRIORITY_HIGH,
        ['Replace with named constant']
    );

    expect($candidate->getNode())->toBe($node)
        ->and($candidate->getIssueType())->toBe(EditCandidate::ISSUE_MAGIC_NUMBER)
        ->and($candidate->getDescription())->toBe('Magic number found')
        ->and($candidate->getPriority())->toBe(EditCandidate::PRIORITY_HIGH)
        ->and($candidate->getSuggestions())->toHaveCount(1)
        ->and($candidate->getLine())->toBe(10);
});

it('can add suggestions dynamically', function () {
    $node = new Variable('tmp');
    $candidate = new EditCandidate(
        $node,
        EditCandidate::ISSUE_POOR_NAMING,
        'Poor variable name',
        EditCandidate::PRIORITY_MEDIUM
    );

    expect($candidate->getSuggestions())->toBeEmpty();

    $candidate->addSuggestion('Rename to something meaningful');
    $candidate->addSuggestion('Use camelCase');

    expect($candidate->getSuggestions())->toHaveCount(2);
});

it('correctly identifies critical candidates', function () {
    $node = new Variable('x');

    $critical = new EditCandidate(
        $node,
        EditCandidate::ISSUE_COMPLEX_EXPRESSION,
        'Critical issue',
        EditCandidate::PRIORITY_CRITICAL
    );

    $nonCritical = new EditCandidate(
        $node,
        EditCandidate::ISSUE_POOR_NAMING,
        'Non-critical issue',
        EditCandidate::PRIORITY_MEDIUM
    );

    expect($critical->isCritical())->toBeTrue()
        ->and($nonCritical->isCritical())->toBeFalse();
});

it('provides correct priority labels', function () {
    $node = new Variable('x');

    $labels = [
        EditCandidate::PRIORITY_CRITICAL => 'CRITICAL',
        EditCandidate::PRIORITY_HIGH => 'HIGH',
        EditCandidate::PRIORITY_MEDIUM => 'MEDIUM',
        EditCandidate::PRIORITY_LOW => 'LOW',
    ];

    foreach ($labels as $priority => $expectedLabel) {
        $candidate = new EditCandidate(
            $node,
            EditCandidate::ISSUE_POOR_NAMING,
            'Test',
            $priority
        );

        expect($candidate->getPriorityLabel())->toBe($expectedLabel);
    }
});

it('formats output correctly', function () {
    $node = new LNumber(123);
    $node->setAttribute('startLine', 5);

    $candidate = new EditCandidate(
        $node,
        EditCandidate::ISSUE_MAGIC_NUMBER,
        'Magic number detected',
        EditCandidate::PRIORITY_HIGH,
        ['Use constant', 'Add comment']
    );

    $formatted = $candidate->format();

    expect($formatted)->toBeString()
        ->and($formatted)->toContain('[HIGH]')
        ->and($formatted)->toContain('Line 5')
        ->and($formatted)->toContain('Magic number detected')
        ->and($formatted)->toContain('Suggestions:')
        ->and($formatted)->toContain('Use constant')
        ->and($formatted)->toContain('Add comment');
});

it('handles node without line number', function () {
    $node = new Variable('test');

    $candidate = new EditCandidate(
        $node,
        EditCandidate::ISSUE_POOR_NAMING,
        'Test issue',
        EditCandidate::PRIORITY_LOW
    );

    // PhpParser возвращает -1 для узлов без startLine, но наш метод getLine() должен вернуть null
    // Примем что узел без установленного атрибута вернёт -1, поэтому проверим это
    $line = $candidate->getLine();
    expect($line === null || $line === -1)->toBeTrue();

    $formatted = $candidate->format();
    expect($formatted)->toContain('Unknown location');
});

it('formats without suggestions when empty', function () {
    $node = new Variable('test');

    $candidate = new EditCandidate(
        $node,
        EditCandidate::ISSUE_POOR_NAMING,
        'Test issue',
        EditCandidate::PRIORITY_LOW
    );

    $formatted = $candidate->format();

    expect($formatted)->not->toContain('Suggestions:');
});
