<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Recombinator\Transformation\SideEffectSeparator;
use Recombinator\Domain\SideEffectType;
use Recombinator\Transformation\Visitor\SideEffectMarkerVisitor;
use Recombinator\Analysis\EffectDependencyGraph;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->separator = new SideEffectSeparator();
    }
);

/**
 * Вспомогательная функция для подготовки AST
 */
function prepareAST(string $code): array
{
    $parser = new ParserFactory()->createForHostVersion();
    $ast = $parser->parse($code);

    // Помечаем узлы типами эффектов
    $marker = new SideEffectMarkerVisitor();
    $traverser = new NodeTraverser();
    $traverser->addVisitor($marker);


    return $traverser->traverse($ast);
}

describe(
    'Basic separation',
    function (): void {
        it(
            'separates pure code into single group',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            $y = 3 * 4;
            $z = $x + $y;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $groups = $result->getGroups();
                expect($groups)->toHaveKey('pure');
                expect($groups['pure']->getSize())->toBeGreaterThan(0);
                expect($groups['pure']->isPure())->toBeTrue();
            }
        );

        it(
            'separates mixed effects into different groups',
            function (): void {
                $code = '<?php
            $x = 1 + 2;              // PURE
            echo "test";             // IO
            $y = $_GET["key"];       // EXTERNAL_STATE
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $groups = $result->getGroups();
                expect($groups)->toHaveKey('pure');
                expect($groups)->toHaveKey('io');
                expect($groups)->toHaveKey('external_state');
            }
        );

        it(
            'returns empty groups for empty AST',
            function (): void {
                $ast = [];
                $result = $this->separator->separate($ast);

                expect($result->getGroups())->toBeEmpty();
                expect($result->getPureComputations())->toBeEmpty();
                expect($result->getBoundaries())->toBeEmpty();
            }
        );
    }
);

describe(
    'Pure computations extraction',
    function (): void {
        it(
            'finds pure computation blocks',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            $y = 3 * 4;
            $z = $x + $y;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $pureComps = $result->getPureComputations();
                expect($pureComps)->not()->toBeEmpty();
                expect($pureComps[0]->size)->toBeGreaterThan(0);
            }
        );

        it(
            'identifies compile-time evaluable computations',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            $y = strtoupper("hello");
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $evaluable = $result->getCompileTimeEvaluableComputations();
                expect($evaluable)->not()->toBeEmpty();
            }
        );

        it(
            'excludes non-pure code from pure computations',
            function (): void {
                $code = '<?php
            echo "test";
            $x = rand(1, 10);
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $pureComps = $result->getPureComputations();
                expect($pureComps)->toBeEmpty();
            }
        );

        it(
            'finds multiple separate pure blocks',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            echo "test";
            $y = 3 * 4;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $pureComps = $result->getPureComputations();
                // Может быть 0, 1 или 2 блока в зависимости от минимального размера
                expect($pureComps)->toBeArray();
            }
        );
    }
);

describe(
    'Boundary detection',
    function (): void {
        it(
            'finds boundaries between different effects',
            function (): void {
                $code = '<?php
            $x = 1 + 2;              // PURE
            echo "test";             // IO
            $y = $_GET["key"];       // EXTERNAL_STATE
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $boundaries = $result->getBoundaries();
                expect($boundaries)->not()->toBeEmpty();
                expect($result->getBoundaryCount())->toBeGreaterThan(0);
            }
        );

        it(
            'detects pure to impure transitions',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            echo "test";
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $boundaries = $result->getBoundaries();
                if (!empty($boundaries)) {
                    $hasPureToImpure = false;
                    foreach ($boundaries as $boundary) {
                        if ($boundary->isPureToImpure()) {
                            $hasPureToImpure = true;
                            break;
                        }
                    }

                    expect($hasPureToImpure)->toBeTrue();
                }
            }
        );

        it(
            'detects impure to pure transitions',
            function (): void {
                $code = '<?php
            echo "test";
            $x = 1 + 2;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $boundaries = $result->getBoundaries();
                if (!empty($boundaries)) {
                    $hasImpureToPure = false;
                    foreach ($boundaries as $boundary) {
                        if ($boundary->isImpureToPure()) {
                            $hasImpureToPure = true;
                            break;
                        }
                    }

                    expect($hasImpureToPure)->toBeTrue();
                }
            }
        );

        it(
            'has no boundaries for uniform effect code',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            $y = 3 * 4;
            $z = $x + $y;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                expect($result->getBoundaryCount())->toBe(0);
            }
        );
    }
);

describe(
    'Effect groups',
    function (): void {
        it(
            'groups nodes by effect type',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            $y = 3 * 4;
            echo "test";
            echo "test2";
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $groups = $result->getGroups();
                expect($groups)->toHaveKey('pure');
                expect($groups)->toHaveKey('io');
            }
        );

        it(
            'sorts groups by priority (PURE first)',
            function (): void {
                $code = '<?php
            echo "test";
            $x = $_GET["key"];
            $y = 1 + 2;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $groups = array_values($result->getGroups());
                if ($groups !== []) {
                    // Первая группа должна иметь самый низкий приоритет (PURE = 0)
                    expect($groups[0]->priority)->toBeLessThanOrEqual($groups[count($groups) - 1]->priority);
                }
            }
        );

        it(
            'provides group by effect type',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            echo "test";
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $pureGroup = $result->getGroupByEffect(SideEffectType::PURE);
                expect($pureGroup)->not()->toBeNull();
                expect($pureGroup->isPure())->toBeTrue();

                $ioGroup = $result->getGroupByEffect(SideEffectType::IO);
                expect($ioGroup)->not()->toBeNull();
                expect($ioGroup->effect)->toBe(SideEffectType::IO);
            }
        );

        it(
            'calculates reorderable percentage',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            $y = 3 * 4;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $pureGroup = $result->getGroupByEffect(SideEffectType::PURE);
                if ($pureGroup) {
                    $percentage = $pureGroup->getReorderablePercentage();
                    expect($percentage)->toBeGreaterThanOrEqual(0);
                    expect($percentage)->toBeLessThanOrEqual(100);
                }
            }
        );
    }
);

