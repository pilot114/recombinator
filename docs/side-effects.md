# Классификация побочных эффектов

## Содержание

1. [Введение](#введение)
2. [Типы побочных эффектов](#типы-побочных-эффектов)
3. [Классификатор побочных эффектов](#классификатор-побочных-эффектов)
4. [Примеры использования](#примеры-использования)
5. [Применение в оптимизации](#применение-в-оптимизации)

---

## Введение

**Побочный эффект** (Side Effect) — это любое изменение состояния системы или взаимодействие с внешним миром, которое происходит при выполнении функции или выражения, помимо возвращения значения.

### Зачем нужна классификация?

Классификация побочных эффектов критически важна для:

1. **Развёртки (Unfolding)** - определение "чистых" блоков кода, которые можно безопасно:
   - Вычислить в compile-time
   - Инлайнить
   - Реорганизовать
   - Кешировать результаты

2. **Свёртки (Folding)** - группировка кода по типу побочного эффекта:
   - Разделение I/O от бизнес-логики
   - Изоляция работы с БД
   - Вынос недетерминированных операций

3. **Оптимизации** - улучшение производительности и читаемости:
   - Устранение избыточных вычислений
   - Минимизация точек взаимодействия с внешним миром
   - Оптимальный порядок выполнения операций

---

## Типы побочных эффектов

### 1. PURE - Чистый код

**Определение:** Код без побочных эффектов. Детерминированный результат, не зависит от внешнего состояния и не изменяет его.

**Характеристики:**
- ✅ Может быть вычислен в compile-time
- ✅ Можно кешировать результаты
- ✅ Безопасно переупорядочивать
- ✅ Можно инлайнить без ограничений

**Примеры:**
```php
// Математические операции
$result = 1 + 2 * 3;
$sqrt = sqrt(16);
$max = max(10, 20, 30);

// Строковые операции
$upper = strtoupper('hello');
$len = strlen('test');
$sub = substr('hello world', 0, 5);

// Операции с массивами
$merged = array_merge([1, 2], [3, 4]);
$keys = array_keys(['a' => 1, 'b' => 2]);
$count = count([1, 2, 3, 4, 5]);

// Чистые пользовательские функции
function add(int $a, int $b): int {
    return $a + $b;
}
```

**Оптимизация:**
```php
// До
$x = 2 + 2;
$y = strtoupper('hello');
echo $x . ' ' . $y;

// После (compile-time evaluation)
echo "4 HELLO";
```

---

### 2. IO - Операции ввода/вывода

**Определение:** Чтение или запись данных во внешние системы (файлы, консоль, потоки).

**Характеристики:**
- ❌ Нельзя вычислить в compile-time
- ❌ Нельзя кешировать
- ⚠️  Может завершиться с ошибкой
- ⚠️  Может быть медленным
- ❌ Нельзя безопасно переупорядочивать

**Примеры:**
```php
// Вывод в консоль
echo "Hello, World!";
print "Test";
printf("Number: %d\n", 42);
var_dump($data);

// Чтение файлов
$content = file_get_contents('/path/to/file.txt');
$lines = file('/path/to/file.txt');
$handle = fopen('/path/to/file.txt', 'r');
$data = fread($handle, 1024);

// Запись файлов
file_put_contents('/path/to/file.txt', 'data');
fwrite($handle, 'data');

// Операции с файловой системой
unlink('/path/to/file.txt');
mkdir('/path/to/dir');
copy('/src.txt', '/dst.txt');
```

**Группировка при свёртке:**
```php
// До оптимизации
echo "Starting\n";
$result = calculate(); // pure
echo "Result: " . $result . "\n";
$final = $result * 2; // pure
echo "Final: " . $final . "\n";

// После свёртки - группируем I/O
$result = calculate();
$final = $result * 2;
echo "Starting\nResult: {$result}\nFinal: {$final}\n";
```

---

### 3. EXTERNAL_STATE - Внешнее состояние

**Определение:** Чтение данных из внешнего окружения (суперглобальные переменные, окружение).

**Характеристики:**
- ❌ Нельзя вычислить в compile-time
- ⚠️  Можно кешировать с осторожностью (в рамках одного запроса)
- ❌ Недетерминированный результат
- ✅ Можно переупорядочивать с ограничениями

**Примеры:**
```php
// Суперглобалы
$username = $_GET['username'];
$password = $_POST['password'];
$session_id = $_SESSION['id'];
$cookie = $_COOKIE['token'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
$uploaded_file = $_FILES['avatar'];

// Окружение
$path = getenv('PATH');
$db_host = $_ENV['DB_HOST'];
```

**Оптимизация:**
```php
// До
$username = 'default';
if (isset($_GET['username'])) {
    $username = $_GET['username'];
}

// После
$username = $_GET['username'] ?? 'default';
```

---

### 4. GLOBAL_STATE - Глобальное состояние

**Определение:** Изменение глобальных переменных, статических свойств, конфигурации PHP.

**Характеристики:**
- ❌ Нельзя вычислить в compile-time
- ❌ Нельзя кешировать
- ❌ Влияет на выполнение остального кода
- ❌ Нельзя безопасно переупорядочивать

**Примеры:**
```php
// Глобальные переменные
global $config;
$config['debug'] = true;

// Статические свойства
class App {
    static $instance = null;
}
App::$instance = new App();

// Конфигурация PHP
ini_set('display_errors', '1');
set_time_limit(60);
date_default_timezone_set('UTC');

// Обработчики
set_error_handler('myErrorHandler');
register_shutdown_function('cleanup');

// Headers и сессии
header('Content-Type: application/json');
session_start();
setcookie('token', '123', time() + 3600);
```

**Рефакторинг:**
```php
// До (глобальное состояние)
class Config {
    static $debug = false;
}
Config::$debug = true;

// После (dependency injection)
class Config {
    public function __construct(
        public bool $debug = false
    ) {}
}
$config = new Config(debug: true);
```

---

### 5. DATABASE - База данных

**Определение:** Запросы к базе данных (SELECT, INSERT, UPDATE, DELETE).

**Характеристики:**
- ❌ Нельзя вычислить в compile-time
- ❌ Нельзя кешировать автоматически
- ⚠️  Может быть очень медленным
- ⚠️  Может завершиться с ошибкой
- ❌ Требует осторожности при переупорядочивании

**Примеры:**
```php
// MySQLi
$result = mysqli_query($conn, "SELECT * FROM users");

// PDO
$stmt = $pdo->query("SELECT * FROM users");
$stmt = $pdo->prepare("INSERT INTO users (name) VALUES (?)");
$stmt->execute(['John']);

// ActiveRecord стиль
$users = User::all();
$user = User::find(1);
$user->update(['name' => 'Jane']);
```

**Группировка:**
```php
// До - множественные запросы
foreach ($ids as $id) {
    $user = User::find($id); // N+1 problem
    echo $user->name;
}

// После - группируем запросы
$users = User::findMany($ids); // 1 запрос
foreach ($users as $user) {
    echo $user->name;
}
```

---

### 6. HTTP - Сетевые запросы

**Определение:** HTTP запросы к внешним API, сетевые соединения.

**Характеристики:**
- ❌ Нельзя вычислить в compile-time
- ❌ Нельзя кешировать автоматически
- ⚠️  Может быть ОЧЕНЬ медленным
- ⚠️  Может завершиться с таймаутом
- ❌ Нельзя безопасно переупорядочивать

**Примеры:**
```php
// cURL
$ch = curl_init('https://api.example.com/data');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);

// file_get_contents с HTTP
$data = file_get_contents('https://api.example.com/data');

// Сокеты
$socket = fsockopen('example.com', 80);
fwrite($socket, "GET / HTTP/1.1\r\n\r\n");

// Email
mail('user@example.com', 'Subject', 'Message');
```

**Оптимизация:**
```php
// До - последовательные запросы
$user = fetchUser($id);        // 1 сек
$posts = fetchPosts($id);      // 1 сек
$comments = fetchComments($id); // 1 сек
// Итого: 3 сек

// После - параллельные запросы
[$user, $posts, $comments] = parallel([
    fn() => fetchUser($id),
    fn() => fetchPosts($id),
    fn() => fetchComments($id),
]);
// Итого: ~1 сек
```

---

### 7. NON_DETERMINISTIC - Недетерминированные операции

**Определение:** Операции, результат которых зависит от времени или случайности.

**Характеристики:**
- ❌ Нельзя вычислить в compile-time
- ❌ Нельзя кешировать
- ❌ Разный результат при каждом вызове
- ⚠️  Можно переупорядочивать с осторожностью

**Примеры:**
```php
// Случайные числа
$random = rand(1, 100);
$random = mt_rand();
$random = random_int(1, 100);
$bytes = random_bytes(16);

// Время
$now = time();
$micro = microtime(true);
$date = date('Y-m-d H:i:s');
$timestamp = strtotime('tomorrow');

// Уникальные ID
$id = uniqid();
$uuid = bin2hex(random_bytes(16));

// Процессы
$pid = getmypid();
```

**Изоляция:**
```php
// До - недетерминированность в логике
function createUser($name) {
    return [
        'id' => uniqid(),        // недетерминированно
        'name' => $name,
        'created_at' => time(),  // недетерминированно
    ];
}

// После - вынос недетерминированности
function createUser($name, $id, $timestamp) {
    return [
        'id' => $id,
        'name' => $name,
        'created_at' => $timestamp,
    ];
}
// Вызов
$user = createUser('John', uniqid(), time());
```

---

### 8. MIXED - Смешанные эффекты

**Определение:** Код содержит несколько типов эффектов или тип невозможно точно определить.

**Примеры:**
```php
// Комбинация эффектов
function processRequest() {
    $data = $_POST['data'];              // EXTERNAL_STATE
    $id = uniqid();                      // NON_DETERMINISTIC
    $saved = saveToDb($id, $data);       // DATABASE
    echo "Saved with ID: $id\n";         // IO
    return $saved;
}

// Динамический вызов
$func = $_GET['action'];
$result = $func();  // Не знаем, что будет вызвано

// Include с кодом
include 'dynamic_code.php';  // Может содержать что угодно
```

---

## Классификатор побочных эффектов

### Класс `SideEffectClassifier`

Анализирует узлы AST и определяет тип побочного эффекта.

#### Основной метод

```php
public function classify(Node $node): SideEffectType
```

Принимает узел AST и возвращает тип побочного эффекта.

#### Методы для блоков

```php
public function classifyBlock(array $nodes): SideEffectType
```

Анализирует массив узлов и возвращает комбинированный тип эффекта.

### Правила классификации

1. **Echo, Print** → IO
2. **Вызовы функций** → анализ по whitelist/blacklist
3. **Суперглобалы** ($_GET, $_POST и т.д.) → EXTERNAL_STATE
4. **Global, Static** → GLOBAL_STATE
5. **Составные узлы** → комбинация эффектов детей
6. **По умолчанию** → PURE

### Комбинирование эффектов

При анализе составных узлов (if, while, блоки) эффекты комбинируются:

- PURE + PURE = PURE
- PURE + X = X
- X + X = X (одинаковые)
- X + Y = MIXED (разные)
- MIXED + X = MIXED

---

## Примеры использования

### Пример 1: Определение чистой функции

```php
use PhpParser\ParserFactory;
use Recombinator\SideEffectClassifier;

$code = '<?php function add($a, $b) { return $a + $b; }';

$parser = (new ParserFactory())->createForHostVersion();
$ast = $parser->parse($code);

$classifier = new SideEffectClassifier();
$effect = $classifier->classify($ast[0]->stmts[0]); // return statement

echo $effect->value; // "pure"
echo $effect->isPure(); // true
echo $effect->isCompileTimeEvaluable(); // true
```

### Пример 2: Анализ блока кода

```php
$code = '<?php
$username = $_GET["username"];
echo "Hello, " . $username;
$data = file_get_contents("/path/to/file.txt");
';

$ast = $parser->parse($code);
$effect = $classifier->classifyBlock($ast);

echo $effect->value; // "mixed"
// EXTERNAL_STATE ($_GET) + IO (echo) + IO (file_get_contents) = MIXED
```

### Пример 3: Определение оптимизируемого кода

```php
$code = '<?php
$a = 1 + 2;
$b = strtoupper("hello");
$c = $a * 3;
';

$ast = $parser->parse($code);
$effect = $classifier->classifyBlock($ast);

if ($effect->isPure()) {
    echo "Код можно вычислить в compile-time!";
    // Результат: true
}
```

---

## Применение в оптимизации

### Фаза 3: Анализ побочных эффектов

#### 3.1 Классификация (текущий этап) ✅

- [x] Определены типы побочных эффектов
- [x] Создан SideEffectType enum
- [x] Реализован SideEffectClassifier
- [x] Написана документация

#### 3.2 Маркировка кода (следующий этап)

Создание Visitor'а для пометки узлов типом эффекта:

```php
class SideEffectMarkerVisitor extends BaseVisitor
{
    private SideEffectClassifier $classifier;

    public function enterNode(Node $node)
    {
        $effect = $this->classifier->classify($node);
        $node->setAttribute('side_effect', $effect);
    }
}
```

#### 3.3 Разделение побочных эффектов

Группировка узлов по типу эффекта для последующей свёртки:

```php
// Вход: смешанный код
$username = $_GET['username'];  // EXTERNAL_STATE
$hash = md5($username);         // PURE
echo $hash;                     // IO

// Выход: разделенный по эффектам
// Блок 1: EXTERNAL_STATE
$username = $_GET['username'];

// Блок 2: PURE
$hash = md5($username);

// Блок 3: IO
echo $hash;
```

### Применение в развёртке

Классификация помогает определить, какой код можно развернуть:

```php
// PURE код - можно развернуть
function calculate($x) {
    return $x * 2 + 10;
}
$result = calculate(5); // → $result = 20;

// NON_DETERMINISTIC - нельзя развернуть
$random = rand(1, 100); // Каждый раз разное значение

// IO - нельзя развернуть
$data = file_get_contents('file.txt'); // Может измениться
```

### Применение в свёртке

Классификация помогает группировать код для создания новых абстракций:

```php
// До оптимизации (смешанные эффекты)
$name = $_GET['name'];
$email = $_GET['email'];
$age = (int)$_GET['age'];
$canVote = $age >= 18;
echo "Name: $name\n";
echo "Email: $email\n";
echo "Can vote: " . ($canVote ? 'yes' : 'no') . "\n";

// После группировки по эффектам
// Группа 1: EXTERNAL_STATE
$input = [
    'name' => $_GET['name'],
    'email' => $_GET['email'],
    'age' => (int)$_GET['age'],
];

// Группа 2: PURE (бизнес-логика)
$canVote = $input['age'] >= 18;

// Группа 3: IO (вывод)
echo "Name: {$input['name']}\n";
echo "Email: {$input['email']}\n";
echo "Can vote: " . ($canVote ? 'yes' : 'no') . "\n";
```

---

## Приоритеты эффектов

Каждый тип эффекта имеет приоритет (0-7), который определяет порядок оптимизации:

| Приоритет | Тип | Описание |
|-----------|-----|----------|
| 0 | PURE | Обрабатывается первым, можно максимально оптимизировать |
| 1 | NON_DETERMINISTIC | Нельзя кешировать, но можно изолировать |
| 2 | EXTERNAL_STATE | Зависит от окружения |
| 3 | IO | I/O операции |
| 4 | GLOBAL_STATE | Изменяет глобальное состояние |
| 5 | DATABASE | Работа с БД |
| 6 | HTTP | Самые медленные операции |
| 7 | MIXED | Обрабатывается последним |

### Стратегия оптимизации по приоритету

1. **PURE** - максимальная оптимизация (инлайнинг, compile-time eval, кеширование)
2. **NON_DETERMINISTIC** - изоляция в отдельные переменные
3. **EXTERNAL_STATE** - чтение один раз, использование многократно
4. **IO/DATABASE/HTTP** - группировка и минимизация вызовов
5. **GLOBAL_STATE** - рефакторинг в явную передачу параметров
6. **MIXED** - разбиение на более специфичные блоки

---

## Следующие шаги

1. ✅ **Классификация побочных эффектов** (текущий документ)
2. ⏳ **Маркировка кода** - Visitor для пометки узлов типом эффекта
3. ⏳ **Граф зависимостей** - построение графа зависимостей по эффектам
4. ⏳ **Разделение эффектов** - группировка узлов по типу эффекта
5. ⏳ **Свёртка** - создание новых абстракций на основе типов эффектов

---

## Заключение

Классификация побочных эффектов — это фундаментальная основа для:
- Безопасной оптимизации кода
- Разделения бизнес-логики от побочных эффектов
- Улучшения читаемости и тестируемости
- Повышения производительности

**SideEffectType** и **SideEffectClassifier** предоставляют необходимые инструменты для анализа и категоризации PHP кода, что позволяет перейти к следующим этапам оптимизации.
