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
 *
 * На больших текстах с множеством повторяющихся строк (например, после копирования
 * методов родителя в наследников) Myers всё равно квадратичен по числу изменений.
 * Тогда используется patience diff: опорными становятся строки, уникальные на обеих
 * сторонах, а Myers считается только по небольшим промежуткам между ними.
 */
final readonly class LineDiffer
{
    /** Начиная с этого числа строк (обе стороны вместе) — patience вместо Myers */
    private const int MYERS_LIMIT = 3000;

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
            return $this->lcsDiff($a, $b);
        }

        $reduced = $this->lcsDiff(
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
     * @param  list<string> $a
     * @param  list<string> $b
     * @return list<array{0: mixed, 1: int}>
     */
    private function lcsDiff(array $a, array $b): array
    {
        if (count($a) + count($b) <= self::MYERS_LIMIT) {
            return $this->differ->diffToArray($a, $b);
        }

        $out = [];
        $this->patience($a, 0, count($a), $b, 0, count($b), $out);

        return $out;
    }

    /**
     * Patience diff по диапазонам [aLo, aHi) и [bLo, bHi).
     *
     * @param list<string>                   $a
     * @param list<string>                   $b
     * @param list<array{0: mixed, 1: int}> $out
     */
    private function patience(array $a, int $aLo, int $aHi, array $b, int $bLo, int $bHi, array &$out): void
    {
        while ($aLo < $aHi && $bLo < $bHi && $a[$aLo] === $b[$bLo]) {
            $out[] = [$a[$aLo++], Differ::OLD];
            $bLo++;
        }

        $suffix = [];
        while ($aLo < $aHi && $bLo < $bHi && $a[$aHi - 1] === $b[$bHi - 1]) {
            $suffix[] = $a[--$aHi];
            $bHi--;
        }

        if ($aLo === $aHi || $bLo === $bHi || ($aHi - $aLo) + ($bHi - $bLo) <= self::MYERS_LIMIT) {
            if ($aLo === $aHi || $bLo === $bHi) {
                $this->appendChange($out, array_slice($a, $aLo, $aHi - $aLo), array_slice($b, $bLo, $bHi - $bLo));
            } else {
                foreach ($this->differ->diffToArray(array_slice($a, $aLo, $aHi - $aLo), array_slice($b, $bLo, $bHi - $bLo)) as $item) {
                    if ($item[1] !== Differ::DIFF_LINE_END_WARNING) {
                        $out[] = $item;
                    }
                }
            }
        } else {
            $anchors = $this->uniqueAnchors($a, $aLo, $aHi, $b, $bLo, $bHi);
            if ($anchors === []) {
                $this->appendChange($out, array_slice($a, $aLo, $aHi - $aLo), array_slice($b, $bLo, $bHi - $bLo));
            } else {
                $pa = $aLo;
                $pb = $bLo;
                foreach ($anchors as [$ai, $bi]) {
                    $this->patience($a, $pa, $ai, $b, $pb, $bi, $out);
                    $out[] = [$a[$ai], Differ::OLD];
                    $pa    = $ai + 1;
                    $pb    = $bi + 1;
                }

                $this->patience($a, $pa, $aHi, $b, $pb, $bHi, $out);
            }
        }

        foreach (array_reverse($suffix) as $line) {
            $out[] = [$line, Differ::OLD];
        }
    }

    /**
     * Строки, встречающиеся ровно по разу на каждой стороне, — наибольшая
     * возрастающая по позиции в $b подпоследовательность (patience sorting).
     *
     * @param  list<string> $a
     * @param  list<string> $b
     * @return list<array{int, int}>
     */
    private function uniqueAnchors(array $a, int $aLo, int $aHi, array $b, int $bLo, int $bHi): array
    {
        $countA = [];
        for ($i = $aLo; $i < $aHi; $i++) {
            $countA[$a[$i]] = ($countA[$a[$i]] ?? 0) + 1;
        }

        $posB   = [];
        $countB = [];
        for ($j = $bLo; $j < $bHi; $j++) {
            $countB[$b[$j]] = ($countB[$b[$j]] ?? 0) + 1;
            $posB[$b[$j]]   = $j;
        }

        $pairs = [];
        for ($i = $aLo; $i < $aHi; $i++) {
            $line = $a[$i];
            if ($countA[$line] === 1 && ($countB[$line] ?? 0) === 1) {
                $pairs[] = [$i, $posB[$line]];
            }
        }

        // LIS по второй координате
        $tails = [];
        $prev  = [];
        foreach ($pairs as $k => [, $bj]) {
            $lo = 0;
            $hi = count($tails);
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($pairs[$tails[$mid]][1] < $bj) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }

            $prev[$k]   = $lo > 0 ? $tails[$lo - 1] : -1;
            $tails[$lo] = $k;
        }

        $result = [];
        for ($k = $tails === [] ? -1 : end($tails); $k >= 0; $k = $prev[$k]) {
            $result[] = $pairs[$k];
        }

        return array_reverse($result);
    }

    /**
     * @param list<array{0: mixed, 1: int}> $out
     * @param list<string>                   $removed
     * @param list<string>                   $added
     */
    private function appendChange(array &$out, array $removed, array $added): void
    {
        $this->flushBlock($out, $removed, $added);
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