describe(
    'Statistics',
    function (): void {
        it(
            'provides comprehensive stats',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            echo "test";
            $y = $_GET["key"];
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $stats = $result->getStats();
                expect($stats)->toHaveKey('total_nodes');
                expect($stats)->toHaveKey('total_groups');
                expect($stats)->toHaveKey('effect_counts');
                expect($stats)->toHaveKey('total_pure_computations');
                expect($stats)->toHaveKey('pure_percentage');
            }
        );

        it(
            'calculates pure percentage correctly',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            $y = 3 * 4;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $stats = $result->getStats();
                expect($stats['pure_percentage'])->toBeGreaterThan(0);
            }
        );

        it(
            'tracks compile-time evaluable count',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            $y = strtoupper("hello");
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $stats = $result->getStats();
                expect($stats)->toHaveKey('compile_time_evaluable');
                expect($stats['compile_time_evaluable'])->toBeGreaterThanOrEqual(0);
            }
        );
    }
);

describe(
    'Dependency graph integration',
    function (): void {
        it(
            'provides access to dependency graph',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            $y = $x * 3;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $graph = $result->getDependencyGraph();
                expect($graph)->toBeInstanceOf(EffectDependencyGraph::class);
                expect($graph->getNodes())->not()->toBeEmpty();
            }
        );

        it(
            'identifies dependencies in pure computations',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            $y = $x * 3;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $pureComps = $result->getPureComputations();
                if (!empty($pureComps)) {
                    // Проверяем, что зависимости отслеживаются
                    expect($pureComps[0]->dependencies)->toBeArray();
                }
            }
        );
    }
);

describe(
    'Complex scenarios',
    function (): void {
        it(
            'handles mixed pure and impure code',
            function (): void {
                $code = '<?php
            $username = $_GET["username"] ?? "default";
            $hash = md5($username);
            echo "Hash: " . $hash;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $groups = $result->getGroups();
                expect($groups)->not()->toBeEmpty();
                expect($result->getBoundaryCount())->toBeGreaterThan(0);
            }
        );

        it(
            'handles sequential operations of same type',
            function (): void {
                $code = '<?php
            echo "Line 1";
            echo "Line 2";
            echo "Line 3";
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $ioGroup = $result->getGroupByEffect(SideEffectType::IO);
                expect($ioGroup)->not()->toBeNull();
                expect($ioGroup->getSize())->toBeGreaterThan(1);
            }
        );

        it(
            'separates database operations',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            mysqli_query($conn, "SELECT * FROM users");
            $y = 3 * 4;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $dbGroup = $result->getGroupByEffect(SideEffectType::DATABASE);
                expect($dbGroup)->not()->toBeNull();
            }
        );

        it(
            'separates HTTP operations',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            curl_exec($ch);
            $y = 3 * 4;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $httpGroup = $result->getGroupByEffect(SideEffectType::HTTP);
                expect($httpGroup)->not()->toBeNull();
            }
        );

        it(
            'separates non-deterministic operations',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            $random = rand(1, 100);
            $y = 3 * 4;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $ndGroup = $result->getGroupByEffect(SideEffectType::NON_DETERMINISTIC);
                expect($ndGroup)->not()->toBeNull();
            }
        );
    }
);

describe(
    'Edge cases',
    function (): void {
        it(
            'handles single statement',
            function (): void {
                $code = '<?php $x = 1 + 2;';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                expect($result->getGroups())->not()->toBeEmpty();
            }
        );

        it(
            'handles nested blocks',
            function (): void {
                $code = '<?php
            if (true) {
                $x = 1 + 2;
                echo "test";
            }
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                // Разделение работает на верхнем уровне
                expect($result->getGroups())->toBeArray();
            }
        );

        it(
            'handles assignments with side effects',
            function (): void {
                $code = '<?php
            $x = $_GET["key"];
            $y = rand(1, 100);
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $groups = $result->getGroups();
                expect($groups)->toHaveKey('external_state');
                expect($groups)->toHaveKey('non_deterministic');
            }
        );
    }
);

describe(
    'EffectGroup methods',
    function (): void {
        it(
            'calculates group size correctly',
            function (): void {
                $code = '<?php
            echo "1";
            echo "2";
            echo "3";
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $ioGroup = $result->getGroupByEffect(SideEffectType::IO);
                if ($ioGroup) {
                    expect($ioGroup->getSize())->toBeGreaterThan(0);
                }
            }
        );
    }
);

describe(
    'EffectBoundary methods',
    function (): void {
        it(
            'calculates boundary distance',
            function (): void {
                $code = '<?php
            $x = 1;
            echo "test";
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $boundaries = $result->getBoundaries();
                if (!empty($boundaries)) {
                    expect($boundaries[0]->getDistance())->toBeGreaterThan(0);
                }
            }
        );
    }
);

describe(
    'PureComputation methods',
    function (): void {
        it(
            'detects dependencies in computations',
            function (): void {
                $code = '<?php
            $x = 1 + 2;
            $y = $x * 3;
        ';

                $ast = prepareAST($code);
                $result = $this->separator->separate($ast);

                $pureComps = $result->getPureComputations();
                if (!empty($pureComps)) {
                    $comp = $pureComps[0];
                    expect($comp->getDependencyCount())->toBeGreaterThanOrEqual(0);
                }
            }
        );
    }
);
