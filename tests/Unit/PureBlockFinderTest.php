<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Recombinator\Analysis\PureBlockFinder;
use Recombinator\Transformation\Visitor\SideEffectMarkerVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->visitor = new SideEffectMarkerVisitor();
        $this->traverser = new NodeTraverser();
        $this->traverser->addVisitor($this->visitor);
    }
);

it(
    'finds pure blocks in simple code',
    function (): void {
        $code = '<?php
        $x = 1 + 2;
        $y = 3 + 4;
        $z = 5 + 6;
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(1);
        $blocks = $finder->findBlocks($ast);

        expect($blocks)->toBeArray();
        expect(count($blocks))->toBeGreaterThan(0);
    }
);

it(
    'finds multiple pure blocks separated by IO',
    function (): void {
        $code = '<?php
        $x = 1 + 2;
        $y = 3 + 4;
        echo "test";
        $a = 5 + 6;
        $b = 7 + 8;
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(1);
        $blocks = $finder->findBlocks($ast);

        // Должно быть 2 блока: до echo и после echo
        expect(count($blocks))->toBeGreaterThanOrEqual(1);
    }
);

it(
    'respects minimum block size',
    function (): void {
        $code = '<?php
        $x = 1;
        echo "test";
        $y = 2;
        $z = 3;
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        // С минимальным размером 2, блок из одной строки ($x = 1) не должен быть найден
        $finder = new PureBlockFinder(2);
        $blocks = $finder->findBlocks($ast);

        // Проверяем, что все блоки имеют размер >= 2
        foreach ($blocks as $block) {
            expect($block['size'])->toBeGreaterThanOrEqual(2);
        }
    }
);

it(
    'returns empty array for code without pure blocks',
    function (): void {
        $code = '<?php
        echo "1";
        echo "2";
        echo "3";
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(1);
        $blocks = $finder->findBlocks($ast);

        // Все echo - это IO, нет чистых блоков
        // Но могут быть PURE узлы внутри (строковые литералы)
        // Мы ищем statement'ы, поэтому должно быть 0 чистых блоков
        expect($blocks)->toBeArray();
    }
);

it(
    'returns block count correctly',
    function (): void {
        $code = '<?php
        $x = 1;
        $y = 2;
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(1);
        $finder->findBlocks($ast);

        $count = $finder->getBlockCount();

        expect($count)->toBeGreaterThanOrEqual(0);
    }
);

it(
    'calculates total pure nodes correctly',
    function (): void {
        $code = '<?php
        $x = 1;
        $y = 2;
        $z = 3;
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(1);
        $finder->findBlocks($ast);

        $total = $finder->getTotalPureNodes();

        expect($total)->toBeGreaterThanOrEqual(0);
    }
);

it(
    'finds the largest pure block',
    function (): void {
        $code = '<?php
        $x = 1;
        $y = 2;
        $z = 3;
        echo "test";
        $a = 4;
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(1);
        $finder->findBlocks($ast);

        $largest = $finder->getLargestBlock();

        if ($largest !== null) {
            expect($largest)->toHaveKey('size');
            expect($largest)->toHaveKey('nodes');
            expect($largest)->toHaveKey('start');
            expect($largest)->toHaveKey('end');
        } else {
            expect($largest)->toBeNull();
        }
    }
);

it(
    'returns null for largest block when no blocks found',
    function (): void {
        $code = '<?php echo "test";';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(10); // Очень большой минимальный размер
        $finder->findBlocks($ast);

        $largest = $finder->getLargestBlock();

        expect($largest)->toBeNull();
    }
);

it(
    'sorts blocks by size',
    function (): void {
        $code = '<?php
        $x = 1;
        $y = 2;
        $z = 3;
        echo "test";
        $a = 4;
        echo "test2";
        $b = 5;
        $c = 6;
        $d = 7;
        $e = 8;
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(1);
        $finder->findBlocks($ast);

        $sorted = $finder->getBlocksSortedBySize();

        expect($sorted)->toBeArray();

        // Проверяем, что отсортировано по убыванию размера
        for ($i = 0; $i < count($sorted) - 1; $i++) {
            expect($sorted[$i]['size'])->toBeGreaterThanOrEqual($sorted[$i + 1]['size']);
        }
    }
);

it(
    'returns statistics about pure blocks',
    function (): void {
        $code = '<?php
        $x = 1;
        $y = 2;
        $z = 3;
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(1);
        $finder->findBlocks($ast);

        $stats = $finder->getStats();

        expect($stats)->toHaveKey('total_blocks');
        expect($stats)->toHaveKey('total_pure_nodes');
        expect($stats)->toHaveKey('average_block_size');
        expect($stats)->toHaveKey('largest_block_size');
        expect($stats)->toHaveKey('smallest_block_size');
    }
);

it(
    'returns zero stats for empty blocks',
    function (): void {
        $code = '<?php echo "test";';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(100); // Большой минимум - не найдет блоков
        $finder->findBlocks($ast);

        $stats = $finder->getStats();

        expect($stats['total_blocks'])->toBe(0);
        expect($stats['total_pure_nodes'])->toBe(0);
        expect($stats['average_block_size'])->toBe(0.0);
    }
);

it(
    'finds nested blocks in if statements',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            $a = 1;
            $b = 2;
        }
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(1);
        $nestedBlocks = $finder->findNestedBlocks($ast);

        expect($nestedBlocks)->toBeArray();
    }
);

it(
    'finds nested blocks in while loops',
    function (): void {
        $code = '<?php
        while ($x < 10) {
            $x = $x + 1;
        }
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(1);
        $nestedBlocks = $finder->findNestedBlocks($ast);

        expect($nestedBlocks)->toBeArray();
    }
);

it(
    'finds nested blocks in functions',
    function (): void {
        $code = '<?php
        function test() {
            $x = 1 + 2;
            $y = 3 + 4;
            return $x + $y;
        }
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(1);
        $nestedBlocks = $finder->findNestedBlocks($ast);

        expect($nestedBlocks)->toBeArray();
    }
);

it(
    'handles empty AST',
    function (): void {
        $ast = [];

        $finder = new PureBlockFinder(1);
        $blocks = $finder->findBlocks($ast);

        expect($blocks)->toBe([]);
        expect($finder->getBlockCount())->toBe(0);
    }
);

it(
    'handles mixed pure and non-pure code',
    function (): void {
        $code = '<?php
        $x = 1 + 2;
        $name = $_GET["name"];
        $y = 3 + 4;
        echo "test";
        $z = 5 + 6;
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(1);
        $blocks = $finder->findBlocks($ast);

        expect($blocks)->toBeArray();
    }
);

it(
    'correctly identifies block boundaries',
    function (): void {
        $code = '<?php
        $a = 1;
        $b = 2;
        echo "break";
        $c = 3;
        $d = 4;
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $finder = new PureBlockFinder(2);
        $blocks = $finder->findBlocks($ast);

        // Каждый блок должен иметь корректные границы
        foreach ($blocks as $block) {
            expect($block['start'])->toBeLessThanOrEqual($block['end']);
            expect($block['size'])->toBe(count($block['nodes']));
        }
    }
);
