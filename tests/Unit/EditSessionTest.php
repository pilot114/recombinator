<?php

declare(strict_types=1);

use PhpParser\Node\Expr\Variable;
use PhpParser\ParserFactory;
use Recombinator\Interactive\EditSession;
use Recombinator\Interactive\InteractiveEditAnalyzer;
use Recombinator\Interactive\Change;
use Recombinator\Interactive\InteractiveEditResult;
use Recombinator\Interactive\ChangeHistory;

beforeEach(function () {
    $parser = (new ParserFactory())->createForHostVersion();
    $code = '<?php $x = 123; $tmp = "test";';
    $ast = $parser->parse($code);

    $analyzer = new InteractiveEditAnalyzer();
    $result = $analyzer->analyze($ast);

    $this->session = new EditSession($ast, $result);
});

describe('Session Initialization', function () {
    it('initializes with AST and analysis result', function () {
        expect($this->session->getCurrentAst())->toBeArray()
            ->and($this->session->getAnalysisResult())->toBeInstanceOf(InteractiveEditResult::class)
            ->and($this->session->getHistory())->toBeInstanceOf(ChangeHistory::class);
    });

    it('loads default preferences', function () {
        $prefs = $this->session->getAllPreferences();

        expect($prefs)->toBeArray()
            ->and($prefs)->toHaveKey('auto_apply_safe_changes')
            ->and($prefs)->toHaveKey('show_suggestions')
            ->and($prefs)->toHaveKey('prioritize_critical');
    });

    it('tracks session start time', function () {
        $duration = $this->session->getSessionDuration();

        expect($duration)->toBeGreaterThanOrEqual(0)
            ->and($duration)->toBeLessThan(5); // Should be less than 5 seconds
    });
});

describe('Applying Changes', function () {
    it('applies a change', function () {
        $node = new Variable('x');
        $change = new Change(
            Change::TYPE_RENAME,
            'Renamed variable',
            $node,
            ['old' => 'x'],
            ['new' => 'username']
        );

        $result = $this->session->applyChange($change);

        expect($result)->toBeTrue()
            ->and($this->session->getTotalAppliedChanges())->toBe(1);
    });

    it('updates statistics when applying changes', function () {
        $node = new Variable('x');

        $change1 = new Change(Change::TYPE_RENAME, 'Change 1', $node, [], []);
        $change2 = new Change(Change::TYPE_RENAME, 'Change 2', $node, [], []);
        $change3 = new Change(Change::TYPE_EXTRACT, 'Change 3', $node, [], []);

        $this->session->applyChange($change1);
        $this->session->applyChange($change2);
        $this->session->applyChange($change3);

        $stats = $this->session->getAppliedChangesStats();

        expect($stats[Change::TYPE_RENAME])->toBe(2)
            ->and($stats[Change::TYPE_EXTRACT])->toBe(1)
            ->and($this->session->getTotalAppliedChanges())->toBe(3);
    });
});

describe('Undo/Redo', function () {
    beforeEach(function () {
        $node = new Variable('x');
        for ($i = 1; $i <= 3; $i++) {
            $change = new Change(
                Change::TYPE_RENAME,
                "Change {$i}",
                $node,
                [],
                []
            );
            $this->session->applyChange($change);
        }
    });

    it('can undo changes', function () {
        expect($this->session->getTotalAppliedChanges())->toBe(3);

        $result = $this->session->undo();

        expect($result)->toBeTrue()
            ->and($this->session->getTotalAppliedChanges())->toBe(2);
    });

    it('can redo changes', function () {
        $this->session->undo();

        expect($this->session->getTotalAppliedChanges())->toBe(2);

        $result = $this->session->redo();

        expect($result)->toBeTrue()
            ->and($this->session->getTotalAppliedChanges())->toBe(3);
    });

    it('returns false when nothing to undo', function () {
        $this->session->undo();
        $this->session->undo();
        $this->session->undo();

        $result = $this->session->undo();

        expect($result)->toBeFalse();
    });

    it('returns false when nothing to redo', function () {
        $result = $this->session->redo();

        expect($result)->toBeFalse();
    });
});

describe('Preferences Management', function () {
    it('can set and get preferences', function () {
        $this->session->setPreference('test_key', 'test_value');

        expect($this->session->getPreference('test_key'))->toBe('test_value');
    });

    it('returns default value for missing preference', function () {
        $value = $this->session->getPreference('non_existent', 'default');

        expect($value)->toBe('default');
    });

    it('returns all preferences', function () {
        $this->session->setPreference('custom', 'value');

        $all = $this->session->getAllPreferences();

        expect($all)->toBeArray()
            ->and($all)->toHaveKey('custom')
            ->and($all['custom'])->toBe('value');
    });

    it('can save preferences to file', function () {
        $tempFile = tempnam(sys_get_temp_dir(), 'prefs_');

        $this->session->setPreference('test', 'value');
        $result = $this->session->savePreferences($tempFile);

        expect($result)->toBeTrue()
            ->and(file_exists($tempFile))->toBeTrue();

        // Cleanup
        unlink($tempFile);
    });

    it('can load preferences from file', function () {
        $tempFile = tempnam(sys_get_temp_dir(), 'prefs_');
        file_put_contents($tempFile, json_encode(['loaded_key' => 'loaded_value']));

        $result = $this->session->loadPreferences($tempFile);

        expect($result)->toBeTrue()
            ->and($this->session->getPreference('loaded_key'))->toBe('loaded_value');

        // Cleanup
        unlink($tempFile);
    });

    it('returns false when loading non-existent file', function () {
        $result = $this->session->loadPreferences('/non/existent/file.json');

        expect($result)->toBeFalse();
    });
});

describe('Session Statistics', function () {
    it('tracks session duration', function () {
        sleep(1);

        $duration = $this->session->getSessionDuration();

        expect($duration)->toBeGreaterThanOrEqual(1);
    });

    it('generates session summary', function () {
        $node = new Variable('x');
        $change = new Change(Change::TYPE_RENAME, 'Test', $node, [], []);
        $this->session->applyChange($change);

        $summary = $this->session->getSessionSummary();

        expect($summary)->toBeString()
            ->and($summary)->toContain('Session Duration')
            ->and($summary)->toContain('Applied Changes');
    });

    it('includes change statistics in summary', function () {
        $node = new Variable('x');
        $change = new Change(Change::TYPE_RENAME, 'Test', $node, [], []);
        $this->session->applyChange($change);

        $summary = $this->session->getSessionSummary();

        expect($summary)->toContain('Changes by type')
            ->and($summary)->toContain(Change::TYPE_RENAME);
    });
});
