<?php

declare(strict_types=1);

namespace Recombinator\Interactive;

use PhpParser\Node;
use Recombinator\Support\ColorDiffer;

/**
 * Интерактивный CLI для редактирования кода (Phase 5.2)
 *
 * Предоставляет интерактивный интерфейс для:
 * - Просмотра результатов анализа
 * - Пошагового применения изменений
 * - Отката и повтора изменений
 * - Управления предпочтениями
 *
 * Пример использования:
 * ```php
 * $analyzer = new InteractiveEditAnalyzer();
 * $result = $analyzer->analyze($ast);
 *
 * $cli = new InteractiveCLI($ast, $result);
 * $cli->run();
 * ```
 */
class InteractiveCLI
{
    private EditSession $session;
    private bool $running = false;
    private ColorDiffer $colorDiffer;

    /** @var array<string, callable> */
    private array $commands = [];

    /**
     * @param Node[] $ast
     * @param InteractiveEditResult $analysisResult
     */
    public function __construct(array $ast, InteractiveEditResult $analysisResult)
    {
        $this->session = new EditSession($ast, $analysisResult);
        $this->colorDiffer = new ColorDiffer();
        $this->registerCommands();
    }

    /**
     * Запускает интерактивный режим
     */
    public function run(): void
    {
        $this->running = true;
        $this->showWelcome();

        while ($this->running) {
            $this->showPrompt();
            $input = $this->readInput();

            if ($input === false || trim($input) === '') {
                continue;
            }

            $this->executeCommand(trim($input));
        }

        $this->showGoodbye();
    }

