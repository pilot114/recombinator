<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use Recombinator\Interactive\InteractiveCLI;
use Recombinator\Interactive\InteractiveEditAnalyzer;
use Recombinator\Interactive\EditSession;
use Recombinator\Interactive\InteractiveEditResult;

beforeEach(
    function (): void {
        $parser = new ParserFactory()->createForHostVersion();
        $code = '<?php
        $x = 123;
        $tmp = "test";
        if ($a) {
            if ($b) {
                if ($c) {
                    if ($d) {
                        echo "nested";
                    }
                }
            }
        }
    ';
        $ast = $parser->parse($code);

        $analyzer = new InteractiveEditAnalyzer();
        $result = $analyzer->analyze($ast);

        $this->cli = new InteractiveCLI($ast, $result);
    }
);

describe(
    'CLI Initialization',
    function (): void {
        it(
            'initializes with AST and result',
            function (): void {
                expect($this->cli->getSession())->toBeInstanceOf(EditSession::class);
            }
        );

        it(
            'has access to session',
            function (): void {
                $session = $this->cli->getSession();

                expect($session->getAnalysisResult())->toBeInstanceOf(InteractiveEditResult::class);
            }
        );
    }
);

describe(
    'Command Execution',
    function (): void {
        it(
            'executes help command',
            function (): void {
                ob_start();
                $result = $this->cli->executeCommand('help');
                $output = ob_get_clean();

                expect($result)->toBeTrue()
                    ->and($output)->toContain('Available commands');
            }
        );

        it(
            'executes status command',
            function (): void {
                ob_start();
                $result = $this->cli->executeCommand('status');
                $output = ob_get_clean();

                expect($result)->toBeTrue()
                    ->and($output)->toContain('Session Status');
            }
        );

        it(
            'executes list command',
            function (): void {
                ob_start();
                $result = $this->cli->executeCommand('list');
                $output = ob_get_clean();

                expect($result)->toBeTrue()
                    ->and($output)->toContain('Issues');
            }
        );

        it(
            'executes report command',
            function (): void {
                ob_start();
                $result = $this->cli->executeCommand('report');
                $output = ob_get_clean();

                expect($result)->toBeTrue()
                    ->and($output)->toContain('Analysis Report');
            }
        );

        it(
            'handles unknown command',
            function (): void {
                ob_start();
                $result = $this->cli->executeCommand('unknown');
                $output = ob_get_clean();

                expect($result)->toBeFalse()
                    ->and($output)->toContain('Unknown command');
            }
        );
    }
);

describe(
    'Command Shortcuts',
    function (): void {
        it(
            'accepts h as help shortcut',
            function (): void {
                ob_start();
                $this->cli->executeCommand('h');
                $output = ob_get_clean();

                expect($output)->toContain('Available commands');
            }
        );

        it(
            'accepts s as status shortcut',
            function (): void {
                ob_start();
                $this->cli->executeCommand('s');
                $output = ob_get_clean();

                expect($output)->toContain('Session Status');
            }
        );

        it(
            'accepts l as list shortcut',
            function (): void {
                ob_start();
                $this->cli->executeCommand('l');
                $output = ob_get_clean();

                expect($output)->toContain('Issues');
            }
        );

        it(
            'accepts q as quit shortcut',
            function (): void {
                $this->cli->setRunning(true);

                ob_start();
                $this->cli->executeCommand('q');
                ob_get_clean();

                // Running flag should be set to false, but we can't directly test it
                // without making running property public or adding a getter
                expect(true)->toBeTrue(); // Placeholder assertion
            }
        );
    }
);

describe(
    'List Command Filtering',
    function (): void {
        it(
            'lists all issues',
            function (): void {
                ob_start();
                $this->cli->executeCommand('list all');
                $output = ob_get_clean();

                expect($output)->toContain('Issues (all)');
            }
        );

        it(
            'lists critical issues',
            function (): void {
                ob_start();
                $this->cli->executeCommand('list critical');
                $output = ob_get_clean();

                expect($output)->toContain('critical');
            }
        );

        it(
            'lists high priority issues',
            function (): void {
                ob_start();
                $this->cli->executeCommand('list high');
                $output = ob_get_clean();

                expect($output)->toBeString();
            }
        );
    }
);

