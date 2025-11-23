# Архитектура проекта Recombinator

## Обзор

Recombinator - это инструмент для статического анализа и трансформации PHP кода, специализирующийся на разделении побочных эффектов и оптимизации сложности.

## Структура проекта

Проект организован в соответствии с принципами разделения ответственности (SRP) и модульной архитектурой:

```
src/
├── Contract/          # Интерфейсы для основных компонентов
├── Core/              # Ядро системы (парсинг, кеш, утилиты)
├── Analysis/          # Анализаторы и калькуляторы
├── Transformation/    # Трансформации кода и visitor'ы
├── Interactive/       # Интерактивный CLI
├── Domain/            # Доменные объекты и value objects
└── Support/           # Вспомогательные классы
```

---

## 1. Contract - Интерфейсы

Директория `Contract/` содержит интерфейсы, определяющие контракты для основных компонентов системы.

### Основные интерфейсы:

#### ComplexityCalculatorInterface
```php
use Recombinator\Contract\ComplexityCalculatorInterface;

interface ComplexityCalculatorInterface
{
    public function calculate(array $nodes): int;
    public function getComplexityLevel(int $value): string;
}
```

**Назначение:** Определяет контракт для калькуляторов сложности кода.

**Реализации:**
- `CognitiveComplexityCalculator` - когнитивная сложность
- `CyclomaticComplexityCalculator` - цикломатическая сложность

#### EffectClassifierInterface
```php
use Recombinator\Contract\EffectClassifierInterface;

interface EffectClassifierInterface
{
    public function classify(Node $node): SideEffectType;
    public function isPureFunction(string $functionName): bool;
}
```

**Назначение:** Определяет контракт для классификаторов побочных эффектов.

**Реализация:** `SideEffectClassifier`

#### SymbolTableInterface
```php
use Recombinator\Contract\SymbolTableInterface;

interface SymbolTableInterface
{
    public function setVarToScope(string $name, mixed $value): void;
    public function getVarFromScope(string $name): mixed;
    public function setCurrentScope(?string $scopeName): void;
}
```

**Назначение:** Определяет контракт для таблицы символов (scope store).

**Реализация:** `ScopeStore`

---

## 2. Core - Ядро системы

Директория `Core/` содержит основные компоненты системы: парсер, кеш, утилиты.

### Компоненты:

#### Parser
```php
use Recombinator\Core\Parser;

$parser = new Parser();
$parser->run($updateCache = false);
```

**Назначение:** Главный компонент для парсинга и обработки PHP файлов.

**Ключевые методы:**
- `run(bool $updateCache)` - запуск обработки
- `buildAST(string $code)` - построение AST
- `prettyPrint(array $ast)` - вывод кода

#### ExecutionCache
```php
use Recombinator\Core\ExecutionCache;

$cache = new ExecutionCache();
$cache->set('key', $value);
$value = $cache->get('key');
```

**Назначение:** Управление кешем выполнения для оптимизации производительности.

#### Fluent
```php
use Recombinator\Core\Fluent;

$fluent = new Fluent(['key' => 'value']);
$value = $fluent->key;
```

**Назначение:** Fluent интерфейс для работы с данными.

#### PrettyDumper
```php
use Recombinator\Core\PrettyDumper;

$dumper = new PrettyDumper();
echo $dumper->dump($nodes);
```

**Назначение:** Форматированный вывод AST узлов для отладки.

---

## 3. Analysis - Анализаторы

Директория `Analysis/` содержит компоненты для анализа кода: калькуляторы сложности, классификаторы побочных эффектов.

### Компоненты:

#### CognitiveComplexityCalculator
```php
use Recombinator\Analysis\CognitiveComplexityCalculator;

$calculator = new CognitiveComplexityCalculator();
$complexity = $calculator->calculate($nodes);
$level = $calculator->getComplexityLevel($complexity);
// Результат: "low", "moderate", "high", "very high"
```

**Назначение:** Вычисление когнитивной сложности кода.

