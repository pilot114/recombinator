<?php

declare(strict_types=1);

namespace Cli;

/**
 * Компоновщик окон для CLI утилит
 */
class WindowComposer
{
    private array $windows = [];

    public function __construct(
        private ?int $terminalWidth = null,
        private ?int $terminalHeight = null,
    ) {
        $this->terminalWidth ??= (int) exec('tput cols') ?: 80;
        $this->terminalHeight ??= (int) exec('tput lines') ?: 24;
    }

    /**
     * Добавляет новое окно в компоновку
     */
    public function append(
        string $style,
        string $content,
        EdgeAlignment $edge,
        int $size = 0,
        ?int $absoluteX = null,
        ?int $absoluteY = null
    ): Window {
        if (mb_strlen($style) !== 9) {
            throw new \InvalidArgumentException('Стиль должен содержать ровно 9 символов');
        }

        if ($edge === EdgeAlignment::Absolute && ($absoluteX === null || $absoluteY === null)) {
            throw new \InvalidArgumentException('Для absolute позиционирования необходимы координаты X и Y');
        }

        $window = new Window(
            style: $style,
            content: $content,
            edge: $edge,
            size: $size,
            terminalWidth: $this->terminalWidth,
            terminalHeight: $this->terminalHeight,
            absoluteX: $absoluteX,
            absoluteY: $absoluteY,
        );

        $this->windows[] = $window;

        return $window;
    }

    /**
     * Рендерит все окна в единый вывод
     */
    public function render(): string
    {
        $output = array_fill(0, $this->terminalHeight, str_repeat(' ', $this->terminalWidth));

        // Сортируем окна по уровню (level) перед отрисовкой
        $sortedWindows = $this->windows;
        usort($sortedWindows, fn(Window $a, Window $b) => $a->level <=> $b->level);

        foreach ($sortedWindows as $window) {
            $lines = $window->getLines();
            ['x' => $x, 'y' => $y] = $window->getPosition();

            foreach ($lines as $idx => $line) {
                $row = $y + $idx;

                if ($row < 0 || $row >= $this->terminalHeight) {
                    continue;
                }

                $lineLen = mb_strlen($line);

                if ($x < 0 || $x >= $this->terminalWidth) {
                    continue;
                }

                $availableWidth = min($lineLen, $this->terminalWidth - $x);
                $output[$row] = mb_substr($output[$row], 0, $x)
                    . mb_substr($line, 0, $availableWidth)
                    . mb_substr($output[$row], $x + $availableWidth);
            }
        }

        return implode("\n", $output);
    }

    /**
     * Выводит отрендеренный результат в консоль
     */
    public function display(): void
    {
        echo $this->render();
    }

    /**
     * Очищает все окна
     */
    public function clear(): void
    {
        $this->windows = [];
    }

    /**
     * Удаляет конкретное окно
     */
    public function remove(Window $window): void
    {
        $this->windows = array_filter($this->windows, static fn($w) => $w !== $window);
    }
}

/**
 * Enum для выравнивания окон
 */
enum EdgeAlignment
{
    case Top;
    case Bottom;
    case Left;
    case Right;
    case Center;
    case Absolute;
}

/**
 * Класс представляющий отдельное окно
 */
class Window
{
    private BorderStyle $borderStyle;
    public string $title = '';
    private string $content;
    public array $position;

    public int $level = 0;

    private int $width;
    private int $height;

    public function __construct(
        string $style,
        string $content,
        private EdgeAlignment $edge,
        private readonly int $size,
        private readonly int $terminalWidth,
        private readonly int $terminalHeight,
        private ?int $absoluteX = null,
        private ?int $absoluteY = null,
    ) {
        $this->borderStyle = BorderStyle::fromString($style);
        $this->content = $content;

        $this->calculateDimensions();
        $this->calculatePosition();
    }

    private function calculateDimensions(): void
    {
        $lines = explode("\n", $this->content);
        $maxContentWidth = max(array_map('mb_strlen', $lines));

        // Учитываем заголовок при расчете ширины
        if ($this->title !== '') {
            $titleWidth = mb_strlen($this->title) + 2;
            $maxContentWidth = max($maxContentWidth, $titleWidth);
        }

        [$this->width, $this->height] = match ($this->edge) {
            EdgeAlignment::Left, EdgeAlignment::Right => [
                $this->size,
                count($lines) + 2,
            ],
            EdgeAlignment::Top, EdgeAlignment::Bottom => [
                $maxContentWidth + 2,
                $this->size,
            ],
            EdgeAlignment::Absolute, EdgeAlignment::Center => [
                $maxContentWidth + 2,
                count($lines) + 2,
            ],
        };
    }

