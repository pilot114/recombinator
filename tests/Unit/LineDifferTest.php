<?php

declare(strict_types=1);

use Recombinator\Support\LineDiffer;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;

beforeEach(
    function (): void {
        $builder = new StrictUnifiedDiffOutputBuilder(['addLineNumbers' => false, 'header' => '']);
        $this->lineDiffer = new LineDiffer($builder);
        $this->differ = new Differ($builder);
    }
);

/**
 * Восстанавливает обе стороны из diff-массива
 *
 * @param  list<array{0: string, 1: int}> $diff
 * @return array{0: string, 1: string}
 */
function sidesOf(array $diff): array
{
    $from = '';
    $to   = '';
    foreach ($diff as [$line, $type]) {
        if ($type !== Differ::ADDED) {
            $from .= $line;
        }

        if ($type !== Differ::REMOVED) {
            $to .= $line;
        }
    }

    return [$from, $to];
}

/**
 * @param  list<array{0: string, 1: int}> $diff
 * @return array{0: int, 1: int}
 */
function changeCounts(array $diff): array
{
    $types = array_column($diff, 1);

    return [count(array_keys($types, Differ::ADDED, true)), count(array_keys($types, Differ::REMOVED, true))];
}

it(
    'matches Differ when every line exists on both sides',
    function (): void {
        $from = "a\nb\nc\nb\na";
        $to   = "b\na\nc\na\nb";

        expect($this->lineDiffer->diff($from, $to))->toBe($this->differ->diff($from, $to));
    }
);

it(
    'handles large insertion of new lines',
    function (): void {
        $from = "<?php\nrequire 'a.php';\necho 1;";
        $to   = "<?php\n" . implode("\n", array_map(fn(int $i): string => "\$x{$i} = {$i};", range(1, 3000))) . "\necho 1;";

        $diff = $this->lineDiffer->diffToArray($from, $to);

        expect(sidesOf($diff))->toBe([$from, $to])
            ->and(changeCounts($diff))->toBe([3000, 1]);
    }
);

it(
    'keeps removed lines before added inside a change block',
    function (): void {
        $diff = $this->lineDiffer->diffToArray("keep\nold1\nold2\nkeep2", "keep\nnew1\nnew2\nkeep2");

        expect(array_column($diff, 1))->toBe([Differ::OLD, Differ::REMOVED, Differ::REMOVED, Differ::ADDED, Differ::ADDED, Differ::OLD]);
    }
);

it(
    'produces valid minimal diffs on random inputs',
    function (): void {
        mt_srand(42);
        $alphabet = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];

        for ($n = 0; $n < 300; $n++) {
            $from = implode("\n", array_map(fn(): string => $alphabet[mt_rand(0, 6)], range(0, mt_rand(0, 25))));
            $to   = implode("\n", array_map(fn(): string => $alphabet[mt_rand(3, 9)], range(0, mt_rand(0, 25))));

            $fast = $this->lineDiffer->diffToArray($from, $to);
            $ref  = $this->differ->diffToArray($from, $to);

            expect(sidesOf($fast))->toBe([$from, $to])
                ->and(changeCounts($fast))->toBe(changeCounts($ref));
        }
    }
);

it(
    'returns empty diff for equal strings',
    function (): void {
        expect($this->lineDiffer->diff("a\nb", "a\nb"))->toBe('');
    }
);
