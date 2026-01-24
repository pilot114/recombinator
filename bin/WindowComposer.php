<?php

declare(strict_types=1);

namespace Cli;

/**
 * Компоновщик окон для CLI утилит
 */
class WindowComposer
{
    private array $windows = [];
    private array $separators = [];

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

        // Рисуем разделители поверх окон
        foreach ($this->separators as $separator) {
            $this->renderSeparator($output, $separator);
        }

        return implode("\n", $output);
    }

    /**
     * Рендерит разделитель в output
     */
    private function renderSeparator(array &$output, Separator $separator): void
    {
        if ($separator->orientation === 'vertical') {
            for ($row = $separator->y; $row < $separator->y + $separator->height; $row++) {
                if ($row >= 0 && $row < $this->terminalHeight && $separator->x < $this->terminalWidth) {
                    $output[$row] = mb_substr($output[$row], 0, $separator->x)
                        . $separator->char
                        . mb_substr($output[$row], $separator->x + 1);
                }
            }
        } else {
            $row = $separator->y;
            if ($row >= 0 && $row < $this->terminalHeight) {
                $line = str_repeat($separator->char, $separator->width);
                $output[$row] = mb_substr($output[$row], 0, $separator->x)
                    . $line
                    . mb_substr($output[$row], $separator->x + $separator->width);
            }
        }
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
        $this->separators = [];
    }

    /**
     * Удаляет конкретное окно
     */
    public function remove(Window $window): void
    {
        $this->windows = array_filter($this->windows, static fn($w) => $w !== $window);
    }

    /**
     * Разделяет терминал на N вертикальных колонок равной ширины
     *
     * @param int $count Количество колонок (по умолчанию 2)
     * @param string $style Стиль границ (9 символов)
     * @param BorderMode $borderMode Режим отрисовки границ
     * @return Window[] Массив созданных окон
     */
    public function splitVertical(
        int $count = 2,
        string $style = '┌─┐│ │└─┘',
        BorderMode $borderMode = BorderMode::PerWindow
    ): array {
        if ($count < 1) {
            throw new \InvalidArgumentException('Количество колонок должно быть >= 1');
        }

        $windows = [];

        // Для режима Between резервируем место под разделители
        $separatorCount = $borderMode === BorderMode::Between ? $count - 1 : 0;
        $availableWidth = $this->terminalWidth - $separatorCount;

        $colWidth = (int) floor($availableWidth / $count);
        $remainder = $availableWidth % $count;

        $x = 0;
        for ($i = 0; $i < $count; $i++) {
            $width = $colWidth + ($i < $remainder ? 1 : 0);

            $window = new Window(
                style: $style,
                content: '',
                edge: EdgeAlignment::Absolute,
                size: 0,
                terminalWidth: $this->terminalWidth,
                terminalHeight: $this->terminalHeight,
                absoluteX: $x,
                absoluteY: 0,
            );
            $window->setFixedDimensions($width, $this->terminalHeight);
            $window->setBorderMode($borderMode);

            $this->windows[] = $window;
            $windows[] = $window;

            $x += $width;

            // Добавляем разделитель между колонками (кроме последней)
            if ($borderMode === BorderMode::Between && $i < $count - 1) {
                $this->addVerticalSeparator($x, 0, $this->terminalHeight, $style);
                $x += 1;
            }
        }

        return $windows;
    }

    /**
     * Разделяет терминал на N горизонтальных строк равной высоты
     *
     * @param int $count Количество строк (по умолчанию 2)
     * @param string $style Стиль границ (9 символов)
     * @param BorderMode $borderMode Режим отрисовки границ
     * @return Window[] Массив созданных окон
     */
    public function splitHorizontal(
        int $count = 2,
        string $style = '┌─┐│ │└─┘',
        BorderMode $borderMode = BorderMode::PerWindow
    ): array {
        if ($count < 1) {
            throw new \InvalidArgumentException('Количество строк должно быть >= 1');
        }

        $windows = [];

        // Для режима Between резервируем место под разделители
        $separatorCount = $borderMode === BorderMode::Between ? $count - 1 : 0;
        $availableHeight = $this->terminalHeight - $separatorCount;

        $rowHeight = (int) floor($availableHeight / $count);
        $remainder = $availableHeight % $count;

        $y = 0;
        for ($i = 0; $i < $count; $i++) {
            $height = $rowHeight + ($i < $remainder ? 1 : 0);

            $window = new Window(
                style: $style,
                content: '',
                edge: EdgeAlignment::Absolute,
                size: 0,
                terminalWidth: $this->terminalWidth,
                terminalHeight: $this->terminalHeight,
                absoluteX: 0,
                absoluteY: $y,
            );
            $window->setFixedDimensions($this->terminalWidth, $height);
            $window->setBorderMode($borderMode);

            $this->windows[] = $window;
            $windows[] = $window;

            $y += $height;

            // Добавляем разделитель между строками (кроме последней)
            if ($borderMode === BorderMode::Between && $i < $count - 1) {
                $this->addHorizontalSeparator(0, $y, $this->terminalWidth, $style);
                $y += 1;
            }
        }

        return $windows;
    }

    /**
     * Добавляет вертикальный разделитель
     */
    private function addVerticalSeparator(int $x, int $y, int $height, string $style): void
    {
        $border = BorderStyle::fromString($style);
        $separator = new Separator(
            x: $x,
            y: $y,
            width: 1,
            height: $height,
            char: $border->left,
            orientation: 'vertical'
        );
        $this->separators[] = $separator;
    }

    /**
     * Добавляет горизонтальный разделитель
     */
    private function addHorizontalSeparator(int $x, int $y, int $width, string $style): void
    {
        $border = BorderStyle::fromString($style);
        $separator = new Separator(
            x: $x,
            y: $y,
            width: $width,
            height: 1,
            char: $border->top,
            orientation: 'horizontal'
        );
        $this->separators[] = $separator;
    }

    /**
     * Создаёт сетку окон NxM
     *
     * @param int $cols Количество колонок
     * @param int $rows Количество строк
     * @param string $style Стиль границ (9 символов)
     * @param BorderMode $borderMode Режим отрисовки границ
     * @return Window[][] Двумерный массив окон [row][col]
     */
    public function grid(
        int $cols,
        int $rows,
        string $style = '┌─┐│ │└─┘',
        BorderMode $borderMode = BorderMode::PerWindow
    ): array {
        if ($cols < 1 || $rows < 1) {
            throw new \InvalidArgumentException('Количество колонок и строк должно быть >= 1');
        }

        $grid = [];

        // Для режима Between резервируем место под разделители
        $hSeparators = $borderMode === BorderMode::Between ? $rows - 1 : 0;
        $vSeparators = $borderMode === BorderMode::Between ? $cols - 1 : 0;

        $availableWidth = $this->terminalWidth - $vSeparators;
        $availableHeight = $this->terminalHeight - $hSeparators;

        $colWidth = (int) floor($availableWidth / $cols);
        $rowHeight = (int) floor($availableHeight / $rows);
        $colRemainder = $availableWidth % $cols;
        $rowRemainder = $availableHeight % $rows;

        // Предвычисляем позиции X для колонок
        $colPositions = [];
        $colWidths = [];
        $x = 0;
        for ($col = 0; $col < $cols; $col++) {
            $colPositions[$col] = $x;
            $colWidths[$col] = $colWidth + ($col < $colRemainder ? 1 : 0);
            $x += $colWidths[$col];
            if ($borderMode === BorderMode::Between && $col < $cols - 1) {
                $x += 1; // место под разделитель
            }
        }

        $y = 0;
        for ($row = 0; $row < $rows; $row++) {
            $height = $rowHeight + ($row < $rowRemainder ? 1 : 0);
            $grid[$row] = [];

            for ($col = 0; $col < $cols; $col++) {
                $window = new Window(
                    style: $style,
                    content: '',
                    edge: EdgeAlignment::Absolute,
                    size: 0,
                    terminalWidth: $this->terminalWidth,
                    terminalHeight: $this->terminalHeight,
                    absoluteX: $colPositions[$col],
                    absoluteY: $y,
                );
                $window->setFixedDimensions($colWidths[$col], $height);
                $window->setBorderMode($borderMode);

                $this->windows[] = $window;
                $grid[$row][$col] = $window;
            }

            $y += $height;

            // Добавляем горизонтальный разделитель после строки (кроме последней)
            if ($borderMode === BorderMode::Between && $row < $rows - 1) {
                $this->addHorizontalSeparator(0, $y, $this->terminalWidth, $style);
                $y += 1;
            }
        }

        // Добавляем вертикальные разделители
        if ($borderMode === BorderMode::Between) {
            $sepX = 0;
            for ($col = 0; $col < $cols - 1; $col++) {
                $sepX += $colWidths[$col];
                $this->addVerticalSeparator($sepX, 0, $this->terminalHeight, $style);
                $sepX += 1;
            }
        }

        return $grid;
    }

    /**
     * Возвращает ширину терминала
     */
    public function getTerminalWidth(): int
    {
        return $this->terminalWidth;
    }

    /**
     * Возвращает высоту терминала
     */
    public function getTerminalHeight(): int
    {
        return $this->terminalHeight;
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
 * Режим отрисовки границ
 */
enum BorderMode
{
    /** Каждое окно имеет свою границу */
    case PerWindow;
    /** Граница между блоками (не принадлежит блокам) */
    case Between;
    /** Без границ */
    case None;
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
    private bool $fixedDimensions = false;
    private BorderMode $borderMode = BorderMode::PerWindow;

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
        // Не пересчитываем, если размеры зафиксированы
        if ($this->fixedDimensions) {
            return;
        }

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
        return match ($this->borderMode) {
            BorderMode::PerWindow => $this->getLinesWithBorder(),
            BorderMode::Between, BorderMode::None => $this->getLinesWithoutBorder(),
        };
    }

    /**
     * Рендерит окно с границами (режим PerWindow)
     */
    private function getLinesWithBorder(): array
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

    /**
     * Рендерит окно без границ (режимы Between и None)
     */
    private function getLinesWithoutBorder(): array
    {
        $lines = [];
        $contentLines = explode("\n", $this->content);

        // Контент занимает всю ширину
        foreach ($contentLines as $line) {
            $lines[] = mb_str_pad($line, $this->width, $this->borderStyle->background);
        }

        // Дополнительные пустые строки до полной высоты
        $contentHeight = count($contentLines);

        for ($i = $contentHeight; $i < $this->height; $i++) {
            $lines[] = str_repeat($this->borderStyle->background, $this->width);
        }

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

    /**
     * Устанавливает фиксированные размеры окна
     */
    public function setFixedDimensions(int $width, int $height): self
    {
        $this->width = $width;
        $this->height = $height;
        $this->fixedDimensions = true;
        return $this;
    }

    /**
     * Сбрасывает фиксированные размеры (размеры будут вычисляться по контенту)
     */
    public function resetFixedDimensions(): self
    {
        $this->fixedDimensions = false;
        $this->calculateDimensions();
        return $this;
    }

    /**
     * Возвращает внутреннюю ширину окна (без границ)
     */
    public function getInnerWidth(): int
    {
        return match ($this->borderMode) {
            BorderMode::PerWindow => $this->width - 2,
            BorderMode::Between, BorderMode::None => $this->width,
        };
    }

    /**
     * Возвращает внутреннюю высоту окна (без границ)
     */
    public function getInnerHeight(): int
    {
        return match ($this->borderMode) {
            BorderMode::PerWindow => $this->height - 2,
            BorderMode::Between, BorderMode::None => $this->height,
        };
    }

    /**
     * Устанавливает режим отрисовки границ
     */
    public function setBorderMode(BorderMode $mode): self
    {
        $this->borderMode = $mode;
        return $this;
    }

    /**
     * Возвращает режим отрисовки границ
     */
    public function getBorderMode(): BorderMode
    {
        return $this->borderMode;
    }
}

/**
 * Класс для разделителя между окнами
 */
readonly class Separator
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
        public string $char,
        public string $orientation, // 'vertical' или 'horizontal'
    ) {}
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