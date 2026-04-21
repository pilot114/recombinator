<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── GET: список примеров ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $examples = [
        [
            'id'    => 'basic',
            'name'  => 'Базовый',
            'files' => [
                [
                    'name' => 'example.php',
                    'code' => <<<'PHP'
<?php

function calculateTotal(array $items, float $taxRate = 0.2): float
{
    $subtotal = 0;
    foreach ($items as $item) {
        $subtotal = $subtotal + $item['price'];
    }
    $tax   = $subtotal * $taxRate;
    $total = $subtotal + $tax;
    return $total;
}

$products = [
    ['name' => 'Apple',  'price' => 1 + 1],
    ['name' => 'Banana', 'price' => 3 * 2],
];

$result = calculateTotal($products);

if (true) {
    echo "Total: " . $result;
} else {
    echo "never";
}

$isArr = is_array($products);
$len   = 1 + 2 + 3;
PHP
                ],
            ],
        ],
        [
            'id'    => 'constants',
            'name'  => 'Константное свёртывание',
            'files' => [
                [
                    'name' => 'constants.php',
                    'code' => <<<'PHP'
<?php

$x   = 2 + 3 * 4;
$s   = 'foo' . 'bar' . '!';
$b   = true && false;
$n   = !false;
$cmp = 10 > 5 ? 'big' : 'small';

if (false) {
    echo "мёртвый код";
} else {
    echo "живой: " . $x;
}

$unused = $x * 0 + 0;
PHP
                ],
            ],
        ],
        [
            'id'    => 'functions',
            'name'  => 'Инлайнинг функций',
            'files' => [
                [
                    'name' => 'functions.php',
                    'code' => <<<'PHP'
<?php

function double(int $x): int
{
    return $x * 2;
}

function square(int $x): int
{
    return $x * $x;
}

$a = double(5);
$b = square(3);
$c = double(4) + square(2);

echo $a + $b + $c;
PHP
                ],
            ],
        ],
        [
            'id'    => 'classes',
            'name'  => 'Классы и методы',
            'files' => [
                [
                    'name' => 'Point.php',
                    'code' => <<<'PHP'
<?php

class Point
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
    ) {}

    public function distanceTo(Point $other): float
    {
        $dx = $this->x - $other->x;
        $dy = $this->y - $other->y;
        return sqrt($dx * $dx + $dy * $dy);
    }

    public function label(): string
    {
        return '(' . $this->x . ', ' . $this->y . ')';
    }
}
PHP
                ],
                [
                    'name' => 'geometry.php',
                    'code' => <<<'PHP'
<?php

$origin = new Point(0.0, 0.0);
$target = new Point(3.0, 4.0);

$distance = $origin->distanceTo($target);
$label    = $target->label();

echo "Distance to " . $label . " = " . $distance;

$unused = $origin->x * 0;
PHP
                ],
            ],
        ],
        [
            'id'    => 'auth',
            'name'  => 'Auth (реальный код)',
            'files' => [
                [
                    'name' => 'functions.php',
                    'code' => <<<'PHP'
<?php

function test($x, $y): float|int|array
{
    return $x + $y;
}
PHP
                ],
                [
                    'name' => 'Auth.php',
                    'code' => <<<'PHP'
<?php

require __DIR__ . '/functions.php';

class Auth
{
    const string HASH = 'test_test';

    public string $test = 'empty';

    public function login(string $username, string $password): string
    {
        if ($username . '_' . $password === self::HASH) {
            return 'success';
        }

        return 'fail';
    }
}
PHP
                ],
                [
                    'name' => 'index.php',
                    'code' => <<<'PHP'
<?php

require __DIR__ . '/Auth.php';

echo 1 . 2 . 3 . 4 . 5 . true . false;

echo 1 / (2 + 3);

echo test(1, 2);

echo is_array([]);

echo Auth::HASH;

$username = 'default_username';
if (isset($_GET['username'])) {
    $username = $_GET['username'];
}

$pass = 'default_pass';
if (isset($_GET['pass'])) {
    $pass = $_GET['pass'];
}

$auth = new Auth();
$result = $auth->login($username, $pass);
echo $result . "\n";

$result = $auth->login('test', 'test');
echo $result . "\n";
PHP
                ],
            ],
        ],
    ];

    echo json_encode(['examples' => $examples], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use PhpParser\Error as PhpParserError;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\BinaryAndIssetVisitor;
use Recombinator\Transformation\Visitor\CoalesceNullRemoveVisitor;
use Recombinator\Transformation\Visitor\CodeBlockVisitor;
use Recombinator\Transformation\Visitor\CallFunctionVisitor;
use Recombinator\Transformation\Visitor\ConcatAssertVisitor;
use Recombinator\Transformation\Visitor\ConcatInterpolateVisitor;
use Recombinator\Transformation\Visitor\ConstClassVisitor;
use Recombinator\Transformation\Visitor\ConstFoldVisitor;
use Recombinator\Transformation\Visitor\ConstructorAndMethodsVisitor;
use Recombinator\Transformation\Visitor\EvalStandardFunction;
use Recombinator\Transformation\Visitor\FunctionBodyCollectorVisitor;
use Recombinator\Transformation\Visitor\FunctionScopeVisitor;
use Recombinator\Transformation\Visitor\PreExecutionVisitor;
use Recombinator\Transformation\Visitor\PropertyAccessVisitor;
use Recombinator\Transformation\Visitor\DeadBranchVisitor;
use Recombinator\Transformation\Visitor\PureFunctionEvaluatorVisitor;
use Recombinator\Transformation\Visitor\ReadabilityVisitor;
use Recombinator\Transformation\Visitor\FileMapIncludeVisitor;
use Recombinator\Transformation\Visitor\RemoveCommentsVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedFunctionVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedPureVisitor;
use Recombinator\Transformation\Visitor\SingleUseInlinerVisitor;
use Recombinator\Transformation\Visitor\TernarReturnVisitor;
use Recombinator\Transformation\Visitor\VarToScalarVisitor;
use Recombinator\Transformation\Visitor\VisitorMeta;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

set_time_limit(30);
ini_set('memory_limit', '256M');

$body  = (string) file_get_contents('php://input');
$input = json_decode($body, true);

// Поддерживаем два формата: {code} и {files, entry}
/** @var array<string, string> $fileMap */
$fileMap = [];

if (!empty($input['files']) && is_array($input['files'])) {
    $entry = (string) ($input['entry'] ?? '');
    $code  = '';
    foreach ($input['files'] as $file) {
        if (!is_array($file) || !isset($file['name'], $file['code'])) {
            continue;
        }
        $fileMap[(string) $file['name']] = (string) $file['code'];
        if ((string) $file['name'] === $entry) {
            $code = (string) $file['code'];
        }
    }
    if ($code === '') {
        $code = end($fileMap) ?: '';
    }
} else {
    $code = trim((string) ($input['code'] ?? ''));
}

if ($code === '') {
    echo json_encode(['error' => 'No code provided']);
    exit;
}

if (!str_starts_with(ltrim($code), '<?')) {
    $code = "<?php\n" . $code;
}

$phpParser = new ParserFactory()->createForNewestSupportedVersion();
$printer   = new Printer();
$differ    = new Differ(new UnifiedDiffOutputBuilder(''));

try {
    $ast = $phpParser->parse($code) ?? [];
} catch (PhpParserError $e) {
    echo json_encode(['error' => 'Parse error: ' . $e->getMessage()]);
    exit;
}

function applyOneVisitor(array &$ast, NodeVisitorAbstract $visitor): string
{
    global $printer;
    $before = $printer->prettyPrint($ast);

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());
    $ast = array_values($t1->traverse($ast));

    $t2 = new NodeTraverser();
    $t2->addVisitor($visitor);
    $ast = array_values($t2->traverse($ast));

    return $before;
}

