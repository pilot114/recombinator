<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Recombinator\Domain\SideEffectType;
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
    'marks pure nodes with PURE effect type',
    function (): void {
        $code = '<?php $x = 1 + 2;';
        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $effect = $ast[0]->getAttribute('side_effect');
        expect($effect)->toBe(SideEffectType::PURE);
    }
);

it(
    'marks echo nodes with IO effect type',
    function (): void {
        $code = '<?php echo "Hello";';
        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $effect = $ast[0]->getAttribute('side_effect');
        expect($effect)->toBe(SideEffectType::IO);
    }
);

it(
    'marks $_GET access with EXTERNAL_STATE effect type',
    function (): void {
        $code = '<?php $x = $_GET["name"];';
        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        // Проверяем узел присваивания
        $stmt = $ast[0];
        $effect = $stmt->getAttribute('side_effect');

        // Должен быть EXTERNAL_STATE, так как используется $_GET
        expect($effect)->toBe(SideEffectType::EXTERNAL_STATE);
    }
);

it(
    'marks rand() with NON_DETERMINISTIC effect type',
    function (): void {
        $code = '<?php $x = rand(1, 10);';
        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $effect = $ast[0]->getAttribute('side_effect');
        expect($effect)->toBe(SideEffectType::NON_DETERMINISTIC);
    }
);

it(
    'marks file operations with IO effect type',
    function (): void {
        $code = '<?php $content = file_get_contents("file.txt");';
        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $effect = $ast[0]->getAttribute('side_effect');
        expect($effect)->toBe(SideEffectType::IO);
    }
);

it(
    'collects statistics about effect types',
    function (): void {
        $code = '<?php
        $x = 1 + 2;          // PURE
        echo "test";         // IO
        $y = $_GET["name"];  // EXTERNAL_STATE
        $z = rand();         // NON_DETERMINISTIC
    ';

        $ast = $this->parser->parse($code);
        $this->visitor->resetStats();
        $ast = $this->traverser->traverse($ast);

        $stats = $this->visitor->getStats();

        expect($stats['pure'])->toBeGreaterThan(0);
        expect($stats['io'])->toBeGreaterThan(0);
        expect($stats['external_state'])->toBeGreaterThan(0);
        expect($stats['non_deterministic'])->toBeGreaterThan(0);
    }
);

it(
    'calculates percentage statistics correctly',
    function (): void {
        $code = '<?php
        $x = 1;
        $y = 2;
        echo "test";
    ';

        $ast = $this->parser->parse($code);
        $this->visitor->resetStats();
        $ast = $this->traverser->traverse($ast);

        $percentage = $this->visitor->getStatsPercentage();

        expect($percentage)->toBeArray();
        expect(array_sum($percentage))->toBeLessThanOrEqual(100.01); // Allow for rounding
    }
);

it(
    'resets statistics correctly',
    function (): void {
        $code = '<?php $x = 1;';
        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $statsBefore = $this->visitor->getStats();
        expect($statsBefore['total'])->toBeGreaterThan(0);

        $this->visitor->resetStats();
        $statsAfter = $this->visitor->getStats();

        expect($statsAfter['total'])->toBe(0);
        expect($statsAfter['pure'])->toBe(0);
    }
);

it(
    'finds nodes by effect type',
    function (): void {
        $code = '<?php
        $x = 1 + 2;
        echo "test";
        $y = 3 + 4;
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $pureNodes = $this->visitor->findNodesByEffect($ast, SideEffectType::PURE);
        $ioNodes = $this->visitor->findNodesByEffect($ast, SideEffectType::IO);

        expect($pureNodes)->toBeArray();
        expect(count($pureNodes))->toBeGreaterThan(0);
        expect(count($ioNodes))->toBeGreaterThan(0);
    }
);

it(
    'handles mixed code with multiple effect types',
    function (): void {
        $code = '<?php
        function test() {
            $a = 1 + 2;
            echo $a;
            $b = $_GET["test"];
            return $b;
        }
    ';

        $ast = $this->parser->parse($code);
        $this->visitor->resetStats();
        $ast = $this->traverser->traverse($ast);

        $stats = $this->visitor->getStats();

        // Должны быть разные типы эффектов
        expect($stats['total'])->toBeGreaterThan(0);
    }
);

it(
    'marks all nodes in the tree',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            echo "positive";
        } else {
            echo "negative";
        }
    ';

        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        // Проверяем, что корневой узел помечен
        expect($ast[0]->getAttribute('side_effect'))->toBeInstanceOf(SideEffectType::class);
    }
);

it(
    'handles nested expressions correctly',
    function (): void {
        $code = '<?php $x = strtoupper(substr("hello", 0, 2));';
        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        // Должен быть PURE, так как обе функции чистые
        $effect = $ast[0]->getAttribute('side_effect');
        expect($effect)->toBe(SideEffectType::PURE);
    }
);

it(
    'marks database operations with DATABASE effect type',
    function (): void {
        $code = '<?php mysqli_query($conn, "SELECT * FROM users");';
        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $effect = $ast[0]->getAttribute('side_effect');
        expect($effect)->toBe(SideEffectType::DATABASE);
    }
);

it(
    'marks curl operations with HTTP effect type',
    function (): void {
        $code = '<?php curl_exec($ch);';
        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $effect = $ast[0]->getAttribute('side_effect');
        expect($effect)->toBe(SideEffectType::HTTP);
    }
);

it(
    'marks global state operations with GLOBAL_STATE effect type',
    function (): void {
        $code = '<?php ini_set("display_errors", "1");';
        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        $effect = $ast[0]->getAttribute('side_effect');
        expect($effect)->toBe(SideEffectType::GLOBAL_STATE);
    }
);

it(
    'returns empty array when finding nodes without matches',
    function (): void {
        $code = '<?php $x = 1;';
        $ast = $this->parser->parse($code);
        $ast = $this->traverser->traverse($ast);

        // Ищем HTTP узлы в чисто математическом коде
        $httpNodes = $this->visitor->findNodesByEffect($ast, SideEffectType::HTTP);

        expect($httpNodes)->toBe([]);
    }
);