    private function calculatePosition(): void
    {
        $this->position = match ($this->edge) {
            EdgeAlignment::Absolute => [
                'x' => $this->absoluteX ?? 0,
                'y' => $this->absoluteY ?? 0,
            ],
            EdgeAlignment::Top => [
                'x' => (int)(($this->terminalWidth - $this->width) / 2),
                'y' => 0,
            ],
            EdgeAlignment::Bottom => [
                'x' => (int)(($this->terminalWidth - $this->width) / 2),
                'y' => $this->terminalHeight - $this->height,
            ],
            EdgeAlignment::Left => [
                'x' => 0,
                'y' => (int)(($this->terminalHeight - $this->height) / 2),
            ],
            EdgeAlignment::Right => [
                'x' => $this->terminalWidth - $this->width,
                'y' => (int)(($this->terminalHeight - $this->height) / 2),
            ],
            EdgeAlignment::Center => [
                'x' => (int)(($this->terminalWidth - $this->width) / 2),
                'y' => (int)(($this->terminalHeight - $this->height) / 2),
            ],
        };
    }

    /**
     * Возвращает отрендеренные строки окна
     */
    public function getLines(): array
    {
        $lines = [];
        $contentLines = explode("\n", $this->content);
        $innerWidth = $this->width - 2;

        // Верхняя граница с заголовком
        $lines[] = $this->title !== ''
            ? $this->renderTopBorderWithTitle($innerWidth)
            : $this->borderStyle->topLeft
            . str_repeat($this->borderStyle->top, $innerWidth)
            . $this->borderStyle->topRight;

        // Контент
        foreach ($contentLines as $line) {
            $padded = mb_str_pad($line, $innerWidth, $this->borderStyle->background);
            $lines[] = $this->borderStyle->left . $padded . $this->borderStyle->right;
        }

        // Дополнительные пустые строки
        $contentHeight = count($contentLines);
        $targetHeight = $this->height - 2;

        for ($i = $contentHeight; $i < $targetHeight; $i++) {
            $lines[] = $this->borderStyle->left
                . str_repeat($this->borderStyle->background, $innerWidth)
                . $this->borderStyle->right;
        }

        // Нижняя граница
        $lines[] = $this->borderStyle->bottomLeft
            . str_repeat($this->borderStyle->bottom, $innerWidth)
            . $this->borderStyle->bottomRight;

        return $lines;
    }

    private function renderTopBorderWithTitle(int $innerWidth): string
    {
        $titleText = ' ' . $this->title . ' ';
        $titleLen = mb_strlen($titleText);

        if ($titleLen >= $innerWidth) {
            // Заголовок слишком длинный, обрезаем
            $titleText = mb_substr($titleText, 0, $innerWidth);
            return $this->borderStyle->topLeft . $titleText . $this->borderStyle->topRight;
        }

        // Центрируем заголовок
        $leftPadding = (int)(($innerWidth - $titleLen) / 2);
        $rightPadding = $innerWidth - $titleLen - $leftPadding;

        return $this->borderStyle->topLeft
            . str_repeat($this->borderStyle->top, $leftPadding)
            . $titleText
            . str_repeat($this->borderStyle->top, $rightPadding)
            . $this->borderStyle->topRight;
    }

    /**
     * Возвращает позицию окна
     */
    public function getPosition(): array
    {
        return $this->position;
    }

    /**
     * Устанавливает абсолютную позицию окна
     */
    public function setPosition(int $x, int $y): self
    {
        $this->position = ['x' => $x, 'y' => $y];
        $this->edge = EdgeAlignment::Absolute;
        $this->absoluteX = $x;
        $this->absoluteY = $y;
        return $this;
    }

    /**
     * Возвращает заголовок окна
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Устанавливает заголовок окна
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;
        $this->calculateDimensions();
        return $this;
    }

    /**
     * Возвращает контент окна
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Обновляет контент окна
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        $this->calculateDimensions();
        return $this;
    }

    /**
     * Устанавливает уровень отрисовки окна (z-index)
     */
    public function setLevel(int $level): self
    {
        $this->level = $level;
        return $this;
    }

    /**
     * Перемещает окно на передний план
     */
    public function bringToFront(int $increment = 1): self
    {
        $this->level += $increment;
        return $this;
    }

    /**
     * Перемещает окно на задний план
     */
    public function sendToBack(int $decrement = 1): self
    {
        $this->level -= $decrement;
        return $this;
    }

    /**
     * Возвращает размеры окна
     */
    public function getDimensions(): array
    {
        return ['width' => $this->width, 'height' => $this->height];
    }
}

/**
 * Класс для хранения стиля границ
 */
readonly class BorderStyle
{
    public function __construct(
        public string $topLeft,
        public string $top,
        public string $topRight,
        public string $left,
        public string $background,
        public string $right,
        public string $bottomLeft,
        public string $bottom,
        public string $bottomRight,
    ) {}

    public static function fromString(string $style): self
    {
        $chars = mb_str_split($style);

        return new self(
            topLeft: $chars[0],
            top: $chars[1],
            topRight: $chars[2],
            left: $chars[3],
            background: $chars[4],
            right: $chars[5],
            bottomLeft: $chars[6],
            bottom: $chars[7],
            bottomRight: $chars[8],
        );
    }
}

/**
 * Вспомогательная функция для дополнения строки с поддержкой многобайтовых символов
 */
function mb_str_pad(string $input, int $padLength, string $padString = ' ', int $padType = STR_PAD_RIGHT): string
{
    $diff = strlen($input) - mb_strlen($input);
    return str_pad($input, $padLength + $diff, $padString, $padType);
}