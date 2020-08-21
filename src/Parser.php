<?php

namespace Recombinator;

use PhpParser\Lexer;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PhpParser\Error;
use Recombinator\Visitor\BinaryAndIssetVisitor;
use Recombinator\Visitor\CallFunctionVisitor;
use Recombinator\Visitor\ConcatAssertVisitor;
use Recombinator\Visitor\ConstClassVisitor;
use Recombinator\Visitor\ConstructVisitor;
use Recombinator\Visitor\EvalStandartFunction;
use Recombinator\Visitor\FunctionScopeVisitor;
use Recombinator\Visitor\IncludeVisitor;
use Recombinator\Visitor\ScopeVisitor;
use Recombinator\Visitor\VarToScalarVisitor;

// TODO: https://github.com/rectorphp/rector
// TODO: IncludeVisitor надо прогонять пока есть что подключать
// TODO: возможность указать входные параметры для выполнения

class Parser
{
    protected $scopes = [];
    protected $ast = [];
    protected $visitors = [];
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
        $ss = new ScopeStore();

        if ($this->isDry) {
            $this->visitors = [
                new ParentConnectingVisitor(), // getAttribute('parent')
                new IncludeVisitor($this->entryPoint),
                new ScopeVisitor($this->entryPoint, $this->cacheDir),
            ];
        } else {
            $this->visitors = [
                new NodeConnectingVisitor(), // getAttribute('parent') / getAttribute('previous') / getAttribute('next')
                new BinaryAndIssetVisitor(),
//                new ConcatAssertVisitor(),
//                new EvalStandartFunction(),
                new VarToScalarVisitor($ss),
//                new FunctionScopeVisitor($ss, $this->cacheDir),
//                new CallFunctionVisitor($ss),
//                new ConstClassVisitor($ss),
            ];
        }
        $this->parseScopesWithVisitors();
    }

    protected function parseScopesWithVisitors()
    {
        $differ = new \Recombinator\ColorDiffer();
        $printer = new StandardPrinter();

        $superOptimize = true;
        foreach ($this->scopes as $scopeName => $scope) {
            $this->ast[$scopeName] = $this->buildAST($scopeName, $scope);
            foreach ($this->visitors as $visitor) {
                $visitor->scopeName = $scopeName;
                $textBefore = $printer->prettyPrint($this->ast[$scopeName]);
                $this->ast[$scopeName] = (new Fluent($this->ast[$scopeName]))
                    ->withVisitors([$visitor])
                    ->modify();
                $textAfter = $printer->prettyPrint($this->ast[$scopeName]);
                $visitor->diff = $differ->diff($textBefore, $textAfter);

                // выводим только по тем визиторам, у которых есть diff
                if (count(explode("\n", $visitor->diff)) > 3) {
                    echo sprintf("*** %s ***\n", get_class($visitor));
                    echo $visitor->diff;
                    $superOptimize = false;
                }
            }
        }
        if ($superOptimize) {
//            $this->printEndBanner();
        }
    }

    public function prettyPrintScopes($lined = false)
    {
        $output = '';
        foreach ($this->ast as $name => $item) {
            $code = $this->prettyPrint($name, $lined);
            if ($code) {
                $output .= sprintf("### %s ###\n%s", $name, $code);
            }
        }
        return $output;
    }

    /**
     * Выводит результирующий код
     * @return string
     */
    public function prettyPrint($name, $lined = false)
    {
        $output = (new StandardPrinter)->prettyPrint($this->ast[$name]);
        if (!strlen($output)) {
            return null;
        }

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

    protected function printEndBanner()
    {
        $text = "
███████╗██╗   ██╗██████╗ ███████╗██████╗      ██████╗ ██████╗ ████████╗██╗███╗   ███╗██╗███████╗███████╗    ██╗
██╔════╝██║   ██║██╔══██╗██╔════╝██╔══██╗    ██╔═══██╗██╔══██╗╚══██╔══╝██║████╗ ████║██║╚══███╔╝██╔════╝    ██║
███████╗██║   ██║██████╔╝█████╗  ██████╔╝    ██║   ██║██████╔╝   ██║   ██║██╔████╔██║██║  ███╔╝ █████╗      ██║
╚════██║██║   ██║██╔═══╝ ██╔══╝  ██╔══██╗    ██║   ██║██╔═══╝    ██║   ██║██║╚██╔╝██║██║ ███╔╝  ██╔══╝      ╚═╝
███████║╚██████╔╝██║     ███████╗██║  ██║    ╚██████╔╝██║        ██║   ██║██║ ╚═╝ ██║██║███████╗███████╗    ██╗
╚══════╝ ╚═════╝ ╚═╝     ╚══════╝╚═╝  ╚═╝     ╚═════╝ ╚═╝        ╚═╝   ╚═╝╚═╝     ╚═╝╚═╝╚══════╝╚══════╝    ╚═╝";
        $differ = new \Recombinator\ColorDiffer();
        $colors = ['red', 'green', 'blue', 'yellow'];
        foreach (explode("\n", $text) as $line) {
            echo $differ->getColoredString($line, $colors[rand(0, 3)]) . "\n";
        }
    }
}