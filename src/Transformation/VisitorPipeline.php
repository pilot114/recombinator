<?php

declare(strict_types=1);

namespace Recombinator\Transformation;

use PhpParser\NodeVisitorAbstract;
use Recombinator\Core\ExecutionCache;
use Recombinator\Core\TargetEnvironment;
use Recombinator\Domain\ScopeStore;
use Recombinator\Support\Sandbox;
use Recombinator\Transformation\Visitor\AnonymousClassHoistVisitor;
use Recombinator\Transformation\Visitor\BinaryAndIssetVisitor;
use Recombinator\Transformation\Visitor\BoundedWhileUnrollerVisitor;
use Recombinator\Transformation\Visitor\CallFunctionVisitor;
use Recombinator\Transformation\Visitor\CallSiteInlinerVisitor;
use Recombinator\Transformation\Visitor\ClassExistsFoldVisitor;
use Recombinator\Transformation\Visitor\ClassInlinerVisitor;
use Recombinator\Transformation\Visitor\ClosureCallInlinerVisitor;
use Recombinator\Transformation\Visitor\ClosureClassVisitor;
use Recombinator\Transformation\Visitor\CoalesceNullRemoveVisitor;
use Recombinator\Transformation\Visitor\CodeBlockVisitor;
use Recombinator\Transformation\Visitor\ConcatAssertVisitor;
use Recombinator\Transformation\Visitor\ConditionSimplifierVisitor;
use Recombinator\Transformation\Visitor\ConcatInterpolateVisitor;
use Recombinator\Transformation\Visitor\ConstantArgumentVisitor;
use Recombinator\Transformation\Visitor\ConstClassVisitor;
use Recombinator\Transformation\Visitor\ConstFoldVisitor;
use Recombinator\Transformation\Visitor\ConstructorAndMethodsVisitor;
use Recombinator\Transformation\Visitor\ContainerEntryVisitor;
use Recombinator\Transformation\Visitor\DeadBranchVisitor;
use Recombinator\Transformation\Visitor\DestructuringExpandVisitor;
use Recombinator\Transformation\Visitor\EnumCaseInlinerVisitor;
use Recombinator\Transformation\Visitor\EvalStandardFunction;
use Recombinator\Transformation\Visitor\ExternalParentGuardVisitor;
use Recombinator\Transformation\Visitor\FileMapIncludeVisitor;
use Recombinator\Transformation\Visitor\FlattenInheritanceVisitor;
use Recombinator\Transformation\Visitor\ForeachLiteralUnrollerVisitor;
use Recombinator\Transformation\Visitor\FunctionBodyCollectorVisitor;
use Recombinator\Transformation\Visitor\FunctionScopeVisitor;
use Recombinator\Transformation\Visitor\GlobalConstantVisitor;
use Recombinator\Transformation\Visitor\HeapSnapshotVisitor;
use Recombinator\Transformation\Visitor\IndexedPropGetterResolverVisitor;
use Recombinator\Transformation\Visitor\InlineEmptySubclassVisitor;
use Recombinator\Transformation\Visitor\InstanceofSimplifierVisitor;
use Recombinator\Transformation\Visitor\InterfaceTypeCheckVisitor;
use Recombinator\Transformation\Visitor\InvariantPropertyVisitor;
use Recombinator\Transformation\Visitor\LocalConstantVisitor;
use Recombinator\Transformation\Visitor\MagicConstantVisitor;
use Recombinator\Transformation\Visitor\MethodToSingleReturnVisitor;
use Recombinator\Transformation\Visitor\MutablePropertyTrackerVisitor;
use Recombinator\Transformation\Visitor\NewArgHoistVisitor;
use Recombinator\Transformation\Visitor\ObjectArrayTrackerVisitor;
use Recombinator\Transformation\Visitor\ObjectMethodInlinerVisitor;
use Recombinator\Transformation\Visitor\ObjectStateSpecializerVisitor;
use Recombinator\Transformation\Visitor\OriginalClassAliasVisitor;
use Recombinator\Transformation\Visitor\PolyfillRequireVisitor;
use Recombinator\Transformation\Visitor\PreExecutionVisitor;
use Recombinator\Transformation\Visitor\PrintNormalizeVisitor;
use Recombinator\Transformation\Visitor\ProfileGuardVisitor;
use Recombinator\Transformation\Visitor\ProgramEvaluatorVisitor;
use Recombinator\Transformation\Visitor\PropertyAccessVisitor;
use Recombinator\Transformation\Visitor\PropertyStateTrackerVisitor;
use Recombinator\Transformation\Visitor\PureCallEvaluatorVisitor;
use Recombinator\Transformation\Visitor\PureFunctionEvaluatorVisitor;
use Recombinator\Transformation\Visitor\ReadabilityVisitor;
use Recombinator\Transformation\Visitor\ReadonlyPropertyAccessVisitor;
use Recombinator\Transformation\Visitor\RemoveCommentsVisitor;
use Recombinator\Transformation\Visitor\RemoveDeadCodeAfterJumpVisitor;
use Recombinator\Transformation\Visitor\RemoveDeadLoopVisitor;
use Recombinator\Transformation\Visitor\RemoveGlobalUseVisitor;
use Recombinator\Transformation\Visitor\RemoveInterfacesVisitor;
use Recombinator\Transformation\Visitor\RemoveSplAutoloadVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedClassVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedClosureCapturesVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedFunctionVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedMethodVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedPropWriteVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedPureVisitor;
use Recombinator\Transformation\Visitor\RuntimeAutoloadVisitor;
use Recombinator\Transformation\Visitor\ScopeVisitor;
use Recombinator\Transformation\Visitor\SimpleMethodCallInlinerVisitor;
use Recombinator\Transformation\Visitor\SingleImplementationInterfaceVisitor;
use Recombinator\Transformation\Visitor\SingleUseInlinerVisitor;
use Recombinator\Transformation\Visitor\SmallCallInlinerVisitor;
use Recombinator\Transformation\Visitor\StaticArrayStateVisitor;
use Recombinator\Transformation\Visitor\StaticMethodExtractVisitor;
use Recombinator\Transformation\Visitor\StaticMethodInlinerVisitor;
use Recombinator\Transformation\Visitor\StaticPropConstVisitor;
use Recombinator\Transformation\Visitor\StripLocalClassTypeHintsVisitor;
use Recombinator\Transformation\Visitor\SymfonyRuntimeVisitor;
use Recombinator\Transformation\Visitor\TargetEnvironmentVisitor;
use Recombinator\Transformation\Visitor\TernarReturnVisitor;
use Recombinator\Transformation\Visitor\TraitInlinerVisitor;
use Recombinator\Transformation\Visitor\UnwrittenArrayKeyVisitor;
use Recombinator\Transformation\Visitor\UseImportVisitor;
use Recombinator\Transformation\Visitor\VarToScalarVisitor;

