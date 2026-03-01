<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Recombinator\Transformation\Visitor\ThisPropertyReplacer;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new Standard();
    }
);

it(
    'replaces $this->property with instance variable',
    function (): void {
        $code = '<?php echo $this->name;';
        $ast = $this->parser->parse($code);

        $instance = [
            'name' => 'obj1',
            'properties' => ['name' => 'obj1_name_123'],
        ];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThisPropertyReplacer($instance));

        $result = $traverser->traverse($ast);

        $output = $this->printer->prettyPrint($result);

        expect($output)->toContain('$obj1_name_123');
        expect($output)->not->toContain('$this->name');
    }
);

it(
    'replaces $this->property in assignment',
    function (): void {
        $code = '<?php $this->value = 42;';
        $ast = $this->parser->parse($code);

        $instance = [
            'name' => 'obj1',
            'properties' => ['value' => 'obj1_value_123'],
        ];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThisPropertyReplacer($instance));

        $result = $traverser->traverse($ast);

        $output = $this->printer->prettyPrint($result);

        expect($output)->toContain('$obj1_value_123 = 42');
        expect($output)->not->toContain('$this->value');
    }
);

it(
    'does not replace non-this property access',
    function (): void {
        $code = '<?php echo $other->name;';
        $ast = $this->parser->parse($code);

        $instance = [
            'name' => 'obj1',
            'properties' => ['name' => 'obj1_name_123'],
        ];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThisPropertyReplacer($instance));

        $result = $traverser->traverse($ast);

        $output = $this->printer->prettyPrint($result);

        expect($output)->toContain('$other->name');
    }
);