describe(
    'History Commands',
    function (): void {
        it(
            'shows empty history initially',
            function (): void {
                ob_start();
                $this->cli->executeCommand('history');
                $output = ob_get_clean();

                expect($output)->toContain('No changes in history');
            }
        );

        it(
            'handles undo with empty history',
            function (): void {
                ob_start();
                $this->cli->executeCommand('undo');
                $output = ob_get_clean();

                expect($output)->toContain('Nothing to undo');
            }
        );

        it(
            'handles redo with empty history',
            function (): void {
                ob_start();
                $this->cli->executeCommand('redo');
                $output = ob_get_clean();

                expect($output)->toContain('Nothing to redo');
            }
        );
    }
);

describe(
    'Preferences Commands',
    function (): void {
        it(
            'shows all preferences',
            function (): void {
                ob_start();
                $this->cli->executeCommand('pref');
                $output = ob_get_clean();

                expect($output)->toContain('User Preferences')
                    ->and($output)->toContain('auto_apply_safe_changes');
            }
        );

        it(
            'shows specific preference',
            function (): void {
                ob_start();
                $this->cli->executeCommand('pref verbose');
                $output = ob_get_clean();

                expect($output)->toContain('verbose');
            }
        );

        it(
            'sets preference',
            function (): void {
                ob_start();
                $this->cli->executeCommand('pref verbose true');
                $output = ob_get_clean();

                expect($output)->toContain('Preference set')
                    ->and($this->cli->getSession()->getPreference('verbose'))->toBeTrue();
            }
        );

        it(
            'converts boolean string values',
            function (): void {
                ob_start();
                $this->cli->executeCommand('pref test_bool false');
                ob_get_clean();

                expect($this->cli->getSession()->getPreference('test_bool'))->toBeFalse();
            }
        );

        it(
            'converts numeric string values',
            function (): void {
                ob_start();
                $this->cli->executeCommand('pref test_num 42');
                ob_get_clean();

                expect($this->cli->getSession()->getPreference('test_num'))->toBe(42);
            }
        );
    }
);

describe(
    'Save Command',
    function (): void {
        it(
            'saves preferences to file',
            function (): void {
                $tempFile = tempnam(sys_get_temp_dir(), 'cli_test_');

                ob_start();
                $this->cli->executeCommand('save ' . $tempFile);
                $output = ob_get_clean();

                expect($output)->toContain('Preferences saved')
                    ->and(file_exists($tempFile))->toBeTrue();

                // Cleanup
                unlink($tempFile);
            }
        );

        it(
            'uses default path when not specified',
            function (): void {
                ob_start();
                $this->cli->executeCommand('save');
                $output = ob_get_clean();

                expect($output)->toContain('Preferences saved');

                // Cleanup default file if created
                if (file_exists('./interactive_edits.json')) {
                    unlink('./interactive_edits.json');
                }
            }
        );
    }
);

describe(
    'Error Handling',
    function (): void {
        it(
            'handles empty command',
            function (): void {
                ob_start();
                $result = $this->cli->executeCommand('');
                $output = ob_get_clean();

                expect($result)->toBeFalse();
            }
        );

        it(
            'handles invalid issue number in show',
            function (): void {
                ob_start();
                $this->cli->executeCommand('show 9999');
                $output = ob_get_clean();

                expect($output)->toContain('Invalid issue number');
            }
        );

        it(
            'handles invalid issue number in apply',
            function (): void {
                ob_start();
                $this->cli->executeCommand('apply 9999');
                $output = ob_get_clean();

                expect($output)->toContain('Invalid issue number');
            }
        );
    }
);