function getVisitorMeta(NodeVisitorAbstract $visitor): array
{
    $ref   = new ReflectionClass($visitor);
    $attrs = $ref->getAttributes(VisitorMeta::class);
    if (!empty($attrs)) {
        /** @var VisitorMeta $m */
        $m = $attrs[0]->newInstance();
        return ['name' => $m->name ?: $ref->getShortName(), 'desc' => $m->desc];
    }
    return ['name' => $ref->getShortName(), 'desc' => ''];
}

$ss = new ScopeStore();

$makeMainVisitors = static function () use ($ss, $fileMap): array {
    $visitors = [];
    if (!empty($fileMap)) {
        $visitors[] = new FileMapIncludeVisitor($fileMap);
    }
    return array_merge($visitors, [
        new RemoveCommentsVisitor(),
        new BinaryAndIssetVisitor(),
        new CoalesceNullRemoveVisitor(),
        new ConcatAssertVisitor(),
        new EvalStandardFunction(),
        new VarToScalarVisitor($ss),
        new RemoveUnusedPureVisitor(),
        new PreExecutionVisitor($ss),
        new ConstFoldVisitor(),
        new DeadBranchVisitor(),
        new SingleUseInlinerVisitor(),
        new FunctionBodyCollectorVisitor($ss),
        new FunctionScopeVisitor($ss),
        new CallFunctionVisitor($ss),
        new PureFunctionEvaluatorVisitor(),
        new RemoveUnusedFunctionVisitor(),
        new ConstructorAndMethodsVisitor($ss),
        new PropertyAccessVisitor($ss),
        new ConstClassVisitor($ss),
        new TernarReturnVisitor(),
    ]);
};

