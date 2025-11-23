<?php

declare(strict_types=1);

namespace Recombinator\Support;

use SebastianBergmann\Diff\Differ;

/**
 * Раскрашивает текст, в данном случае - дифф в зелёный и красный
 */
class ColorDiffer
{
    public bool $hasDiff = false;

    /** @var array<string, string> */
    private array $foreground_colors = [];

    /** @var array<string, string> */
    private array $background_colors = [];

    private Differ $differ;

    public function __construct()
    {
        $this->foreground_colors['black'] = '0;30';
        $this->foreground_colors['dark_gray'] = '1;30';
        $this->foreground_colors['blue'] = '0;34';
        $this->foreground_colors['light_blue'] = '1;34';
        $this->foreground_colors['green'] = '0;32';
        $this->foreground_colors['light_green'] = '1;32';
        $this->foreground_colors['cyan'] = '0;36';
        $this->foreground_colors['light_cyan'] = '1;36';
        $this->foreground_colors['red'] = '0;31';
        $this->foreground_colors['light_red'] = '1;31';
        $this->foreground_colors['purple'] = '0;35';
        $this->foreground_colors['light_purple'] = '1;35';
        $this->foreground_colors['brown'] = '0;33';
        $this->foreground_colors['yellow'] = '1;33';
        $this->foreground_colors['light_gray'] = '0;37';
        $this->foreground_colors['white'] = '1;37';

        $this->background_colors['black'] = '40';
        $this->background_colors['red'] = '41';
        $this->background_colors['green'] = '42';
        $this->background_colors['yellow'] = '43';
        $this->background_colors['blue'] = '44';
        $this->background_colors['magenta'] = '45';
        $this->background_colors['cyan'] = '46';
        $this->background_colors['light_gray'] = '47';

        $this->differ = new Differ(new \SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder());
    }

    public function getColoredString(string $string, ?string $foreground_color = null, ?string $background_color = null): string
    {
        $colored_string = "";

        if ($foreground_color !== null && isset($this->foreground_colors[$foreground_color])) {
            $colored_string .= "\033[" . $this->foreground_colors[$foreground_color] . "m";
        }
        if ($background_color !== null && isset($this->background_colors[$background_color])) {
            $colored_string .= "\033[" . $this->background_colors[$background_color] . "m";
        }

        $colored_string .= $string . "\033[0m";

        return $colored_string;
    }

    /**
     * @return array<int, string>
     */
    public function getForegroundColors(): array
    {
        return array_keys($this->foreground_colors);
    }

    /**
     * @return array<int, string>
     */
    public function getBackgroundColors(): array
    {
        return array_keys($this->background_colors);
    }

    public function diff(string $a, string $b, bool $withNotModified = false): string
    {
        $out = $this->differ->diff($a, $b);
        $noDiffResult = "--- Original+++ New";
        $this->hasDiff = str_replace("\n", "", $out) !== $noDiffResult;

        $colorOut = "";
        foreach (explode("\n", $out) as $line) {
            if (!$line) continue;
            if (isset($line[0]) && $line[0] === '-') {
                $colorOut .= $this->getColoredString($line, "red") . "\n";
            } elseif (isset($line[0]) && $line[0] === '+') {
                $colorOut .= $this->getColoredString($line, "green") . "\n";
            } else {
                if ($withNotModified) {
                    $colorOut .= $line . "\n";
                }
            }
        }
        return $colorOut;
    }
}