    /**
     * Выполняет команду без интерактивного режима
     */
    public function executeCommand(string $command): bool
    {
        $parts = explode(' ', $command, 2);
        $cmd = strtolower($parts[0]);
        $args = $parts[1] ?? '';

        if (!isset($this->commands[$cmd])) {
            $this->output("Unknown command: {$cmd}. Type 'help' for available commands.");
            return false;
        }

        try {
            $this->commands[$cmd]($args);
            return true;
        } catch (\Exception $e) {
            $this->output("Error executing command: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Возвращает текущую сессию редактирования
     */
    public function getSession(): EditSession
    {
        return $this->session;
    }

    /**
     * Устанавливает флаг запуска (для тестирования)
     */
    public function setRunning(bool $running): void
    {
        $this->running = $running;
    }

    private function registerCommands(): void
    {
        $this->commands = [
            'help' => fn($args) => $this->commandHelp(),
            'h' => fn($args) => $this->commandHelp(),
            '?' => fn($args) => $this->commandHelp(),

            'status' => fn($args) => $this->commandStatus(),
            's' => fn($args) => $this->commandStatus(),

            'list' => fn($args) => $this->commandList($args),
            'l' => fn($args) => $this->commandList($args),

            'show' => fn($args) => $this->commandShow($args),

            'apply' => fn($args) => $this->commandApply($args),
            'a' => fn($args) => $this->commandApply($args),

            'undo' => fn($args) => $this->commandUndo(),
            'u' => fn($args) => $this->commandUndo(),

            'redo' => fn($args) => $this->commandRedo(),
            'r' => fn($args) => $this->commandRedo(),

            'history' => fn($args) => $this->commandHistory(),

            'pref' => fn($args) => $this->commandPreferences($args),

            'save' => fn($args) => $this->commandSave($args),

            'report' => fn($args) => $this->commandReport(),

            'exit' => fn($args) => $this->commandExit(),
            'quit' => fn($args) => $this->commandExit(),
            'q' => fn($args) => $this->commandExit(),
        ];
    }

    private function commandHelp(): void
    {
        $this->output("\n=== Interactive Code Editor - Help ===\n");
        $this->output("Available commands:");
        $this->output("  help, h, ?         - Show this help message");
        $this->output("  status, s          - Show current session status");
        $this->output("  list [type], l     - List issues (critical/high/all)");
        $this->output("  show <number>      - Show details of specific issue");
        $this->output("  apply <number>, a  - Apply suggested fix");
        $this->output("  undo, u            - Undo last change");
        $this->output("  redo, r            - Redo undone change");
        $this->output("  history            - Show change history");
        $this->output("  pref [key] [value] - View or set preferences");
        $this->output("  save [path]        - Save changes to file");
        $this->output("  report             - Show full analysis report");
        $this->output("  exit, quit, q      - Exit interactive mode\n");
    }

    private function commandStatus(): void
    {
        $result = $this->session->getAnalysisResult();

        $this->output("\n=== Session Status ===");
        $this->output($result->getSummary());
        $this->output("\n" . $this->session->getSessionSummary());
        $this->output("");
    }

    private function commandList(string $args): void
    {
        $result = $this->session->getAnalysisResult();
        $type = strtolower(trim($args));

        $candidates = match ($type) {
            'critical' => $result->getCriticalCandidates(),
            'high' => array_filter(
                $result->getEditCandidates(),
                fn($c) => $c->getPriority() === EditCandidate::PRIORITY_HIGH
            ),
            'all', '' => $result->getEditCandidatesByPriority(),
            default => $result->getEditCandidatesByType($type),
        };

        if (empty($candidates)) {
            $this->output("No issues found for filter: {$type}");
            return;
        }

        $this->output("\n=== Issues ({$type}) ===");
        foreach ($candidates as $i => $candidate) {
            $this->output(sprintf(
                "%d. [%s] Line %s: %s",
                $i + 1,
                $candidate->getPriorityLabel(),
                $candidate->getLine() ?? '?',
                $candidate->getDescription()
            ));
        }
        $this->output("");
    }

    private function commandShow(string $args): void
    {
        $index = (int)trim($args) - 1;
        $result = $this->session->getAnalysisResult();
        $candidates = $result->getEditCandidatesByPriority();

        if (!isset($candidates[$index])) {
            $this->output("Invalid issue number: " . ($index + 1));
            return;
        }

        $candidate = $candidates[$index];
        $this->output("\n" . $candidate->format());
    }

    private function commandApply(string $args): void
    {
        $index = (int)trim($args) - 1;
        $result = $this->session->getAnalysisResult();
        $candidates = $result->getEditCandidatesByPriority();

        if (!isset($candidates[$index])) {
            $this->output("Invalid issue number: " . ($index + 1));
            return;
        }

        $candidate = $candidates[$index];

        // Создаём change (в реальности здесь была бы логика применения изменения)
        $change = new Change(
            Change::TYPE_CUSTOM,
            "Fixed: " . $candidate->getDescription(),
            $candidate->getNode(),
            [], // beforeState
            []  // afterState
        );

        $this->session->applyChange($change);
        $this->output("Applied change: " . $candidate->getDescription());
    }

    private function commandUndo(): void
    {
        if ($this->session->undo()) {
            $this->output("Change undone");
        } else {
            $this->output("Nothing to undo");
        }
    }

    private function commandRedo(): void
    {
        if ($this->session->redo()) {
            $this->output("Change redone");
        } else {
            $this->output("Nothing to redo");
        }
    }

    private function commandHistory(): void
    {
        $changes = $this->session->getHistory()->getAllChanges();

        if (empty($changes)) {
            $this->output("No changes in history");
            return;
        }

        $this->output("\n=== Change History ===");
        foreach ($changes as $i => $change) {
            $this->output(sprintf("%d. %s", $i + 1, $change->format()));
        }
        $this->output("");
    }

    private function commandPreferences(string $args): void
    {
        $parts = explode(' ', trim($args), 2);

        if (empty($parts[0])) {
            // Показать все предпочтения
            $this->output("\n=== User Preferences ===");
            foreach ($this->session->getAllPreferences() as $key => $value) {
                $this->output(sprintf(
                    "  %s = %s",
                    $key,
                    is_bool($value) ? ($value ? 'true' : 'false') : $value
                ));
            }
            $this->output("");
            return;
        }

        $key = $parts[0];

        if (!isset($parts[1])) {
            // Показать конкретное предпочтение
            $value = $this->session->getPreference($key);
            $this->output("{$key} = " . ($value ?? 'not set'));
            return;
        }

        // Установить предпочтение
        $value = $parts[1];
        if ($value === 'true') $value = true;
        if ($value === 'false') $value = false;
        if (is_numeric($value)) $value = (int)$value;

        $this->session->setPreference($key, $value);
        $this->output("Preference set: {$key} = {$value}");
    }

    private function commandSave(string $args): void
    {
        $path = trim($args);
        if (empty($path)) {
            $path = './interactive_edits.json';
        }

        if ($this->session->savePreferences($path)) {
            $this->output("Preferences saved to: {$path}");
        } else {
            $this->output("Failed to save preferences");
        }
    }

    private function commandReport(): void
    {
        $result = $this->session->getAnalysisResult();
        $this->output("\n" . $result->formatReport());
    }

    private function commandExit(): void
    {
        $this->running = false;
    }

    private function showWelcome(): void
    {
        $this->output("\n╔════════════════════════════════════════════════╗");
        $this->output("║   Interactive Code Editor - Recombinator      ║");
        $this->output("╚════════════════════════════════════════════════╝\n");

        $result = $this->session->getAnalysisResult();
        $this->output($result->getSummary());
        $this->output("\nType 'help' for available commands.\n");
    }

    private function showGoodbye(): void
    {
        $this->output("\n=== Session Summary ===");
        $this->output($this->session->getSessionSummary());
        $this->output("\nGoodbye!\n");
    }

    private function showPrompt(): void
    {
        echo "> ";
    }

    /**
     * Читает ввод пользователя
     */
    private function readInput(): string|false
    {
        return fgets(STDIN);
    }

    /**
     * Выводит текст
     */
    private function output(string $message): void
    {
        echo $message . "\n";
    }
}
