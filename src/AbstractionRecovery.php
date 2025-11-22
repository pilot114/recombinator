<?php

declare(strict_types=1);

namespace Recombinator;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Восстановление абстракций (Phase 4.2)
 *
 * Анализирует код и определяет границы для новых функций на основе:
 * - Типов побочных эффектов
 * - Размера и сложности блоков
 * - Зависимостей между узлами
 *
 * Реализует правила:
 * - FOLD-FUNC-1: Создание функции для чистого блока
 * - FOLD-FUNC-2: Создание функции для блока с эффектами одного типа
 *
 * Пример использования:
 * ```php
 * // Подготовка AST (сначала маркировка эффектов)
 * $marker = new SideEffectMarkerVisitor();
 * $traverser = new NodeTraverser();
 * $traverser->addVisitor($marker);
 * $ast = $traverser->traverse($ast);
 *
 * // Анализ абстракций
 * $recovery = new AbstractionRecovery();
 * $candidates = $recovery->analyze($ast);
 *
 * // Получение кандидатов
 * foreach ($candidates as $candidate) {
 *     if ($candidate->isViable()) {
 *         echo "Found candidate: " . $candidate->suggestFunctionName() . "\n";
 *     }
 * }
 * ```
 */
class AbstractionRecovery
{
    private SideEffectSeparator $separator;
    private CognitiveComplexityCalculator $complexityCalculator;

    /**
     * Минимальный размер чистого блока для создания функции (FOLD-FUNC-1)
     */
    private int $minPureBlockSize;

    /**
     * Минимальный размер блока с эффектами для создания функции (FOLD-FUNC-2)
     */
    private int $minEffectBlockSize;

    /**
     * Минимальная когнитивная сложность для создания функции
     */
    private int $minComplexity;

    public function __construct(
        ?SideEffectSeparator $separator = null,
        ?CognitiveComplexityCalculator $complexityCalculator = null,
        int $minPureBlockSize = 5,
        int $minEffectBlockSize = 3,
        int $minComplexity = 10
    ) {
        $this->separator = $separator ?? new SideEffectSeparator();
        $this->complexityCalculator = $complexityCalculator ?? new CognitiveComplexityCalculator();
        $this->minPureBlockSize = $minPureBlockSize;
        $this->minEffectBlockSize = $minEffectBlockSize;
        $this->minComplexity = $minComplexity;
    }

    /**
     * Анализирует AST и находит кандидатов на выделение в функции
     *
     * @param Node[] $ast AST, обработанный SideEffectMarkerVisitor
     * @return FunctionCandidate[] Массив кандидатов на выделение
     */
    public function analyze(array $ast): array
    {
        // 1. Разделяем код по типам эффектов
        $separation = $this->separator->separate($ast);

        $candidates = [];

        // 2. Анализируем чистые блоки (FOLD-FUNC-1)
        foreach ($separation->getPureComputations() as $pureComp) {
            $candidate = $this->analyzePureBlock($pureComp);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        // 3. Анализируем группы по эффектам (FOLD-FUNC-2)
        foreach ($separation->getGroups() as $group) {
            $candidate = $this->analyzeEffectGroup($group);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        // 4. Сортируем кандидатов по приоритету
        usort($candidates, fn($a, $b) => $b->getPriority() <=> $a->getPriority());

        return $candidates;
    }

    /**
     * Анализирует чистый блок кода (FOLD-FUNC-1)
     */
    private function analyzePureBlock(PureComputation $pureComp): ?FunctionCandidate
    {
        $nodes = $pureComp->nodes;
        $size = count($nodes);

        // Проверяем минимальный размер
        if ($size < $this->minPureBlockSize) {
            return null;
        }

        // Вычисляем сложность
        $complexity = $this->complexityCalculator->calculate($nodes);

        // Анализируем переменные
        $variables = $this->analyzeVariables($nodes);

        return new FunctionCandidate(
            nodes: $nodes,
            effectType: SideEffectType::PURE,
            size: $size,
            complexity: $complexity,
            usedVariables: $variables['used'],
            definedVariables: $variables['defined'],
            returnVariable: $this->detectReturnVariable($nodes),
            startLine: $this->getStartLine($nodes),
            endLine: $this->getEndLine($nodes),
        );
    }

    /**
     * Анализирует группу узлов с одним типом эффекта (FOLD-FUNC-2)
     */
    private function analyzeEffectGroup(EffectGroup $group): ?FunctionCandidate
    {
        $nodes = $group->nodes;
        $size = count($nodes);

        // Пропускаем PURE и MIXED (они обрабатываются отдельно)
        if ($group->effect->isPure() || $group->effect === SideEffectType::MIXED) {
            return null;
        }

        // Проверяем минимальный размер
        if ($size < $this->minEffectBlockSize) {
            return null;
        }

        // Вычисляем сложность
        $complexity = $this->complexityCalculator->calculate($nodes);

        // Анализируем переменные
        $variables = $this->analyzeVariables($nodes);

        return new FunctionCandidate(
            nodes: $nodes,
            effectType: $group->effect,
            size: $size,
            complexity: $complexity,
            usedVariables: $variables['used'],
            definedVariables: $variables['defined'],
            returnVariable: null, // Блоки с эффектами обычно не возвращают значения
            startLine: $this->getStartLine($nodes),
            endLine: $this->getEndLine($nodes),
        );
    }

    /**
     * Анализирует переменные в блоке кода
     *
     * @param Node[] $nodes
     * @return array{used: array<string>, defined: array<string>}
     */
    private function analyzeVariables(array $nodes): array
    {
        $used = [];
        $defined = [];

        $analyzer = new VariableAnalyzer();
        foreach ($nodes as $node) {
            $result = $analyzer->analyze($node);
            $used = array_merge($used, $result['used']);
            $defined = array_merge($defined, $result['defined']);
        }

        return [
            'used' => array_unique($used),
            'defined' => array_unique($defined),
        ];
    }

    /**
     * Определяет переменную-результат блока
     */
    private function detectReturnVariable(array $nodes): ?string
    {
        if (empty($nodes)) {
            return null;
        }

        // Берем последний узел
        $lastNode = end($nodes);

        // Если это присваивание, возвращаем переменную
        if ($lastNode instanceof Node\Stmt\Expression) {
            $expr = $lastNode->expr;
            if ($expr instanceof Node\Expr\Assign) {
                $var = $expr->var;
                if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
                    return '$' . $var->name;
                }
            }
        }

        return null;
    }

    /**
     * Получает начальную строку блока
     */
    private function getStartLine(array $nodes): int
    {
        if (empty($nodes)) {
            return 0;
        }

        $first = reset($nodes);
        return $first->getStartLine() ?? 0;
    }

    /**
     * Получает конечную строку блока
     */
    private function getEndLine(array $nodes): int
    {
        if (empty($nodes)) {
            return 0;
        }

        $last = end($nodes);
        return $last->getEndLine() ?? 0;
    }
}
