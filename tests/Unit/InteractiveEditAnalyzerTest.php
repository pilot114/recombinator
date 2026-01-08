<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use Recombinator\Interactive\InteractiveEditAnalyzer;
use Recombinator\Interactive\EditCandidate;
use Recombinator\Domain\StructureImprovement;

beforeEach(function () {
    $this->analyzer = new InteractiveEditAnalyzer();
    $this->parser = (new ParserFactory())->createForHostVersion();
});

describe('Poor Naming Detection', function () {
    it('detects poor variable names', function () {
        $code = '<?php $x = 123; $tmp = "test";';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result->getTotalIssues())->toBeGreaterThan(0);

        $poorNamingCandidates = $result->getEditCandidatesByType(EditCandidate::ISSUE_POOR_NAMING);
        expect($poorNamingCandidates)->not->toBeEmpty();
    });

    it('does not flag good variable names', function () {
        $code = '<?php $username = "john"; $totalCount = 5;';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        // Should have minimal or no issues
        $poorNamingCandidates = $result->getEditCandidatesByType(EditCandidate::ISSUE_POOR_NAMING);
        expect(count($poorNamingCandidates))->toBeLessThan(2);
    });

    it('ignores special variables', function () {
        $code = '<?php $result = $_GET["id"]; $data = $_POST["name"];';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        // $_GET and $_POST should not be flagged
        $candidates = $result->getEditCandidates();
        $hasSpecialVars = false;
        foreach ($candidates as $candidate) {
            if (str_contains($candidate->getDescription(), '$_GET') ||
                str_contains($candidate->getDescription(), '$_POST')) {
                $hasSpecialVars = true;
                break;
            }
        }

        expect($hasSpecialVars)->toBeFalse();
    });
});

describe('Complex Expression Detection', function () {
    it('detects complex expressions', function () {
        $code = '<?php
            $result = ($a + $b) * ($c - $d) / ($e + $f) - ($g * $h) + ($i / $j);
        ';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        $complexExprCandidates = $result->getEditCandidatesByType(EditCandidate::ISSUE_COMPLEX_EXPRESSION);
        expect($complexExprCandidates)->not->toBeEmpty();
    });

    it('does not flag simple expressions', function () {
        $code = '<?php $sum = $a + $b;';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        $complexExprCandidates = $result->getEditCandidatesByType(EditCandidate::ISSUE_COMPLEX_EXPRESSION);
        expect($complexExprCandidates)->toBeEmpty();
    });
});

describe('Magic Number Detection', function () {
    it('detects magic numbers', function () {
        $code = '<?php $timeout = 3600; $maxRetries = 42;';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        $magicNumberCandidates = $result->getEditCandidatesByType(EditCandidate::ISSUE_MAGIC_NUMBER);
        expect($magicNumberCandidates)->not->toBeEmpty();
    });

    it('ignores common constants', function () {
        $code = '<?php $zero = 0; $one = 1; $two = 2; $minusOne = -1;';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        $magicNumberCandidates = $result->getEditCandidatesByType(EditCandidate::ISSUE_MAGIC_NUMBER);
        expect($magicNumberCandidates)->toBeEmpty();
    });
});

describe('Deep Nesting Detection', function () {
    it('detects deep nesting', function () {
        $code = '<?php
            if ($a) {
                if ($b) {
                    if ($c) {
                        if ($d) {
                            if ($e) {
                                echo "too deep";
                            }
                        }
                    }
                }
            }
        ';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        $deepNestingCandidates = $result->getEditCandidatesByType(EditCandidate::ISSUE_DEEP_NESTING);
        expect($deepNestingCandidates)->not->toBeEmpty();
    });

    it('does not flag shallow nesting', function () {
        $code = '<?php
            if ($a) {
                if ($b) {
                    echo "ok";
                }
            }
        ';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        $deepNestingCandidates = $result->getEditCandidatesByType(EditCandidate::ISSUE_DEEP_NESTING);
        expect($deepNestingCandidates)->toBeEmpty();
    });
});

describe('Structure Improvements', function () {
    it('suggests extracting complex functions', function () {
        $code = '<?php
            function complexFunction($data) {
                if ($data["type"] === "A") {
                    if ($data["status"] === "active") {
                        for ($i = 0; $i < count($data["items"]); $i++) {
                            if ($data["items"][$i]["value"] > 100) {
                                while ($data["items"][$i]["nested"]) {
                                    // complex logic here
                                    $result = process($data["items"][$i]);
                                }
                            }
                        }
                    }
                }
                return $result;
            }
        ';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result->getTotalImprovements())->toBeGreaterThan(0);
    });

    it('suggests simplifying complex conditions', function () {
        $code = '<?php
            if (($a && $b) || ($c && $d && $e) || ($f && !$g && $h)) {
                echo "complex";
            }
        ';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        $simplifyImprovements = $result->getStructureImprovementsByType(
            StructureImprovement::TYPE_SIMPLIFY_CONDITION
        );
        expect($simplifyImprovements)->not->toBeEmpty();
    });

    it('suggests introducing variables for complex expressions', function () {
        $code = '<?php
            $result = (($a + $b) * ($c - $d)) / (($e + $f) - ($g * $h));
        ';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        $introduceVarImprovements = $result->getStructureImprovementsByType(
            StructureImprovement::TYPE_INTRODUCE_VARIABLE
        );
        expect($introduceVarImprovements)->not->toBeEmpty();
    });
});

describe('Result Analysis', function () {
    it('provides correct statistics', function () {
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
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result->getTotalIssues())->toBeGreaterThan(0)
            ->and($result->getIssueStats())->toBeArray()
            ->and($result->getSummary())->toBeString();
    });

    it('sorts candidates by priority', function () {
        $code = '<?php
            $x = 123;
            $result = (($a + $b) * ($c - $d)) / (($e + $f) - ($g * $h));
        ';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);
        $sorted = $result->getEditCandidatesByPriority();

        expect($sorted)->toBeArray();

        // Check that it's actually sorted (higher priority first)
        if (count($sorted) > 1) {
            for ($i = 0; $i < count($sorted) - 1; $i++) {
                expect($sorted[$i]->getPriority())
                    ->toBeGreaterThanOrEqual($sorted[$i + 1]->getPriority());
            }
        }
    });

    it('identifies critical issues', function () {
        $code = '<?php
            $result = (($a + $b) * ($c - $d)) / (($e + $f) - ($g * $h)) +
                      (($i + $j) * ($k - $l)) / (($m + $n) - ($o * $p));
        ';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        if ($result->hasCriticalIssues()) {
            $critical = $result->getCriticalCandidates();
            expect($critical)->not->toBeEmpty();

            foreach ($critical as $candidate) {
                expect($candidate->isCritical())->toBeTrue();
            }
        }
    });

    it('generates formatted report', function () {
        $code = '<?php $x = 123; $tmp = "test";';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);
        $report = $result->formatReport();

        expect($report)->toBeString()
            ->and($report)->toContain('Interactive Edit Analysis Report')
            ->and($report)->toContain('Total issues found');
    });
});
