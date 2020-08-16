<?php

namespace Recombinator;

use PhpParser\Lexer;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PhpParser\Error;
use Recombinator\Visitor\ConcatAssertVisitor;
use Recombinator\Visitor\EvalStandartFunction;
use Recombinator\Visitor\IncludeVisitor;
use Recombinator\Visitor\ScopeVisitor;
use Recombinator\Visitor\SimpleEqualVisitor;

// TODO: https://github.com/rectorphp/rector
// TODO: IncludeVisitor надо прогонять пока есть что подключать
// TODO: возможность указать входные параметры для выполнения

class Parser
{
    protected $scopes = [];
    protected $ast = [];
    protected $cacheDir;
    protected $isDry = false;

    public function __construct($path, $cachePath)
    {
        $entryPointName = 'index.php';
        $this->cacheDir = realpath($cachePath);
        $cacheFiles = glob($this->cacheDir . '/*');

        if (count($cacheFiles) > 0) {
            foreach ($cacheFiles as $cacheFile) {
                $basename = basename($cacheFile);
                if ($basename === $entryPointName) {
                    $this->entryPoint = $cacheFile;
                }
                $this->scopes[$basename] = file_get_contents($cacheFile);
            }
        } else {
            $this->isDry = true;
            $this->entryPoint = realpath($path);
            $this->scopes = [ $entryPointName => file_get_contents($this->entryPoint) ];
        }
    }

    /**
     * Запускает парсинг
     */
    public function parseScopes()
    {
        if ($this->isDry) {
            $visitors = [
                [
                    new ParentConnectingVisitor(), // getAttribute('parent')
                    new IncludeVisitor($this->entryPoint),
                ],
                [
                    new ScopeVisitor($this->entryPoint, $this->cacheDir)
                ]
            ];
            $this->parseScopesWithVisitors($visitors);
        } else {
            $visitors = [
                [
                    new NodeConnectingVisitor(), // getAttribute('parent') / getAttribute('previous') / getAttribute('next')
                    new SimpleEqualVisitor(),
                    new ConcatAssertVisitor(),
                    new EvalStandartFunction(),
                ]
            ];
            $this->parseScopesWithVisitors($visitors);
        }
    }

    /**
     *  $visitors - массив этапов обхода (в одном этапе может быть несколько visitor)
     */
    protected function parseScopesWithVisitors($visitors)
    {
        foreach ($this->scopes as $name => $scope) {
            $this->ast[$name] = $this->buildAST($name, $scope);
            foreach ($visitors as $visitorBatch) {
                $this->ast[$name] = (new Fluent($this->ast[$name]))
                    ->withVisitors($visitorBatch)
                    ->modify();
            }
        }
    }

    /**
     * Выводит результирующий код
     * @return string
     */
    public function prettyPrint($name, $lined = false)
    {
        $output = (new StandardPrinter)->prettyPrint($this->ast[$name]);
        if ($lined) {
            $lines = explode("\n", $output);
            $numOutput = '';
            foreach ($lines as $i => $line) {
                // поправка на нулевой индекс и на <?php строку
                $numOutput .= $i+2 . ') ' . $line . "\n";
            }
            $output = $numOutput;
        }
        return $output . "\n";
    }

    /**
     * Выводит AST дерево, используя кастомный дампер
     * @return string
     */
    public function dumpAST($name)
    {
        return (new PrettyDumper())->dump($this->ast[$name]) . "\n";
    }

    public function updateCache()
    {
        foreach ($this->ast as $name => $ast) {
            file_put_contents(
                $this->cacheDir . '/' . $name,
                "<?php\n" . $this->prettyPrint($name)
            );
        }
    }

    protected function buildAST($name, $code)
    {
        // настраиваем лексер, чтобы узлы содержали информацию о своем положении
        $lexer = new Lexer([
            'usedAttributes' => [
                'comments', 'startLine', 'endLine',
                'startTokenPos', 'endTokenPos',
                'startFilePos', 'endFilePos'
            ]
        ]);
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7, $lexer);
        try {
            $this->ast[$name] = $parser->parse($code);
        } catch (Error $e) {
            if ($e->hasColumnInfo()) {
                echo $e->getMessageWithColumnInfo();
            } else {
                echo $e->getMessage();
            }
        }
        return $this->ast[$name];
    }
}