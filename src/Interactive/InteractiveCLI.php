<?php

declare(strict_types=1);

namespace Recombinator\Interactive;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

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
    private readonly EditSession $session;

    private bool $running = false;


    /**
     * @var array<string, callable(string): void>
     */
    private array $commands;

    /**
     * @param Node[] $ast
     */
    public function __construct(array $ast, InteractiveEditResult $analysisResult)
    {
        $this->session = new EditSession($ast, $analysisResult);
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
            if ($input === false) {
                break; // конец ввода (Ctrl+D, закрытый pipe)
            }

            if (trim($input) === '') {
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
            $this->output(sprintf("Unknown command: %s. Type 'help' for available commands.", $cmd));
            return false;
        }

        try {
            $this->commands[$cmd]($args);
            return true;
        } catch (\InvalidArgumentException $invalidArgumentException) {
            $this->output("Error executing command: " . $invalidArgumentException->getMessage());
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

            'list' => $this->commandList(...),
            'l' => $this->commandList(...),

            'show' => $this->commandShow(...),

            'apply' => $this->commandApply(...),
            'a' => $this->commandApply(...),

            'undo' => fn($args) => $this->commandUndo(),
            'u' => fn($args) => $this->commandUndo(),

            'redo' => fn($args) => $this->commandRedo(),
            'r' => fn($args) => $this->commandRedo(),

            'history' => fn($args) => $this->commandHistory(),

            'pref' => $this->commandPreferences(...),

            'save' => $this->commandSave(...),

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
        $this->output("  apply <n> [name]   - Rename variable of issue n (to name or first suggestion)");
        $this->output("  undo, u            - Undo last change");
        $this->output("  redo, r            - Redo undone change");
        $this->output("  history            - Show change history");
        $this->output("  pref [key] [value] - View or set preferences");
        $this->output("  save <path>        - Save edited code to file");
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
                fn(\Recombinator\Interactive\EditCandidate $c): bool => $c->getPriority() === EditCandidate::PRIORITY_HIGH
            ),
            'all', '' => $result->getEditCandidatesByPriority(),
            default => $result->getEditCandidatesByType($type),
        };

        if ($candidates === []) {
            $this->output('No issues found for filter: ' . $type);
            return;
        }

        $this->output("\n=== Issues ({$type}) ===");
        $index = 1;
        foreach ($candidates as $candidate) {
            $this->output(
                sprintf(
                    "%d. [%s] Line %s: %s",
                    $index,
                    $candidate->getPriorityLabel(),
                    (string) ($candidate->getLine() ?? '?'),
                    $candidate->getDescription()
                )
            );
            $index++;
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
        [$number, $name] = array_pad(preg_split('/\s+/', trim($args), 2) ?: [], 2, '');
        $index      = (int) $number - 1;
        $candidates = $this->session->getAnalysisResult()->getEditCandidatesByPriority();

        if (!isset($candidates[$index])) {
            $this->output("Invalid issue number: " . ($index + 1));
            return;
        }

        // Автоматически применимо только переименование; остальное (магические числа,
        // вложенность) правится вручную
        $candidate = $candidates[$index];
        $node      = $candidate->getNode();
        $variable  = $node instanceof Node\Expr\Assign ? $node->var : $node;
        if (!$variable instanceof Node\Expr\Variable || !is_string($variable->name)) {
            $this->output('Issue #' . $number . ' cannot be fixed automatically: ' . $candidate->getDescription());
            return;
        }

        // Имя из подсказки «Consider: $name», если не указано явно
        $suggested = array_filter(array_map(
            static fn(string $s): ?string => preg_match('/^Consider: \$(\w+)$/', $s, $m) === 1 ? $m[1] : null,
            $candidate->getSuggestions(),
        ));
        $to = ltrim($name, '$') ?: (reset($suggested) ?: '');
        if ($to === '') {
            $this->output('Specify the new name: apply ' . $number . ' <name>');
            return;
        }

        $from = $variable->name;
        $this->session->applyChange(new Change(Change::TYPE_RENAME, sprintf('Renamed $%s to $%s', $from, $to), $variable, ['old' => $from], ['new' => $to]));
        $this->output(sprintf('Renamed $%s to $%s', $from, $to));
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

        if ($changes === []) {
            $this->output("No changes in history");
            return;
        }

        $this->output("\n=== Change History ===");
        $index = 1;
        foreach ($changes as $change) {
            $this->output(sprintf("%d. %s", $index, $change->format()));
            $index++;
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
                if (is_bool($value)) {
                    $valueStr = $value ? 'true' : 'false';
                } elseif (is_scalar($value)) {
                    $valueStr = (string) $value;
                } else {
                    $valueStr = json_encode($value) ?: 'unknown';
                }

                $this->output(
                    sprintf(
                        "  %s = %s",
                        $key,
                        $valueStr
                    )
                );
            }

            $this->output("");
            return;
        }

        $key = $parts[0];

        if (!isset($parts[1])) {
            // Показать конкретное предпочтение
            $value = $this->session->getPreference($key);
            if ($value === null) {
                $valueStr = 'not set';
            } elseif (is_bool($value)) {
                $valueStr = $value ? 'true' : 'false';
            } elseif (is_scalar($value)) {
                $valueStr = (string) $value;
            } else {
                $valueStr = json_encode($value) ?: 'unknown';
            }

            $this->output($key . ' = ' . $valueStr);
            return;
        }

        // Установить предпочтение
        $value = $parts[1];
        if ($value === 'true') {
            $value = true;
        }

        if ($value === 'false') {
            $value = false;
        }

        if (is_numeric($value)) {
            $value = (int)$value;
        }

        $this->session->setPreference($key, $value);
        $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $this->output(sprintf('Preference set: %s = %s', $key, $displayValue));
    }

    private function commandSave(string $args): void
    {
        $path = trim($args);
        if ($path === '') {
            $this->output('Specify the file: save <path>');
            return;
        }

        $code = new Standard()->prettyPrintFile($this->session->getCurrentAst()) . "\n";
        $this->output(file_put_contents($path, $code) !== false ? 'Code saved to: ' . $path : 'Failed to save code to: ' . $path);
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
