<?php

namespace Recombinator;

use PhpParser\Lexer;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PhpParser\Error;

class Parser
{
    protected $scopes = [];
    protected $ast = [];
    protected $cacheDir;

    public function __construct($path, $cachePath)
    {
        $this->cacheDir = realpath($cachePath);
        $cacheFiles = glob($this->cacheDir . '/*');

        if (count($cacheFiles) > 0) {
            foreach ($cacheFiles as $cacheFile) {
                $basename = basename($cacheFile);
                if ($basename === 'index.php') {
                    $this->entryPoint = $cacheFile;
                }
                $this->scopes[$basename] = file_get_contents($cacheFile);
            }
        } else {
            $this->entryPoint = realpath($path);
            $this->scopes = [ 'index.php' => file_get_contents($this->entryPoint) ];
        }
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

    /**
     * Запускает парсинг и начинает строить дерево скопов c заданным уровнем вложенности
     */
    public function parseScopes()
    {
        $visitors = [
            // для доступа к $node->getAttribute('parent')
            new ParentConnectingVisitor(),
            new EqualVisitor(),
        ];

        foreach ($this->scopes as $name => $scope) {
            $this->ast[$name] = $this->buildAST($name, $scope);
            $this->ast[$name] = (new Fluent($this->ast[$name]))
                ->withVisitors($visitors)
                ->modify();
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
}