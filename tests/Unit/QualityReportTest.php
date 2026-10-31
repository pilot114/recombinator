<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use Recombinator\Analysis\QualityReport;

it('measures all source files as one program before and the result after', function (): void {
    $sources = [
        "<?php\nfunction a(\$x) {\n    if (\$x) {\n        return 1;\n    }\n    return 2;\n}\n",
        "<?php\nfunction b(\$y) {\n    foreach (\$y as \$v) {\n        echo \$v;\n    }\n}\n",
        '<?php this is not php',
    ];
    $after = new ParserFactory()->createForNewestSupportedVersion()->parse("<?php\necho 1;\n") ?? [];

    $report = QualityReport::of($sources, $after);

    expect($report['complexity']['before'])->toBe(['cognitive' => 2, 'cyclomatic' => 3, 'lines' => 11, 'nesting' => 1])
        ->and($report['complexity']['after'])->toBe(['cognitive' => 0, 'cyclomatic' => 1, 'lines' => 1, 'nesting' => 0]);
});