// Readability visitors run once after main loop converges (like inliner.php)
$makeReadabilityVisitors = static fn(): array => [
    new ReadabilityVisitor(),
    new ConcatInterpolateVisitor(),
    new CodeBlockVisitor(),
];

$steps            = [];
$maxPasses        = 8;
$readabilityPass  = false;

// ── Phase 1: optimisation loop ────────────────────────────────────────────────
for ($pass = 1; $pass <= $maxPasses; $pass++) {
    $passChanged = false;

    foreach ($makeMainVisitors() as $visitor) {
        try {
            $before = applyOneVisitor($ast, $visitor);
            $after  = $printer->prettyPrint($ast);
        } catch (\Throwable) {
            continue;
        }

        if ($before === $after) {
            continue;
        }

        $diff = $differ->diff($before, $after);
        $meta = getVisitorMeta($visitor);

        $steps[] = [
            'pass'    => $pass,
            'visitor' => $meta['name'],
            'desc'    => $meta['desc'],
            'before'  => "<?php\n" . $before,
            'after'   => "<?php\n" . $after,
            'diff'    => $diff,
        ];

        $passChanged = true;
    }

    if (!$passChanged) {
        break;
    }
}

// ── Phase 2: readability (single isolated pass) ───────────────────────────────
foreach ($makeReadabilityVisitors() as $visitor) {
    try {
        $before = applyOneVisitor($ast, $visitor);
        $after  = $printer->prettyPrint($ast);
    } catch (\Throwable) {
        continue;
    }

    if ($before === $after) {
        continue;
    }

    $diff = $differ->diff($before, $after);
    $meta = getVisitorMeta($visitor);

    $readabilityPass = true;
    $steps[] = [
        'pass'    => $pass,
        'visitor' => $meta['name'],
        'desc'    => $meta['desc'],
        'before'  => "<?php\n" . $before,
        'after'   => "<?php\n" . $after,
        'diff'    => $diff,
    ];
}

echo json_encode([
    'original' => $code,
    'final'    => "<?php\n" . $printer->prettyPrint($ast),
    'steps'    => $steps,
    'passes'   => $readabilityPass ? $pass : $pass - 1,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
