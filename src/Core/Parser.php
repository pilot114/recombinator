<?php

declare(strict_types=1);

namespace Recombinator\Core;

use PhpParser\Node;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\Parser as PhpParser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PhpParser\Error;
use PhpParser\NodeVisitor;
use Recombinator\Domain\ScopeStore;
use Recombinator\Support\ColorDiffer;
use Recombinator\Transformation\Visitor\BinaryAndIssetVisitor;
use Recombinator\Transformation\Visitor\CallFunctionVisitor;
use Recombinator\Transformation\Visitor\ConcatAssertVisitor;
use Recombinator\Transformation\Visitor\ConstClassVisitor;
use Recombinator\Transformation\Visitor\EvalStandardFunction;
use Recombinator\Transformation\Visitor\FunctionScopeVisitor;
use Recombinator\Transformation\Visitor\IncludeVisitor;
use Recombinator\Transformation\Visitor\ScopeVisitor;
use Recombinator\Transformation\Visitor\TernarReturnVisitor;
use Recombinator\Transformation\Visitor\VarToScalarVisitor;

// TODO: https://github.com/rectorphp/rector
// TODO: возможность указать входные параметры для выполнения

/**
 * Парсер и трансформатор PHP кода
 *
 * Основной класс для парсинга PHP файлов и применения различных трансформаций.
 * Работает с кешем файлов, управляет областями видимости (scopes) и применяет
 * последовательность visitor'ов для оптимизации и трансформации кода.
 *
 * Пример использования:
 * ```php
 * $parser = new Parser('/path/to/file.php', '/path/to/cache');
 * $parser->run();
 * echo $parser->prettyPrint('index.php');
 * // или
 * echo $parser->dumpAST('index.php');
 * ```
 */
class Parser
{
    /**
     *
     *
     * @var array<string, string> Массив исходников
     */
    protected array $scopes = [];

    /**
     *
     *
     * @var array<string, array<int, Node>> Массив распарсенных исходников
     */
    protected array $ast = [];

    /**
     *
     *
     * @var array<int, NodeVisitor>
     */
    protected array $visitors = [];

    protected string $cacheDir;

    protected bool $isDry = false;

    protected bool $superOptimize = true;

    protected bool $hasEdit = true;

    protected string $entryPoint = '';

    /**
     * @throws \RuntimeException
     */
    public function __construct(protected string $path, string $cachePath)
    {
        $this->cacheDir = realpath($cachePath) ?: $cachePath;
        $this->buildScopes();
    }

    /**
     * @throws \RuntimeException
     */
    protected function buildScopes(): void
    {
        $entryPointName = 'index.php';
        $cacheFiles = glob($this->cacheDir . '/*');
        if ($cacheFiles !== false && $cacheFiles !== []) {
            foreach ($cacheFiles as $cacheFile) {
                $basename = basename($cacheFile);
                if ($basename === $entryPointName) {
                    $this->entryPoint = $cacheFile;
                }

                $content = file_get_contents($cacheFile);
                if ($content !== false) {
                    $this->scopes[$basename] = $content;
                }
            }
        } else {
            $this->isDry = true;
            $realPath = realpath($this->path);
            if ($realPath === false) {
                throw new \RuntimeException('Path not found: ' . $this->path);
            }

            $this->entryPoint = $realPath;
            $content = file_get_contents($this->entryPoint);
            if ($content !== false) {
                $this->scopes = [$entryPointName => $content];
            }
        }
    }

    /**
     * Запускает парсинг
     *
     * @throws \RuntimeException
     */
    public function run(): void
    {
        $ss = new ScopeStore();
        $this->visitors = [
            new BinaryAndIssetVisitor(),
            new ConcatAssertVisitor(),
            new EvalStandardFunction(),
            new VarToScalarVisitor($ss),
            new FunctionScopeVisitor($ss, $this->cacheDir),
            new CallFunctionVisitor($ss),
            new ConstClassVisitor($ss),
            new TernarReturnVisitor(),
        //            new ConstructorAndMethodsVisitor($ss),
        ];
        $this->parseScopesWithVisitors();
    }

    protected function parseScopes(NodeVisitor $visitor, bool $updateCache = true): void
    {
        $differ = new ColorDiffer();
        $printer = new StandardPrinter();

        $this->hasEdit = false;
        foreach ($this->scopes as $scopeName => $scope) {
            $this->ast[$scopeName] = $this->buildAST($scopeName, $scope);

            // связи узлов обновляем каждый раз
            $this->ast[$scopeName] = new Fluent($this->ast[$scopeName])
                ->withVisitors([new NodeConnectingVisitor()])
                ->modify();

            if (property_exists($visitor, 'scopeName')) {
                $visitor->scopeName = $scopeName;
            }

            if (property_exists($visitor, 'scopeStore')) {
                $scopeStore = $visitor->scopeStore;
                if ($scopeStore instanceof ScopeStore) {
                    $scopeStore->setCurrentScope($scopeName);
                }
            }

            $textBefore = $printer->prettyPrint($this->ast[$scopeName]);
            $this->ast[$scopeName] = new Fluent($this->ast[$scopeName])
                ->withVisitors([$visitor])
                ->modify();
            $textAfter = $printer->prettyPrint($this->ast[$scopeName]);

            if (property_exists($visitor, 'diff')) {
                $visitor->diff = $differ->diff($textBefore, $textAfter);
            }

            // выводим только по тем визиторам, у которых есть diff
            if ($differ->hasDiff) {
                $this->hasEdit = true;
                echo sprintf("> EDIT %s\n", $visitor::class);
                if (property_exists($visitor, 'diff') && is_string($visitor->diff)) {
                    echo $visitor->diff;
                }

                $this->superOptimize = false;
            }

            // после каждого прогона обновляем кеш
            if ($updateCache) {
                $this->updateCache();
            }
        }
    }

