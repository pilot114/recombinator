<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;

/**
 * Завершающий шаг прогона: нормализует результирующий код через внешние PHP-инструменты.
 *
 * Порядок применения:
 *   1. Rector   — обновляет синтаксис до PHP 8.4, улучшает качество кода
 *   2. PHPCBF   — приводит форматирование к PSR-12
 *
 * Работает поверх AST: сериализует текущее дерево → запускает инструменты → перепарсивает результат.
 */
#[VisitorMeta('Финальная нормализация: Rector (PHP 8.4+, качество кода) + PHPCBF (PSR-12)')]
class LinterVisitor extends BaseVisitor
{
    public function __construct(private readonly string $projectRoot) {}

    /**
     * @param  array<Node> $nodes
     * @return array<Node>|null
     */
    #[\Override]
    public function afterTraverse(array $nodes): ?array
    {
        $printer = new StandardPrinter();
        $codeBefore = "<?php\n" . $printer->prettyPrint($nodes);

        $tmpFile = sys_get_temp_dir() . '/recombinator_linter_' . uniqid('', true) . '.php';
        if (file_put_contents($tmpFile, $codeBefore) === false) {
            return null;
        }

        try {
            $this->runRector($tmpFile);
            $this->runPhpcbf($tmpFile);

            $codeAfter = file_get_contents($tmpFile);
            if ($codeAfter === false || trim($codeAfter) === trim($codeBefore)) {
                return null;
            }

            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            return $parser->parse($codeAfter) ?? null;
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    private function runRector(string $file): void
    {
        $binary = $this->resolveBinary('rector');
        if ($binary === null) {
            return;
        }

        $config = $this->projectRoot . '/rector.php';
        exec(sprintf(
            'php %s process %s --config %s --no-progress-bar 2>/dev/null',
            escapeshellarg($binary),
            escapeshellarg($file),
            escapeshellarg($config)
        ));
    }

    private function runPhpcbf(string $file): void
    {
        $binary = $this->resolveBinary('phpcbf');
        if ($binary === null) {
            return;
        }

        $config = $this->projectRoot . '/phpcs.xml';
        exec(sprintf(
            'php %s %s --standard=%s 2>/dev/null',
            escapeshellarg($binary),
            escapeshellarg($file),
            escapeshellarg($config)
        ));
    }

    private function resolveBinary(string $name): ?string
    {
        $vendorPath = $this->projectRoot . '/vendor/bin/' . $name;
        if (is_executable($vendorPath)) {
            return $vendorPath;
        }

        // fallback: поиск в PATH
        $found = trim((string) shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null'));
        return $found !== '' ? $found : null;
    }
}
