<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use Recombinator\Domain\NamingSuggester;
use Recombinator\Domain\SideEffectType;

beforeEach(
    function (): void {
        $this->suggester = new NamingSuggester();
    }
);

describe(
    'Variable Name Suggestions', function (): void {
        it(
            'suggests names for binary operations', function (): void {
                $node = new Expr\BinaryOp\Plus(
                    new Scalar\LNumber(1),
                    new Scalar\LNumber(2)
                );

                $suggestions = $this->suggester->suggestVariableName($node);

                expect($suggestions)->toBeArray()
                    ->and($suggestions)->toContain('sum')
                    ->and($suggestions)->toContain('total');
            }
        );

        it(
            'suggests names for function calls', function (): void {
                $node = new Expr\FuncCall(
                    new Node\Name('count'),
                    []
                );

                $suggestions = $this->suggester->suggestVariableName($node);

                expect($suggestions)->toBeArray()
                    ->and($suggestions)->toContain('count')
                    ->and($suggestions)->toContain('total');
            }
        );

        it(
            'suggests names for array access', function (): void {
                $node = new Expr\ArrayDimFetch(
                    new Expr\Variable('_GET'),
                    new Scalar\String_('username')
                );

                $suggestions = $this->suggester->suggestVariableName($node);

                expect($suggestions)->toBeArray()
                    ->and($suggestions)->not->toBeEmpty();
            }
        );

        it(
            'suggests names for ternary operations', function (): void {
                $node = new Expr\Ternary(
                    new Expr\Variable('condition'),
                    new Scalar\String_('yes'),
                    new Scalar\String_('no')
                );

                $suggestions = $this->suggester->suggestVariableName($node);

                expect($suggestions)->toBeArray()
                    ->and($suggestions)->toContain('result');
            }
        );

        it(
            'suggests names for concat operations', function (): void {
                $node = new Expr\BinaryOp\Concat(
                    new Scalar\String_('hello'),
                    new Scalar\String_('world')
                );

                $suggestions = $this->suggester->suggestVariableName($node);

                expect($suggestions)->toBeArray()
                    ->and($suggestions)->toContain('text');
            }
        );
    }
);

describe(
    'Function Name Suggestions', function (): void {
        it(
            'suggests names based on effect type PURE', function (): void {
                $suggestions = $this->suggester->suggestFunctionName([], SideEffectType::PURE);

                expect($suggestions)->toBeArray()
                    ->and($suggestions)->toContain('calculate');
            }
        );

        it(
            'suggests names based on effect type IO', function (): void {
                $suggestions = $this->suggester->suggestFunctionName([], SideEffectType::IO);

                expect($suggestions)->toBeArray()
                    ->and($suggestions)->toContain('display');
            }
        );

        it(
            'suggests names based on effect type DATABASE', function (): void {
                $suggestions = $this->suggester->suggestFunctionName([], SideEffectType::DATABASE);

                expect($suggestions)->toBeArray()
                    ->and($suggestions)->toContain('queryDb');
            }
        );
    }
);

describe(
    'Name Quality Assessment', function (): void {
        it(
            'detects poor names - too short', function (): void {
                expect($this->suggester->isPoorName('x'))->toBeTrue()
                ->and($this->suggester->isPoorName('y'))->toBeTrue()
                ->and($this->suggester->isPoorName('a'))->toBeTrue();
            }
        );

        it(
            'allows standard short names', function (): void {
                expect($this->suggester->isPoorName('i'))->toBeFalse()
                ->and($this->suggester->isPoorName('j'))->toBeFalse()
                ->and($this->suggester->isPoorName('id'))->toBeFalse();
            }
        );

        it(
            'detects poor names - common bad patterns', function (): void {
                expect($this->suggester->isPoorName('tmp'))->toBeTrue()
                ->and($this->suggester->isPoorName('temp'))->toBeTrue()
                ->and($this->suggester->isPoorName('foo'))->toBeTrue()
                ->and($this->suggester->isPoorName('bar'))->toBeTrue();
            }
        );

        it(
            'detects poor names - number suffixes', function (): void {
                expect($this->suggester->isPoorName('a1'))->toBeTrue()
                ->and($this->suggester->isPoorName('x2'))->toBeTrue();
            }
        );

        it(
            'accepts good names', function (): void {
                expect($this->suggester->isPoorName('username'))->toBeFalse()
                ->and($this->suggester->isPoorName('totalCount'))->toBeFalse()
                ->and($this->suggester->isPoorName('user_id'))->toBeFalse();
            }
        );
    }
);

describe(
    'Name Quality Scoring', function (): void {
        it(
            'gives high scores to good names', function (): void {
                $score = $this->suggester->scoreNameQuality('username');
                expect($score)->toBeGreaterThan(5);

                $score = $this->suggester->scoreNameQuality('totalCount');
                expect($score)->toBeGreaterThan(5);
            }
        );

        it(
            'gives low scores to poor names', function (): void {
                $score = $this->suggester->scoreNameQuality('x');
                expect($score)->toBeLessThan(5);

                $score = $this->suggester->scoreNameQuality('tmp');
                expect($score)->toBeLessThan(5);
            }
        );

        it(
            'penalizes very long names', function (): void {
                $longName = 'thisIsAVeryLongVariableNameThatIsProbablyTooLong';
                $score = $this->suggester->scoreNameQuality($longName);
                expect($score)->toBeLessThan(8);
            }
        );

        it(
            'scores are in valid range 0-10', function (): void {
                $names = ['x', 'tmp', 'username', 'veryLongNameHere', 'id', 'foo'];

                foreach ($names as $name) {
                    $score = $this->suggester->scoreNameQuality($name);
                    expect($score)->toBeGreaterThanOrEqual(0)
                        ->and($score)->toBeLessThanOrEqual(10);
                }
            }
        );
    }
);
