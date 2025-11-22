<?php

declare(strict_types=1);

namespace Recombinator;

/**
 * Результат интерактивного анализа кода
 *
 * Содержит:
 * - Кандидатов на редактирование (проблемы в коде)
 * - Предложения по улучшению структуры
 * - Статистику по типам проблем
 */
class InteractiveEditResult
{
    /**
     * @param EditCandidate[] $editCandidates
     * @param StructureImprovement[] $structureImprovements
     * @param array<string, int> $issueStats
     */
    public function __construct(
        private array $editCandidates,
        private array $structureImprovements,
        private array $issueStats
    ) {
    }

    /**
     * @return EditCandidate[]
     */
    public function getEditCandidates(): array
    {
        return $this->editCandidates;
    }

    /**
     * Возвращает кандидатов отсортированных по приоритету
     *
     * @return EditCandidate[]
     */
    public function getEditCandidatesByPriority(): array
    {
        $candidates = $this->editCandidates;
        usort($candidates, fn($a, $b) => $b->getPriority() <=> $a->getPriority());
        return $candidates;
    }

    /**
     * Возвращает кандидатов определенного типа
     *
     * @return EditCandidate[]
     */
    public function getEditCandidatesByType(string $type): array
    {
        return array_filter(
            $this->editCandidates,
            fn($c) => $c->getIssueType() === $type
        );
    }

    /**
     * Возвращает только критичные кандидаты
     *
     * @return EditCandidate[]
     */
    public function getCriticalCandidates(): array
    {
        return array_filter(
            $this->editCandidates,
            fn($c) => $c->isCritical()
        );
    }

    /**
     * @return StructureImprovement[]
     */
    public function getStructureImprovements(): array
    {
        return $this->structureImprovements;
    }

    /**
     * Возвращает улучшения отсортированные по приоритету
     *
     * @return StructureImprovement[]
     */
    public function getStructureImprovementsByPriority(): array
    {
        $improvements = $this->structureImprovements;
        usort($improvements, fn($a, $b) => $b->getPriority() <=> $a->getPriority());
        return $improvements;
    }

    /**
     * Возвращает улучшения определенного типа
     *
     * @return StructureImprovement[]
     */
    public function getStructureImprovementsByType(string $type): array
    {
        return array_filter(
            $this->structureImprovements,
            fn($i) => $i->getType() === $type
        );
    }

    /**
     * @return array<string, int>
     */
    public function getIssueStats(): array
    {
        return $this->issueStats;
    }

    /**
     * Возвращает общее количество найденных проблем
     */
    public function getTotalIssues(): int
    {
        return count($this->editCandidates);
    }

    /**
     * Возвращает общее количество предложений по улучшению
     */
    public function getTotalImprovements(): int
    {
        return count($this->structureImprovements);
    }

    /**
     * Проверяет, есть ли критичные проблемы
     */
    public function hasCriticalIssues(): bool
    {
        return !empty($this->getCriticalCandidates());
    }

    /**
     * Форматированный отчет
     */
    public function formatReport(): string
    {
        $report = "=== Interactive Edit Analysis Report ===\n\n";

        $report .= "Summary:\n";
        $report .= sprintf("  Total issues found: %d\n", $this->getTotalIssues());
        $report .= sprintf("  Critical issues: %d\n", count($this->getCriticalCandidates()));
        $report .= sprintf("  Structure improvements: %d\n\n", $this->getTotalImprovements());

        if (!empty($this->issueStats)) {
            $report .= "Issue Statistics:\n";
            foreach ($this->issueStats as $type => $count) {
                $report .= sprintf("  %s: %d\n", $type, $count);
            }
            $report .= "\n";
        }

        // Критичные проблемы
        $critical = $this->getCriticalCandidates();
        if (!empty($critical)) {
            $report .= "=== Critical Issues ===\n";
            foreach ($critical as $candidate) {
                $report .= $candidate->format() . "\n";
            }
        }

        // Высокоприоритетные улучшения
        $topImprovements = array_slice($this->getStructureImprovementsByPriority(), 0, 5);
        if (!empty($topImprovements)) {
            $report .= "=== Top Structure Improvements ===\n";
            foreach ($topImprovements as $improvement) {
                $report .= $improvement->format() . "\n";
            }
        }

        return $report;
    }

    /**
     * Краткая сводка
     */
    public function getSummary(): string
    {
        return sprintf(
            "Found %d issues (%d critical) and %d structure improvements",
            $this->getTotalIssues(),
            count($this->getCriticalCandidates()),
            $this->getTotalImprovements()
        );
    }
}
