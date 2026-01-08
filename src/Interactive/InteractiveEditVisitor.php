<?php

namespace Recombinator\Interactive;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Recombinator\Analysis\CognitiveComplexityCalculator;
use Recombinator\Domain\NamingSuggester;
use Recombinator\Domain\StructureImprovement;

/**
 * Visitor для поиска кандидатов на интерактивную правку
 */
class InteractiveEditVisitor extends NodeVisitorAbstract
{
    /** @var EditCandidate[] */
    private array $editCandidates = [];

    /** @var StructureImprovement[] */
    private array $structureImprovements = [];

    /** @var array<string, int> Статистика по типам проблем */
    private array $issueStats = [];

    private int $currentDepth = 0;

    public function __construct(
        private NamingSuggester $namingSuggester,
        private CognitiveComplexityCalculator $complexityCalculator,
        private int $complexityThreshold,
        private int $maxNestingDepth,
        private int $minNameQuality
    ) {
    }

    public function enterNode(Node $node): void
    {
        // Увеличиваем глубину при входе в блоки
        if ($this->isNestingNode($node)) {
            $this->currentDepth++;
        }

        // Анализ переменных
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $this->analyzeVariable($node, $node->name);
        }

        // Анализ присваиваний
        if ($node instanceof Expr\Assign) {
            $this->analyzeAssignment($node);
        }

        // Анализ функций
        if ($node instanceof Stmt\Function_ || $node instanceof Expr\Closure) {
            $this->analyzeFunction($node);
        }

        // Анализ условий
        if ($node instanceof Stmt\If_) {
            $this->analyzeCondition($node);
        }

        // Анализ магических чисел
        if ($node instanceof Node\Scalar\LNumber || $node instanceof Node\Scalar\DNumber) {
            $this->analyzeMagicNumber($node);
        }