/**
 * Единый список визиторов для всех точек входа (public/api.php, bin/zoo.php).
 *
 * Визиторы пересоздаются на каждом проходе, поэтому список отдаётся заново
 * при каждом вызове; состояние, которое переживает проходы, хранится в полях.
 */
final readonly class VisitorPipeline
{
    /**
     * @param \ArrayObject<string, mixed> $enumCache  кэш enum-методов: enum к поздним проходам уже встроен
     * @param array<string, string>       $fileMap    исходники программы: путь → код
     * @param string|null                 $projectDir реальный путь проекта для проб целевого сервера
     */
    public function __construct(
        private ScopeStore $scopeStore,
        private ExecutionCache $execCache,
        private Sandbox $sandbox,
        private \ArrayObject $enumCache,
        private array $fileMap = [],
        private string $entry = '',
        private TargetEnvironment $environment = new TargetEnvironment(),
        private ?string $projectDir = null,
    ) {
    }

    /**
     * Основной цикл: прогоняется до неподвижной точки.
     *
     * @return list<NodeVisitorAbstract>
     */
    public function main(): array
    {
        $ss        = $this->scopeStore;
        $fileMap   = $this->fileMap;
        $env       = $this->environment;
        $probesDir = dirname(__DIR__, 2) . '/var/cache/probes';

        $visitors = [];
        if ($fileMap !== []) {
            $visitors[] = new FileMapIncludeVisitor($fileMap, $this->entry);
            $visitors[] = new SymfonyRuntimeVisitor();
            $visitors[] = new UseImportVisitor($fileMap);
            // Сразу убираем недостижимые классы: все следующие визиторы обходят меньше кода
            $visitors[] = new RemoveUnusedClassVisitor();
            $visitors[] = new ExternalParentGuardVisitor();
        }

        // Замкнутый мир: все классы программы известны, только если их подтянули из карты файлов
        $closedWorld = $fileMap !== [] ? [new ClassExistsFoldVisitor(), new UnwrittenArrayKeyVisitor(), new AnonymousClassHoistVisitor(), new InterfaceTypeCheckVisitor($fileMap), new RuntimeAutoloadVisitor($fileMap)] : [];
        // Допущения о целевом сервере: class_exists, extension_loaded, PHP_VERSION_ID, getenv…
        if (!$env->isEmpty()) {
            $closedWorld[] = new TargetEnvironmentVisitor($env, $fileMap, $this->projectDir);
            $closedWorld[] = new PolyfillRequireVisitor($env, $fileMap, $this->entry);
            $closedWorld[] = new InvariantPropertyVisitor($env);
            $closedWorld[] = new HeapSnapshotVisitor($env, $this->projectDir, $this->entry, $probesDir);
            $closedWorld[] = new ProfileGuardVisitor($env, $this->projectDir, $this->entry, $probesDir);
        }

        return array_merge($visitors, [
            new RemoveGlobalUseVisitor(),
            new RemoveSplAutoloadVisitor(),

            // Упрощение выражений
            new RemoveCommentsVisitor(),
            new BinaryAndIssetVisitor(),
            new CoalesceNullRemoveVisitor(),
            new ReadonlyPropertyAccessVisitor(),
            new SimpleMethodCallInlinerVisitor(),
            new PropertyStateTrackerVisitor(),
            new StaticArrayStateVisitor(),
            new BoundedWhileUnrollerVisitor(),
            new ForeachLiteralUnrollerVisitor(),
            new MutablePropertyTrackerVisitor(),
            new ConcatAssertVisitor(),
            new EvalStandardFunction(),
            new StaticPropConstVisitor(),
            ...$closedWorld,
            new PreExecutionVisitor($ss, $this->sandbox),
            new VarToScalarVisitor($ss),
            new MagicConstantVisitor(),
            new GlobalConstantVisitor(),
            new ConstantArgumentVisitor(),
            new LocalConstantVisitor(),
            new ConstFoldVisitor(),
            new PrintNormalizeVisitor(),
            new DeadBranchVisitor(),
            new DestructuringExpandVisitor(),
            new InstanceofSimplifierVisitor(),
            new SingleUseInlinerVisitor(),

            // Инлайнинг иерархии классов
            new SingleImplementationInterfaceVisitor($fileMap),
            new RemoveInterfacesVisitor(),
            new TraitInlinerVisitor(),
            new FlattenInheritanceVisitor(),

            // Разворачивание getter-цепочек
            new IndexedPropGetterResolverVisitor($this->sandbox, $this->enumCache),

            // Инлайнинг enum и статических методов
            new EnumCaseInlinerVisitor(),
            new StaticMethodInlinerVisitor(),
            new StaticMethodExtractVisitor(),

            // Инлайнинг функций
            new ScopeVisitor(),
            new FunctionBodyCollectorVisitor($ss),
            new FunctionScopeVisitor($ss),
            new CallFunctionVisitor($ss),
            new PureFunctionEvaluatorVisitor(),
            new PureCallEvaluatorVisitor($this->execCache),
            new RemoveUnusedFunctionVisitor(null, $fileMap),

            // Развёртка живых объектов: new из аргументов, процедуры, состояние, замыкания
            new NewArgHoistVisitor(),
            new ObjectMethodInlinerVisitor(),
            new SmallCallInlinerVisitor($fileMap),
            new CallSiteInlinerVisitor(),
            new ClosureClassVisitor(),
            new ObjectStateSpecializerVisitor(),
            new ContainerEntryVisitor($env->assumes('containerEntriesImmutable')),
            new ClosureCallInlinerVisitor(),

            // Инлайнинг классов
            new MethodToSingleReturnVisitor(),
            new ConstructorAndMethodsVisitor($ss),
            new PropertyAccessVisitor($ss),
            new ConstClassVisitor($ss),
            new TernarReturnVisitor(),
            new ClassInlinerVisitor(),

            // Удаление type-hints локальных классов и мёртвых prop-записей
            new StripLocalClassTypeHintsVisitor(),
            new RemoveUnusedPropWriteVisitor(),

            // Отслеживание коллекций объектов
            new ObjectArrayTrackerVisitor(),

            // Удаление неиспользуемых сущностей
            new InlineEmptySubclassVisitor(),
            new RemoveDeadCodeAfterJumpVisitor(),
            new RemoveUnusedClosureCapturesVisitor(),
            new RemoveUnusedMethodVisitor(null, $fileMap),
            new RemoveUnusedClassVisitor(),
            new RemoveUnusedPureVisitor(),
            new RemoveDeadLoopVisitor(),

            // Детерминированная программа целиком → её вывод
            new ProgramEvaluatorVisitor(),
        ]);
    }

    /**
     * Читаемость: один раз после сходимости основного цикла.
     *
     * @return list<NodeVisitorAbstract>
     */
    public static function readability(): array
    {
        return [
            new ConditionSimplifierVisitor(),
            new ReadabilityVisitor(),
            new ConcatInterpolateVisitor(),
            new CodeBlockVisitor(),
            new OriginalClassAliasVisitor(),
        ];
    }
}