**Метрики:**
- Вложенность условий
- Циклы
- Рекурсия
- Boolean операторы

#### CyclomaticComplexityCalculator
```php
use Recombinator\Analysis\CyclomaticComplexityCalculator;

$calculator = new CyclomaticComplexityCalculator();
$complexity = $calculator->calculate($nodes);
```

**Назначение:** Вычисление цикломатической сложности (McCabe).

#### SideEffectClassifier
```php
use Recombinator\Analysis\SideEffectClassifier;

$classifier = new SideEffectClassifier();
$effectType = $classifier->classify($node);
$isPure = $classifier->isPureFunction('array_map');
```

**Назначение:** Классификация побочных эффектов в коде.

**Типы эффектов:**
- `IO` - файловые операции
- `Database` - операции с БД
- `HTTP` - HTTP запросы
- `NonDeterministic` - недетерминированные функции
- `GlobalState` - изменение глобального состояния
- `Pure` - чистые функции

#### EffectDependencyGraph
```php
use Recombinator\Analysis\EffectDependencyGraph;

$graph = new EffectDependencyGraph();
$graph->analyzeStatement($stmt);
$dependencies = $graph->getDependencies($nodeId);
```

**Назначение:** Построение графа зависимостей между эффектами.

#### PureBlockFinder
```php
use Recombinator\Analysis\PureBlockFinder;

$finder = new PureBlockFinder();
$pureBlocks = $finder->findPureBlocks($stmts);
```

**Назначение:** Поиск блоков чистого кода без побочных эффектов.

#### VariableAnalyzer
```php
use Recombinator\Analysis\VariableAnalyzer;

$analyzer = new VariableAnalyzer();
$analysis = $analyzer->analyze($node);
```

**Назначение:** Анализ использования переменных.

#### ComplexityController
```php
use Recombinator\Analysis\ComplexityController;

$controller = new ComplexityController();
$metrics = $controller->analyzeFunction($functionNode);
```

**Назначение:** Координация вычисления различных метрик сложности.

---

## 4. Transformation - Трансформации

Директория `Transformation/` содержит компоненты для трансформации кода.

### Компоненты:

#### SideEffectSeparator
```php
use Recombinator\Transformation\SideEffectSeparator;

$separator = new SideEffectSeparator();
$result = $separator->separate($stmts);
// Возвращает SeparationResult с разделенными эффектами
```

**Назначение:** Разделение кода на блоки с побочными эффектами и чистые вычисления.

**Результат содержит:**
- Группы эффектов
- Чистые вычисления
- Границы эффектов
- Зависимости

#### AbstractionRecovery
```php
use Recombinator\Transformation\AbstractionRecovery;

$recovery = new AbstractionRecovery();
$suggestions = $recovery->findAbstractionOpportunities($code);
```

**Назначение:** Поиск возможностей для извлечения абстракций.

#### FunctionExtractor
```php
use Recombinator\Transformation\FunctionExtractor;

$extractor = new FunctionExtractor();
$newFunction = $extractor->extractFunction($codeBlock, 'functionName');
```

**Назначение:** Извлечение кода в отдельные функции.

#### NestedConditionSimplifier
```php
use Recombinator\Transformation\NestedConditionSimplifier;

$simplifier = new NestedConditionSimplifier();
$simplified = $simplifier->simplify($nestedConditions);
```

**Назначение:** Упрощение вложенных условий.

#### VariableDeclarationOptimizer
```php
use Recombinator\Transformation\VariableDeclarationOptimizer;

$optimizer = new VariableDeclarationOptimizer();
$optimized = $optimizer->optimize($declarations);
```

**Назначение:** Оптимизация объявлений переменных.

### Transformation/Visitor - Visitor классы

Директория `Transformation/Visitor/` содержит visitor классы для обхода и модификации AST.

