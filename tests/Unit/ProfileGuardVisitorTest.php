<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Core\TargetEnvironment;
use Recombinator\Transformation\Visitor\ProfileGuardVisitor;

it('guards a polymorphic call with the class observed in the profile run', function (): void {
    $project = sys_get_temp_dir() . '/rcb_profile_' . uniqid();
    mkdir($project . '/public', 0777, true);
    $code = '<?php class Json { public function render($d) { return json_encode($d); } }
        class Html { public function render($d) { return "<b>" . $d . "</b>"; } }
        class Page { public function __construct(private $renderer) {} public function show($d) { return $this->renderer->render($d); } }
        $kind = getenv("REQUEST_METHOD") === "PUT" ? new Html() : new Json();
        echo (new Page($kind))->show("x");';
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);

    $env = TargetEnvironment::fromArray(['profile' => [['method' => 'GET', 'uri' => '/']]]);
    $ast = new NodeTraverser(new ProfileGuardVisitor($env, $project, 'public/index.php', $project . '/cache'))->traverse($ast);
    $result = new Printer()->prettyPrint($ast);

    expect($result)->toMatch('/\(\$devirt\d+_\w+ = \$this->renderer\) instanceof \\\\Json \? \$devirt\d+_\w+->render\(\$d\) : \$devirt\d+_\w+->render\(\$d\)/');

    // Следующий проход: прямая ветка не встроилась — защита снимается и больше не ставится
    $again = new NodeTraverser(new ProfileGuardVisitor($env, $project, 'public/index.php', $project . '/cache'))->traverse(
        new NodeTraverser(new NodeConnectingVisitor())->traverse($ast),
    );
    expect(new Printer()->prettyPrint($again))->toContain('return $this->renderer->render($d);');
    shell_exec('rm -rf ' . escapeshellarg($project));
});