    protected function saveAstToScope(): void
    {
        $printer = new StandardPrinter();
        foreach ($this->ast as $scopeName => $ast) {
            $text = $printer->prettyPrint($ast);
            $this->scopes[$scopeName] = "<?php\n" . $text;
        }
    }

    /**
     * @throws \RuntimeException
     */
    protected function parseScopesWithVisitors(): void
    {
        // при первом запуске рекурсивно прогоняем IncludeVisitor и затем ScopeVisitor
        if ($this->isDry) {
            $updateCache = false;
            while ($this->hasEdit) {
                $this->parseScopes(new IncludeVisitor($this->entryPoint), $updateCache);
                $this->saveAstToScope();
            }

            $this->parseScopes(new ScopeVisitor($this->entryPoint, $this->cacheDir));
            $this->saveAstToScope();
        } else {
            foreach ($this->visitors as $visitor) {
                $this->buildScopes();
                $this->parseScopes($visitor);
            }
        }

        if ($this->superOptimize) {
            $this->printEndBanner();
        }
    }

    public function prettyPrintScopes(bool $lined = false): string
    {
        $output = '';
        foreach (array_keys($this->ast) as $name) {
            $code = $this->prettyPrint($name, $lined);
            if ($code) {
                $output .= sprintf("### %s ###\n%s", $name, $code);
            }
        }

        return $output;
    }

    /**
     * Выводит результирующий код
     */
    public function prettyPrint(string $name, bool $lined = false): ?string
    {
        $output = new StandardPrinter()->prettyPrint($this->ast[$name]);
        if ($output === '') {
            return null;
        }

        if ($lined) {
            $lines = explode("\n", $output);
            $numOutput = '';
            foreach ($lines as $i => $line) {
                // поправка на нулевой индекс и на <?php строку
                $numOutput .= $i + 2 . ') ' . $line . "\n";
            }

            $output = $numOutput;
        }

        return $output . "\n";
    }

    /**
     * Выводит AST дерево, используя кастомный дампер
     */
    public function dumpAST(string $name): string
    {
        return new PrettyDumper()->dump($this->ast[$name]) . "\n";
    }

    public function updateCache(): void
    {
        foreach (array_keys($this->ast) as $name) {
            file_put_contents(
                $this->cacheDir . '/' . $name,
                "<?php\n" . $this->prettyPrint($name)
            );
        }
    }

    /**
     * @return array<int, Node>
     */
    protected function buildAST(string $name, string $code): array
    {
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($code);
            if ($ast !== null) {
                /**
 * @var array<int, Node> $ast
*/
                $this->ast[$name] = $ast;
            }
        } catch (Error $error) {
            echo $error->getMessage() . "\n";
        }

        return $this->ast[$name] ?? [];
    }

    protected function printEndBanner(): void
    {
        $text = "
███████╗██╗   ██╗██████╗ ███████╗██████╗      ██████╗ ██████╗ ████████╗██╗███╗   ███╗██╗███████╗███████╗    ██╗
██╔════╝██║   ██║██╔══██╗██╔════╝██╔══██╗    ██╔═══██╗██╔══██╗╚══██╔══╝██║████╗ ████║██║╚══███╔╝██╔════╝    ██║
███████╗██║   ██║██████╔╝█████╗  ██████╔╝    ██║   ██║██████╔╝   ██║   ██║██╔████╔██║██║  ███╔╝ █████╗      ██║
╚════██║██║   ██║██╔═══╝ ██╔══╝  ██╔══██╗    ██║   ██║██╔═══╝    ██║   ██║██║╚██╔╝██║██║ ███╔╝  ██╔══╝      ╚═╝
███████║╚██████╔╝██║     ███████╗██║  ██║    ╚██████╔╝██║        ██║   ██║██║ ╚═╝ ██║██║███████╗███████╗    ██╗
╚══════╝ ╚═════╝ ╚═╝     ╚══════╝╚═╝  ╚═╝     ╚═════╝ ╚═╝        ╚═╝   ╚═╝╚═╝     ╚═╝╚═╝╚══════╝╚══════╝    ╚═╝";
        $differ = new ColorDiffer();
        $colors = ['red', 'green', 'blue', 'yellow'];
        foreach (explode("\n", $text) as $line) {
            echo $differ->getColoredString($line, $colors[random_int(0, 3)]) . "\n";
        }
    }
}