#### BaseVisitor
```php
use Recombinator\Transformation\Visitor\BaseVisitor;

class MyVisitor extends BaseVisitor
{
    public function enterNode(Node $node)
    {
        // Логика обработки узла
    }
}
```

**Назначение:** Базовый класс для всех visitor'ов с общей функциональностью.

#### Примеры Visitor'ов:

**BinaryAndIssetVisitor** - обработка бинарных операций и isset
```php
use Recombinator\Transformation\Visitor\BinaryAndIssetVisitor;

$visitor = new BinaryAndIssetVisitor();
// Преобразует: isset($var) && $var === 'value'
```

**VarToScalarVisitor** - замена переменных на скаляры
```php
use Recombinator\Transformation\Visitor\VarToScalarVisitor;
use Recombinator\Domain\ScopeStore;

$scopeStore = new ScopeStore();
$visitor = new VarToScalarVisitor($scopeStore);
```

**FunctionScopeVisitor** - обработка scope функций
```php
use Recombinator\Transformation\Visitor\FunctionScopeVisitor;

$visitor = new FunctionScopeVisitor($scopeStore, $cacheDir);
```

**SideEffectMarkerVisitor** - пометка побочных эффектов
```php
use Recombinator\Transformation\Visitor\SideEffectMarkerVisitor;

$visitor = new SideEffectMarkerVisitor($classifier);
```

---

## 5. Interactive - Интерактивный CLI

Директория `Interactive/` содержит компоненты для интерактивной работы с пользователем.

### Компоненты:

#### InteractiveCLI
```php
use Recombinator\Interactive\InteractiveCLI;

$cli = new InteractiveCLI();
$cli->run();
```

**Назначение:** Главный класс интерактивного CLI.

**Функции:**
- Интерактивное редактирование
- Предложения по улучшению
- История изменений
- Откат изменений

#### InteractiveEditAnalyzer
```php
use Recombinator\Interactive\InteractiveEditAnalyzer;

$analyzer = new InteractiveEditAnalyzer();
$suggestions = $analyzer->analyzeFunctionForEdits($function);
```

**Назначение:** Анализ кода и предложение улучшений.

#### EditSession
```php
use Recombinator\Interactive\EditSession;

$session = new EditSession();
$session->startEdit($functionName);
$session->applyChange($change);
$session->commit();
```

**Назначение:** Управление сессией редактирования.

#### ChangeHistory
```php
use Recombinator\Interactive\ChangeHistory;

$history = new ChangeHistory();
$history->record($change);
$history->undo();
```

**Назначение:** Отслеживание и откат изменений.

---

## 6. Domain - Доменные объекты

Директория `Domain/` содержит value objects и доменные сущности.

### Компоненты:

#### SideEffectType (enum)
```php
use Recombinator\Domain\SideEffectType;

$type = SideEffectType::IO;
$isEffect = $type->hasEffect(); // true

// Все типы:
SideEffectType::Pure
SideEffectType::IO
SideEffectType::Database
SideEffectType::HTTP
SideEffectType::NonDeterministic
SideEffectType::GlobalState
```

**Назначение:** Enum типов побочных эффектов.

#### ComplexityMetrics
```php
use Recombinator\Domain\ComplexityMetrics;

$metrics = ComplexityMetrics::fromNodes($nodes);
echo $metrics->cognitiveComplexity; // 15
echo $metrics->cyclomaticComplexity; // 8
echo $metrics->linesOfCode; // 50
echo $metrics->nestingDepth; // 3
```

**Назначение:** Value object для метрик сложности.

**Свойства:**
- `cognitiveComplexity` - когнитивная сложность
- `cyclomaticComplexity` - цикломатическая сложность
- `linesOfCode` - количество строк кода
- `nestingDepth` - глубина вложенности

#### SeparationResult
```php
use Recombinator\Domain\SeparationResult;

$result = new SeparationResult($effectGroups, $pureBlocks);
$groups = $result->getEffectGroups();
$pure = $result->getPureComputations();
```

**Назначение:** Результат разделения побочных эффектов.

