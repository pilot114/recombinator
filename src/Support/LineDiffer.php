<?php

declare(strict_types=1);

namespace Recombinator\Support;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\DiffOutputBuilderInterface;

/**
 * Построчный diff, быстрый на крупных вставках и удалениях
 *
 * Differ из sebastian/diff гоняет Myers по всей изменённой середине: O((N+M)·D),
 * и шаг вроде «подключили 3000 строк vendor» или «удалили все комментарии» считается
 * сотни миллисекунд. Строка, которой нет на другой стороне, не может войти в общую
 * подпоследовательность, поэтому такие строки сразу помечаются добавленными/удалёнными,
 * а Myers считается только по оставшимся (приём из GNU diff). Diff остаётся минимальным.
 */
final readonly class LineDiffer
{
    private Differ $differ;

    public function __construct(private DiffOutputBuilderInterface $outputBuilder)
    {
        $this->differ = new Differ($outputBuilder);
    }

    public function diff(string $from, string $to): string
    {
        return $this->outputBuilder->getDiff($this->diffToArray($from, $to));
    }

    /**
     * Формат как у Differ::diffToArray: [строка, Differ::OLD|ADDED|REMOVED]
     *
     * @return list<array{0: mixed, 1: int}>
     */
    public function diffToArray(string $from, string $to): array
    {
        $a = $this->splitLines($from);
        $b = $this->splitLines($to);

        $inA = array_flip($a);
        $inB = array_flip($b);

        $keptA = array_keys(array_filter($a, static fn(string $line): bool => isset($inB[$line])));
        $keptB = array_keys(array_filter($b, static fn(string $line): bool => isset($inA[$line])));

        if (count($keptA) === count($a) && count($keptB) === count($b)) {
            return $this->differ->diffToArray($a, $b);
        }

        $reduced = $this->differ->diffToArray(
            array_map(static fn(int $i): string => $a[$i], $keptA),
            array_map(static fn(int $i): string => $b[$i], $keptB),
        );

        // Возвращаем отброшенные строки на их места: всё, что лежит между двумя
        // совпадениями, — один блок изменений, в нём сначала удаления, потом добавления
        $diff = [];
        if (($reduced[0][1] ?? null) === Differ::DIFF_LINE_END_WARNING) {
            $diff[] = array_shift($reduced);
        }

        $removed = [];
        $added   = [];
        $i       = 0;
        $j       = 0;
        $pa      = 0;
        $pb      = 0;

        foreach ($reduced as [$line, $type]) {
            if ($type === Differ::OLD) {
                for ($fi = $keptA[$pa++]; $i < $fi; $i++) {
                    $removed[] = $a[$i];
                }

                for ($tj = $keptB[$pb++]; $j < $tj; $j++) {
                    $added[] = $b[$j];
                }

                $this->flushBlock($diff, $removed, $added);
                $diff[] = [$line, Differ::OLD];
                $i++;
                $j++;
            } elseif ($type === Differ::REMOVED) {
                for ($fi = $keptA[$pa++]; $i <= $fi; $i++) {
                    $removed[] = $a[$i];
                }
            } elseif ($type === Differ::ADDED) {
                for ($tj = $keptB[$pb++]; $j <= $tj; $j++) {
                    $added[] = $b[$j];
                }
            }
        }

        array_push($removed, ...array_slice($a, $i));
        array_push($added, ...array_slice($b, $j));

        $this->flushBlock($diff, $removed, $added);

        return $diff;
    }

    /**
     * Дописывает накопленный блок изменений: сначала удаления, потом добавления
     *
     * @param list<array{0: mixed, 1: int}> $diff
     * @param list<string>                   $removed
     * @param list<string>                   $added
     */
    private function flushBlock(array &$diff, array &$removed, array &$added): void
    {
        foreach ($removed as $line) {
            $diff[] = [$line, Differ::REMOVED];
        }

        foreach ($added as $line) {
            $diff[] = [$line, Differ::ADDED];
        }

        $removed = [];
        $added   = [];
    }

    /**
     * Строки вместе с переводом строки — так же, как их режет Differ
     *
     * @return list<string>
     */
    private function splitLines(string $input): array
    {
        $lines = preg_split('/(.*\R)/', $input, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return $lines === false ? [] : $lines;
    }
}