        // Анализ сложных выражений
        if ($node instanceof Expr) {
            $this->analyzeExpression($node);
        }
    }

    public function leaveNode(Node $node): void
    {
        // Уменьшаем глубину при выходе из блоков
        if ($this->isNestingNode($node)) {
            $this->currentDepth--;
        }
    }

    public function getResult(): InteractiveEditResult
    {
        return new InteractiveEditResult(
            $this->editCandidates,
            $this->structureImprovements,
            $this->issueStats
        );
    }

    private function analyzeVariable(Expr\Variable $node, string $name): void
    {
        // Пропускаем специальные переменные
        if (in_array($name, ['this', '_GET', '_POST', '_SESSION', '_SERVER', '_COOKIE', '_FILES'])) {
            return;
        }

        $quality = $this->namingSuggester->scoreNameQuality($name);

        if ($quality < $this->minNameQuality) {
            $this->addEditCandidate(
                $node,
                EditCandidate::ISSUE_POOR_NAMING,
                "Variable '\${$name}' has poor name quality (score: {$quality}/10)",
                EditCandidate::PRIORITY_MEDIUM,
                ["Consider renaming to something more descriptive"]
            );
        }
    }

    private function analyzeAssignment(Expr\Assign $node): void
    {
        if (!$node->var instanceof Expr\Variable || !is_string($node->var->name)) {
            return;
        }

        $varName = $node->var->name;

        // Генерация предложений для имени на основе значения
        $suggestions = $this->namingSuggester->suggestVariableName($node->expr);

        if (!empty($suggestions) && $this->namingSuggester->isPoorName($varName)) {
            $this->addEditCandidate(
                $node,
                EditCandidate::ISSUE_POOR_NAMING,
                "Variable '\${$varName}' could have a more meaningful name",
                EditCandidate::PRIORITY_HIGH,
                array_map(fn($s) => "Consider: \${$s}", $suggestions)
            );
        }

        // Проверка сложности выражения
        $complexity = $this->complexityCalculator->calculate($node->expr);
        if ($complexity > $this->complexityThreshold) {
            $this->addStructureImprovement(
                StructureImprovement::TYPE_INTRODUCE_VARIABLE,
                "Complex expression (complexity: {$complexity}) could be split into intermediate variables",
                $node,
                ['complexity' => $complexity, 'priority' => 7]
            );
        }
    }

    private function analyzeFunction(Stmt\Function_|Expr\Closure $node): void
    {
        if ($node instanceof Stmt\Function_ && $node->name !== null) {
            $name = $node->name->toString();
            $quality = $this->namingSuggester->scoreNameQuality($name);

            if ($quality < $this->minNameQuality) {
                $this->addEditCandidate(
                    $node,
                    EditCandidate::ISSUE_POOR_NAMING,
                    "Function '{$name}' has poor name quality (score: {$quality}/10)",
                    EditCandidate::PRIORITY_HIGH,
                    ["Consider using a more descriptive verb-based name"]
                );
            }
        }

        // Анализ сложности функции
        $complexity = $this->complexityCalculator->calculate($node);
        if ($complexity > 20) {
            $this->addStructureImprovement(
                StructureImprovement::TYPE_SPLIT_LOGIC,
                "Function is too complex (complexity: {$complexity}), consider splitting",
                $node,
                ['complexity' => $complexity, 'priority' => 9]
            );
        }
    }

    private function analyzeCondition(Stmt\If_ $node): void
    {
        // Проверка глубокой вложенности
        if ($this->currentDepth > $this->maxNestingDepth) {
            $this->addEditCandidate(
                $node,
                EditCandidate::ISSUE_DEEP_NESTING,
                "Deep nesting (level {$this->currentDepth}), consider extracting to function or early return",
                EditCandidate::PRIORITY_HIGH,
                [
                    "Use early returns to reduce nesting",
                    "Extract complex logic to separate function",
                    "Consider guard clauses"
                ]
            );

            $this->addStructureImprovement(
                StructureImprovement::TYPE_REDUCE_NESTING,
                "Reduce nesting depth from {$this->currentDepth} to {$this->maxNestingDepth}",
                $node,
                [
                    'current_depth' => $this->currentDepth,
                    'target_depth' => $this->maxNestingDepth,
                    'depth_reduction' => $this->currentDepth - $this->maxNestingDepth,
                    'priority' => 8
                ]
            );
        }

        // Проверка сложности условия
        $complexity = $this->complexityCalculator->calculate($node->cond);
        if ($complexity > 4) {
            $this->addStructureImprovement(
                StructureImprovement::TYPE_SIMPLIFY_CONDITION,
                "Complex condition (complexity: {$complexity}), consider introducing variable",
                $node,
                ['complexity' => $complexity, 'priority' => 6]
            );
        }
    }

    private function analyzeMagicNumber(Node\Scalar\LNumber|Node\Scalar\DNumber $node): void
    {
        $value = $node->value;

        // Пропускаем общие константы
        if (in_array($value, [0, 1, 2, -1, 10, 100, 1000], true)) {
            return;
        }

        // Проверяем, не является ли число частью массива или простой операции
        $parent = $node->getAttribute('parent');
        if ($parent instanceof Expr\Array_ || $parent instanceof Expr\ArrayDimFetch) {
            return;
        }

        $this->addEditCandidate(
            $node,
            EditCandidate::ISSUE_MAGIC_NUMBER,
            "Magic number '{$value}' should be replaced with named constant",
            EditCandidate::PRIORITY_LOW,
            ["Define a constant with meaningful name (e.g., MAX_RETRIES, TIMEOUT_SECONDS)"]
        );
    }

    private function analyzeExpression(Expr $node): void
    {
        // Пропускаем простые выражения
        if ($node instanceof Expr\Variable || $node instanceof Node\Scalar) {
            return;
        }

        $complexity = $this->complexityCalculator->calculate($node);

        if ($complexity > $this->complexityThreshold * 2) {
            $this->addEditCandidate(
                $node,
                EditCandidate::ISSUE_COMPLEX_EXPRESSION,
                "Very complex expression (complexity: {$complexity})",
                EditCandidate::PRIORITY_CRITICAL,
                [
                    "Break down into smaller parts",
                    "Introduce intermediate variables",
                    "Extract to separate function"
                ]
            );
        } elseif ($complexity > $this->complexityThreshold) {
            $this->addEditCandidate(
                $node,
                EditCandidate::ISSUE_COMPLEX_EXPRESSION,
                "Complex expression (complexity: {$complexity})",
                EditCandidate::PRIORITY_HIGH,
                [
                    "Consider breaking down into smaller parts",
                    "Introduce intermediate variables"
                ]
            );
        }
    }

    private function isNestingNode(Node $node): bool
    {
        return $node instanceof Stmt\If_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\Switch_
            || $node instanceof Stmt\TryCatch
            || $node instanceof Expr\Closure
            || $node instanceof Stmt\Function_;
    }

    private function addEditCandidate(
        Node $node,
        string $issueType,
        string $description,
        int $priority,
        array $suggestions = []
    ): void {
        $candidate = new EditCandidate($node, $issueType, $description, $priority, $suggestions);
        $this->editCandidates[] = $candidate;

        // Обновляем статистику
        if (!isset($this->issueStats[$issueType])) {
            $this->issueStats[$issueType] = 0;
        }
        $this->issueStats[$issueType]++;
    }

    private function addStructureImprovement(
        string $type,
        string $description,
        Node $node,
        array $metadata = []
    ): void {
        $improvement = new StructureImprovement($type, $description, $node, $metadata);
        $this->structureImprovements[] = $improvement;
    }
}