#### ScopeStore (Symbol Table)
```php
use Recombinator\Domain\ScopeStore;

$scopeStore = new ScopeStore();
$scopeStore->setCurrentScope('functionName');
$scopeStore->setVarToScope('x', 10);
$value = $scopeStore->getVarFromScope('x');
```

**Назначение:** Хранение символов (переменных, констант, функций) по scope.

**Методы:**
- `setVarToScope()` / `getVarFromScope()` - переменные
- `setConstToScope()` / `getConstFromScope()` - константы scope
- `setConstToGlobal()` / `getConstFromGlobal()` - глобальные константы
- `setFunctionToGlobal()` / `getFunctionFromGlobal()` - функции
- `setClassToGlobal()` / `getClassFromGlobal()` - классы

#### FunctionCandidate
```php
use Recombinator\Domain\FunctionCandidate;

$candidate = new FunctionCandidate($stmts, $complexity, $effectTypes);
```

**Назначение:** Кандидат для извлечения в функцию.

---

## 7. Support - Вспомогательные классы

Директория `Support/` содержит вспомогательные утилиты.

### Компоненты:

#### ColorDiffer
```php
use Recombinator\Support\ColorDiffer;

$differ = new ColorDiffer();
$diff = $differ->diff($oldCode, $newCode);
echo $diff; // Цветной diff
```

**Назначение:** Генерация цветного diff для отображения изменений.

#### Sandbox
```php
use Recombinator\Support\Sandbox;

$sandbox = new Sandbox();
$result = $sandbox->execute($code);
```

**Назначение:** Безопасное выполнение PHP кода в изолированной среде.

**Особенности:**
- Whitelist разрешенных функций
- Ограничение опасных операций
- Проверка на безопасность

---

## Примеры использования

### Пример 1: Анализ сложности функции

```php
use Recombinator\Analysis\CognitiveComplexityCalculator;
use Recombinator\Analysis\CyclomaticComplexityCalculator;
use Recombinator\Domain\ComplexityMetrics;
use PhpParser\ParserFactory;

// Парсим код
$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$ast = $parser->parse($code);

// Вычисляем метрики
$metrics = ComplexityMetrics::fromNodes($ast);

echo "Cognitive Complexity: {$metrics->cognitiveComplexity}\n";
echo "Cyclomatic Complexity: {$metrics->cyclomaticComplexity}\n";
echo "Lines of Code: {$metrics->linesOfCode}\n";
echo "Nesting Depth: {$metrics->nestingDepth}\n";

// Получаем уровень сложности
$cogCalc = new CognitiveComplexityCalculator();
$level = $cogCalc->getComplexityLevel($metrics->cognitiveComplexity);
echo "Complexity Level: $level\n";
```

### Пример 2: Разделение побочных эффектов

```php
use Recombinator\Transformation\SideEffectSeparator;
use Recombinator\Analysis\SideEffectClassifier;
use PhpParser\ParserFactory;

// Парсим код
$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$ast = $parser->parse($code);

// Разделяем эффекты
$classifier = new SideEffectClassifier();
$separator = new SideEffectSeparator($classifier);
$result = $separator->separate($ast);

// Анализируем результат
foreach ($result->getEffectGroups() as $group) {
    echo "Effect Type: {$group->type->name}\n";
    echo "Statements: " . count($group->statements) . "\n";
}

foreach ($result->getPureComputations() as $pure) {
    echo "Pure Block found\n";
}
```

### Пример 3: Интерактивное редактирование

```php
use Recombinator\Interactive\InteractiveCLI;

$cli = new InteractiveCLI();
$cli->run();

// CLI предложит:
// 1. Выбрать функцию для анализа
// 2. Показать метрики сложности
// 3. Предложить улучшения
// 4. Применить изменения с подтверждением
// 5. Откатить изменения при необходимости
```

### Пример 4: Использование ScopeStore

