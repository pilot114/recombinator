<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\SymfonyContainerCacheVisitor;

/** @param array<string, string> $fileMap */
function applySymfonyContainerCache(array $fileMap): string
{
    $code = '<?php class Kernel {
        protected function initializeContainer(): void {
            $cachePath = $this->getBuildDir() . "/Container.php";
            if (is_file($cachePath) && \is_object($this->container = include $cachePath)) { $this->container->set("kernel", $this); return; }
            $container = $this->buildContainer();
            $this->dumpContainer($cachePath, $container);
            $this->container = require $cachePath;
        }
        protected function getKernelParameters(): array { return []; } }';
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new SymfonyContainerCacheVisitor($fileMap, 'public/index.php'))->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

$loader = '<?php
if (\class_exists(\C1\App_KernelProdContainer::class, false)) {
} elseif (!include __DIR__.\'/C1/App_KernelProdContainer.php\') {
    return;
}
return new \C1\App_KernelProdContainer([\'container.build_hash\' => \'C1\'], __DIR__.\DIRECTORY_SEPARATOR.\'C1\');';

it('creates the warmed container instead of including the cache or building one', function () use ($loader): void {
    $result = applySymfonyContainerCache(['var/cache/prod/App_KernelProdContainer.php' => $loader]);

    expect($result)
        ->toContain("\$this->container = new \\C1\\App_KernelProdContainer(['container.build_hash' => 'C1'], __DIR__ . '/../var/cache/prod' . \\DIRECTORY_SEPARATOR . 'C1');\n        \$this->container->set('kernel', \$this);\n    }")
        ->not->toContain('buildContainer')
        ->toContain('getKernelParameters');
});

it('does nothing without exactly one container loader', function (array $fileMap): void {
    expect(applySymfonyContainerCache($fileMap))->toContain('$this->buildContainer()');
})->with([
    'none' => [[]],
    'two'  => [['var/cache/prod/App_KernelProdContainer.php' => $loader, 'var/cache/dev/App_KernelDevDebugContainer.php' => $loader]],
]);
