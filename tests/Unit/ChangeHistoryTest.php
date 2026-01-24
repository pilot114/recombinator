<?php

declare(strict_types=1);

use PhpParser\Node\Expr\Variable;
use Recombinator\Interactive\Change;
use Recombinator\Interactive\ChangeHistory;

beforeEach(
    function (): void {
        $this->history = new ChangeHistory();
    }
);

describe(
    'Adding Changes',
    function (): void {
        it(
            'adds a change to history',
            function (): void {
                $node = new Variable('x');
                $change = new Change(
                    Change::TYPE_RENAME,
                    'Renamed variable',
                    $node,
                    ['old' => 'x'],
                    ['new' => 'username']
                );

                $this->history->addChange($change);

                expect($this->history->getCount())->toBe(1)
                ->and($this->history->getCurrentChange())->toBe($change);
            }
        );

        it(
            'adds multiple changes',
            function (): void {
                for ($i = 1; $i <= 5; $i++) {
                    $node = new Variable('x' . $i);
                    $change = new Change(
                        Change::TYPE_RENAME,
                        'Change ' . $i,
                        $node,
                        [],
                        []
                    );
                    $this->history->addChange($change);
                }

                expect($this->history->getCount())->toBe(5);
            }
        );
    }
);

describe(
    'Undo/Redo',
    function (): void {
        beforeEach(
            function (): void {
                // Добавляем 3 изменения
                for ($i = 1; $i <= 3; $i++) {
                    $node = new Variable('x' . $i);
                    $change = new Change(
                        Change::TYPE_RENAME,
                        'Change ' . $i,
                        $node,
                        [],
                        []
                    );
                    $this->history->addChange($change);
                }
            }
        );

        it(
            'can undo a change',
            function (): void {
                expect($this->history->canUndo())->toBeTrue();

                $change = $this->history->undo();

                expect($change)->not->toBeNull()
                    ->and($change->getDescription())->toBe('Change 3');
            }
        );

        it(
            'can undo multiple changes',
            function (): void {
                $this->history->undo();
                $this->history->undo();

                $change = $this->history->undo();

                expect($change->getDescription())->toBe('Change 1')
                ->and($this->history->canUndo())->toBeFalse();
            }
        );

        it(
            'returns null when nothing to undo',
            function (): void {
                $this->history->undo();
                $this->history->undo();
                $this->history->undo();

                $result = $this->history->undo();

                expect($result)->toBeNull();
            }
        );

        it(
            'can redo a change',
            function (): void {
                $this->history->undo();

                expect($this->history->canRedo())->toBeTrue();

                $change = $this->history->redo();

                expect($change)->not->toBeNull()
                    ->and($change->getDescription())->toBe('Change 3');
            }
        );

        it(
            'can redo multiple changes',
            function (): void {
                $this->history->undo();
                $this->history->undo();
                $this->history->undo();

                $this->history->redo();
                $this->history->redo();

                $change = $this->history->redo();

                expect($change->getDescription())->toBe('Change 3')
                ->and($this->history->canRedo())->toBeFalse();
            }
        );

        it(
            'returns null when nothing to redo',
            function (): void {
                $result = $this->history->redo();

                expect($result)->toBeNull();
            }
        );
    }
);

describe(
    'History Navigation',
    function (): void {
        beforeEach(
            function (): void {
                for ($i = 1; $i <= 3; $i++) {
                    $node = new Variable('x' . $i);
                    $change = new Change(
                        Change::TYPE_RENAME,
                        'Change ' . $i,
                        $node,
                        [],
                        []
                    );
                    $this->history->addChange($change);
                }
            }
        );

        it(
            'tracks current position correctly',
            function (): void {
                expect($this->history->getCurrentChange()->getDescription())->toBe('Change 3');

                $this->history->undo();
                expect($this->history->getCurrentChange()->getDescription())->toBe('Change 2');

                $this->history->undo();
                expect($this->history->getCurrentChange()->getDescription())->toBe('Change 1');
            }
        );

        it(
            'clears future history on new change after undo',
            function (): void {
                $this->history->undo();
                $this->history->undo();

                // Добавляем новое изменение после отката
                $node = new Variable('new');
                $change = new Change(
                    Change::TYPE_RENAME,
                    'New change',
                    $node,
                    [],
                    []
                );
                $this->history->addChange($change);

                expect($this->history->getCount())->toBe(2)
                ->and($this->history->canRedo())->toBeFalse();
            }
        );
    }
);

describe(
    'History Management',
    function (): void {
        it(
            'can clear history',
            function (): void {
                $node = new Variable('x');
                $change = new Change(Change::TYPE_RENAME, 'Test', $node, [], []);
                $this->history->addChange($change);

                expect($this->history->getCount())->toBe(1);

                $this->history->clear();

                expect($this->history->getCount())->toBe(0)
                ->and($this->history->canUndo())->toBeFalse()
                ->and($this->history->canRedo())->toBeFalse();
            }
        );

        it(
            'returns all changes',
            function (): void {
                for ($i = 1; $i <= 3; $i++) {
                    $node = new Variable('x' . $i);
                    $change = new Change(Change::TYPE_RENAME, 'Change ' . $i, $node, [], []);
                    $this->history->addChange($change);
                }

                $all = $this->history->getAllChanges();

                expect($all)->toHaveCount(3)
                    ->and($all[0]->getDescription())->toBe('Change 1')
                    ->and($all[2]->getDescription())->toBe('Change 3');
            }
        );

        it(
            'generates summary',
            function (): void {
                $node = new Variable('x');
                $change = new Change(Change::TYPE_RENAME, 'Test', $node, [], []);
                $this->history->addChange($change);

                $summary = $this->history->getSummary();

                expect($summary)->toBeString()
                    ->and($summary)->toContain('Changes:')
                    ->and($summary)->toContain('Undo:')
                    ->and($summary)->toContain('Redo:');
            }
        );
    }
);

describe(
    'Change Object',
    function (): void {
        it(
            'creates change with all properties',
            function (): void {
                $node = new Variable('x');
                $timestamp = time();

                $change = new Change(
                    Change::TYPE_RENAME,
                    'Test change',
                    $node,
                    ['old' => 'x'],
                    ['new' => 'username'],
                    $timestamp
                );

                expect($change->getType())->toBe(Change::TYPE_RENAME)
                ->and($change->getDescription())->toBe('Test change')
                ->and($change->getNode())->toBe($node)
                ->and($change->getBeforeState())->toBe(['old' => 'x'])
                ->and($change->getAfterState())->toBe(['new' => 'username'])
                ->and($change->getTimestamp())->toBe($timestamp);
            }
        );

        it(
            'auto-generates timestamp',
            function (): void {
                $node = new Variable('x');
                $before = time();

                $change = new Change(
                    Change::TYPE_RENAME,
                    'Test',
                    $node,
                    [],
                    []
                );

                $after = time();

                expect($change->getTimestamp())->toBeGreaterThanOrEqual($before)
                ->and($change->getTimestamp())->toBeLessThanOrEqual($after);
            }
        );

        it(
            'formats change correctly',
            function (): void {
                $node = new Variable('x');
                $change = new Change(
                    Change::TYPE_RENAME,
                    'Renamed variable',
                    $node,
                    [],
                    []
                );

                $formatted = $change->format();

                expect($formatted)->toBeString()
                    ->and($formatted)->toContain('RENAME')
                    ->and($formatted)->toContain('Renamed variable');
            }
        );
    }
);