```php
use Recombinator\Domain\ScopeStore;

$store = new ScopeStore();

// Работа с глобальными константами
$store->setConstToGlobal('APP_VERSION', '1.0.0');
$version = $store->getConstFromGlobal('APP_VERSION');

// Работа с функциями
$store->setCurrentScope('myFunction');
$store->setVarToScope('x', 10);
$store->setVarToScope('y', 20);

$x = $store->getVarFromScope('x'); // 10
$y = $store->getVarFromScope('y'); // 20

// Переключение scope
$store->setCurrentScope('anotherFunction');
$z = $store->getVarFromScope('x'); // null (другой scope)
```

---

## Принципы разработки

### SOLID принципы

**Single Responsibility Principle (SRP)**
- Каждый класс имеет одну ответственность
- Парсинг, анализ, трансформация разделены

**Open/Closed Principle (OCP)**
- Компоненты открыты для расширения через интерфейсы
- Закрыты для модификации

**Liskov Substitution Principle (LSP)**
- Все реализации интерфейсов взаимозаменяемы

**Interface Segregation Principle (ISP)**
- Интерфейсы сфокусированы и минимальны
- Клиенты не зависят от неиспользуемых методов

**Dependency Inversion Principle (DIP)**
- Зависимости от абстракций (интерфейсов)
- Не от конкретных реализаций

### Паттерны проектирования

**Visitor Pattern**
- Используется для обхода и модификации AST
- Все visitor'ы наследуют BaseVisitor

**Strategy Pattern**
- Калькуляторы сложности реализуют ComplexityCalculatorInterface
- Классификаторы реализуют EffectClassifierInterface

**Value Object Pattern**
- ComplexityMetrics, SeparationResult
- Immutable объекты со значением

---

## Расширение функциональности

### Добавление нового типа побочных эффектов

1. Добавить в `SideEffectType` enum:
```php
enum SideEffectType: string
{
    // ... existing
    case Network = 'network';
}
```

2. Обновить `SideEffectClassifier`:
```php
private const NETWORK_FUNCTIONS = [
    'curl_exec',
    'fsockopen',
    // ...
];
```

### Создание нового Visitor'а

```php
namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

class MyCustomVisitor extends BaseVisitor
{
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\If_) {
            // Обработка if узла
        }
        return null;
    }
}
```

### Добавление нового калькулятора сложности

```php
namespace Recombinator\Analysis;

use Recombinator\Contract\ComplexityCalculatorInterface;

class HalsteadComplexityCalculator implements ComplexityCalculatorInterface
{
    public function calculate(array $nodes): int
    {
        // Реализация вычисления Halstead метрик
    }

    public function getComplexityLevel(int $value): string
    {
        // Определение уровня сложности
    }
}
```

---

## Тестирование

Все компоненты спроектированы для удобного тестирования:

```php
use Recombinator\Analysis\SideEffectClassifier;
use Recombinator\Contract\EffectClassifierInterface;

class MyTest extends TestCase
{
    public function testClassifier()
    {
        // Mock интерфейса
        $classifier = $this->createMock(EffectClassifierInterface::class);

        $classifier->expects($this->once())
            ->method('classify')
            ->willReturn(SideEffectType::Pure);

        // Использование в тестах
        $this->assertTrue($classifier->classify($node)->isPure());
    }
}
```

---

## Дальнейшее развитие

Планируемые улучшения:

1. **Dependency Injection Container** - для управления зависимостями
2. **Factory Pattern** - для создания visitor'ов
3. **Value Objects** - NodeId, ComplexityScore
4. **Configuration System** - управление настройками
5. **PSR-3 Logger** - структурированное логирование
6. **Abstract Complexity Calculator** - базовый класс для калькуляторов

---

## Заключение

Новая архитектура Recombinator обеспечивает:

- ✅ Четкое разделение ответственности
- ✅ Модульность и расширяемость
- ✅ Соответствие SOLID принципам
- ✅ Удобство тестирования
- ✅ Ясность и поддерживаемость кода
