<?php

declare(strict_types=1);

namespace Recombinator\Support;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * Утилита для цветного форматирования diff вывода
 *
 * Использует ANSI escape коды для раскрашивания текста. Основное применение -
 * отображение различий между версиями кода: добавленные строки выделяются
 * зеленым цветом, удаленные - красным. Также предоставляет возможность
 * раскрашивания произвольного текста в различные цвета.
 */
class ColorDiffer
{
    public bool $hasDiff = false;

    /**
     * 
     *
     * @var array<string, string> 
     */
    private array $foregroundColors = [];

    /**
     * 
     *
     * @var array<string, string> 
     */
    private array $backgroundColors = [];

    private readonly Differ $differ;

    public function __construct()
    {
        $this->foregroundColors['black'] = '0;30';
        $this->foregroundColors['dark_gray'] = '1;30';
        $this->foregroundColors['blue'] = '0;34';
        $this->foregroundColors['light_blue'] = '1;34';
        $this->foregroundColors['green'] = '0;32';
        $this->foregroundColors['light_green'] = '1;32';
        $this->foregroundColors['cyan'] = '0;36';
        $this->foregroundColors['light_cyan'] = '1;36';
        $this->foregroundColors['red'] = '0;31';
        $this->foregroundColors['light_red'] = '1;31';
        $this->foregroundColors['purple'] = '0;35';
        $this->foregroundColors['light_purple'] = '1;35';
        $this->foregroundColors['brown'] = '0;33';
        $this->foregroundColors['yellow'] = '1;33';
        $this->foregroundColors['light_gray'] = '0;37';
        $this->foregroundColors['white'] = '1;37';

        $this->backgroundColors['black'] = '40';
        $this->backgroundColors['red'] = '41';
        $this->backgroundColors['green'] = '42';
        $this->backgroundColors['yellow'] = '43';
        $this->backgroundColors['blue'] = '44';
        $this->backgroundColors['magenta'] = '45';
        $this->backgroundColors['cyan'] = '46';
        $this->backgroundColors['light_gray'] = '47';

        $this->differ = new Differ(new UnifiedDiffOutputBuilder());
    }

    public function getColoredString(string $string, ?string $foreground_color = null, ?string $background_color = null): string
    {
        $colored_string = "";

        if ($foreground_color !== null && isset($this->foregroundColors[$foreground_color])) {
            $colored_string .= "\033[" . $this->foregroundColors[$foreground_color] . "m";
        }

        if ($background_color !== null && isset($this->backgroundColors[$background_color])) {
            $colored_string .= "\033[" . $this->backgroundColors[$background_color] . "m";
        }

        return $colored_string . ($string . "\033[0m");
    }

    /**
     * @return array<int, string>
     */
    public function getForegroundColors(): array
    {
        return array_keys($this->foregroundColors);
    }

    /**
     * @return array<int, string>
     */
    public function getBackgroundColors(): array
    {
        return array_keys($this->backgroundColors);
    }

    public function diff(string $a, string $b, bool $withNotModified = false): string
    {
        $out = $this->differ->diff($a, $b);
        $noDiffResult = "--- Original+++ New";
        $this->hasDiff = str_replace("\n", "", $out) !== $noDiffResult;

        $colorOut = "";
        foreach (explode("\n", $out) as $line) {
            if ($line === '') {
                continue;
            }

            if ($line === '0') {
                continue;
            }

            if (isset($line[0]) && $line[0] === '-') {
                $colorOut .= $this->getColoredString($line, "red") . "\n";
            } elseif (isset($line[0]) && $line[0] === '+') {
                $colorOut .= $this->getColoredString($line, "green") . "\n";
            } elseif ($withNotModified) {
                $colorOut .= $line . "\n";
            }
        }

        return $colorOut;
    }
}
