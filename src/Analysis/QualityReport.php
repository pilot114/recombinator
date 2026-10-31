<?php

declare(strict_types=1);

namespace Recombinator\Analysis;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;
use Recombinator\Domain\ComplexityMetrics;
use Recombinator\Interactive\EditCandidate;
use Recombinator\Interactive\InteractiveEditAnalyzer;

/**
 * Итог прогона для человека: сложность программы до и после конвейера (этап «свёртки»
 * оценивает её рост) и подсказки для ручной правки (этап интерактивных улучшений:
 * имена, магические числа, вложенность). Код не меняет.
 */
final class QualityReport
{
    /** Подсказок в отчёте — самые важные: остальные теряются в выводе */
    private const int SUGGESTIONS_LIMIT = 50;

    /**
     * @param  list<string> $sources весь исходный код, по файлу (у проекта — и vendor): файлы
     *                               разбираются по одному, чтобы не держать все AST в памяти;
     *                               файл, который не разбирается, не учитывается
     * @param  list<Node>   $after   итог; строки узлов — как в напечатанном итоге
     * @return array{complexity: array{before: array<string, int>, after: array<string, int>, warnings: list<string>}, suggestions: list<string>, summary: string}
     */
    public static function of(array $sources, array $after): array
    {
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        $parts  = [];
        foreach ($sources as $source) {
            try {
                $parts[] = ComplexityMetrics::fromNodes($parser->parse($source) ?? []);
            } catch (Error) {
                continue;
            }
        }

        $before     = ComplexityMetrics::combine($parts);
        $afterM     = ComplexityMetrics::fromNodes($after);
        $controller = new ComplexityController();
        $controller->register('program', $before, $afterM);

        $edits = new InteractiveEditAnalyzer()->analyze($after);

        return [
            'complexity' => [
                'before'   => self::metrics($before),
                'after'    => self::metrics($afterM),
                'warnings' => array_map(static fn(ComplexityWarning $w): string => $w->format(), $controller->getWarnings()),
            ],
            'suggestions' => array_map(
                static fn(EditCandidate $candidate): string => rtrim($candidate->format()),
                array_slice($edits->getEditCandidatesByPriority(), 0, self::SUGGESTIONS_LIMIT),
            ),
            'summary' => $edits->getSummary(),
        ];
    }

    /** @return array<string, int> */
    private static function metrics(ComplexityMetrics $metrics): array
    {
        return [
            'cognitive'  => $metrics->getCognitiveComplexity(),
            'cyclomatic' => $metrics->getCyclomaticComplexity(),
            'lines'      => $metrics->getLinesOfCode(),
            'nesting'    => $metrics->getNestingDepth(),
        ];
    }
}
