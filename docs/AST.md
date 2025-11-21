 # PhpParser

    Builder (обертка от Node, чтобы её сбилдить)
        Property (свойство в Class_ / Trait_)
        Param (параметры в FunctionLike)
        TraitUse
        TraitUseAdaptation
        Use_
        Declaration (набор Statements)
            Namespace_
            Class_
            Trait_
            Interface_
            FunctionLike
                Function_
                Method

    Node (любой элемент AST)
        Arg
        Param
        AttributeGroup
        Attribute
        Const_
        Name
            FullyQualified, Relative
        FunctionLike
        UnionType
        NullableType
        MatchArm
        Identifier
            VarLikeIdentifier
        Stmt
            TraitUseAdaptation
                Alias, Precedence
            Break_, Continue_
            Case_
            Catch_
            Class_
            ClassConst
            ClassLike
                Class_
                Interface_
                Trait_
            ClassMethod
            Const_
            Declare_, DeclareDeclare
            Property, PropertyProperty
            Use_, UseUse
            Do_, While_, For_, Foreach_
            If_, Else_, Elseif_
            Echo_
            Expression
            Finally_
            Function_
            Global_
            Goto_
            GroupLike
            HalfCompiler
            InlineHTML
            Label
            Namespace_
            Nop
            Return_
            Static_
            StaticVar
            Switch_
            Throw, TryCatch
            Unset_
        Expr
            AssignOp
                Concat
                Coalesce
                BitwiseAnd, BitwiseOr, BitwiseXor
                ShiftLeft, ShiftRight
                Plus, Minus, Div, Mul, Mod, Pow
            BinaryOp
                Concat
                Coalesce
                BitwiseAnd, BitwiseOr, BitwiseXor
                LogicalAnd, LogicalOr, LogicalXor 
                BooleanAnd, BooleanOr
                ShiftLeft, ShiftRight
                Plus, Minus, Div, Mul, Mod, Pow
                Equal, NotEqual, Greater, GreaterOrEqual, Smaller, SmallerOrEqual, Identical, NotIdentical
                Spaceship
            Cast
                Array_, Bool_, Double, Int_, Object_, String_, Unset_
            Scalar
                String_
                LNumber
                DNumber
                EncapsedStringPart
                Encapsed
                MagicConst
                    Class_, Dir, File, Function_, Line, Method, Namespace_, Trait_
            Array_, ArrayDimFetch, ArrayItem
            ArrowFunction
            Assign, AssignOp, AssignRef
            BinaryOp, BinaryNot, BooleanNot
            ClassConstFetch, ConstFetch, PropertyFetch, StaticPropertyFetch, NullsafePropertyFetch
            FuncCall, MethodCall, StaticCall, NullsafeMethodCall
            Clone
            Closure, ClosureUse
            Empty_
            ErrorSuppress
            Eval_
            Exit_
            Include_
            Instanceof_
            Isset_
            Match_
            New_
            PostDec, PostInc, PreDec, PreInc
            Print_
            ShellExec
            Ternary
            Throw_
            UnaryMinus, UnaryPlus
            Variable
            Yield, YieldFrom

# EBNF

К недостаткам EBNF, как, впрочем, и BNF, можно отнести тот факт, что они описывают грамматическую структуру формального
языка без учёта контекстных зависимостей, а значит, при наличии таких зависимостей РБНФ-описание оказывается неполным,
и некоторые правила синтаксиса описываемого языка приходится излагать в обычной текстовой форме. Это приводит к тому,
что текст, точно соответствующий EBNF-грамматике, может, тем не менее, оказаться синтаксически некорректным. Например,
в РБНФ-грамматике невозможно естественным образом отобразить тот факт, что некоторая операция требует операндов одного
и того же типа. Подобные проверки приходится проводить уже написанным вручную кодом грамматического анализатора.
С другой стороны, системы описания грамматики, включающие определение контекстных зависимостей, например,
грамматика ван Вейнгаардена, оказываются существенно сложнее, и использование их для автоматической генерации
парсеров оказывается затруднительным.


# ANTLR

https://ru.wikipedia.org/wiki/ANTLR
https://github.com/antlr/grammars-v4/blob/master/php/PhpLexer.g4

# ?

AST деревья (тут пример только для основного файла, без объявлений функций / классов):

    echo 1 / (2 + 3);
    =>
    Stmt_Echo:
        Expr_BinaryOp_Div:
            left: Scalar_LNumber (1)
            right:
                Expr_BinaryOp_Plus:
                    left: Scalar_LNumber (2)
                    right: Scalar_LNumber (3)
    
    
    echo test(1, 2);
    =>
    Stmt_Echo:
        Expr_FuncCall:
            name: Name (test)
            args:
                Scalar_LNumber (1)
                Scalar_LNumber (2)
    
    
    echo is_array([]);
    =>
    Stmt_Echo:
        Expr_FuncCall:
            name: Name (is_array)
            args:
                Expr_Array([])
    
    
    $username = 'default_username';
    =>
    Expr_Assign
        var: Expr_Variable (username)
        expr: Scalar_String (default_username)
    
    
    if (isset($_GET['username'])) {
        $username = $_GET['username'];
    }
    =>
    Stmt_If:
        cond:
            Expr_Isset:
                vars:
                    Expr_ArrayDimFetch:
                        var: Expr_Variable (_GET)
                        dim: Scalar_String (username)
        stmts:
            Expr_Assign:
                var: Expr_Variable (username)
                expr:
                    Expr_ArrayDimFetch:
                        var: Expr_Variable (_GET)
                        dim: Scalar_String (_username)
    
    
    $auth = new Auth();
    =>
    Expr_Assign:
            var: Expr_Variable (auth)
            expr:
                Expr_New:
                    class: Name (Auth)
    
    
    $result = $auth->login($username, $pass);
    =>
    Expr_Assign:
            var: Expr_Variable (result)
            expr:
                Expr_MethodCall:
                    var: Expr_Variable (auth)
                    name: Identifier (login)
                    args:
                        Expr_Variable (username)
                        Expr_Variable (pass)
    
    
    echo $result . "\n";
    =>
    Stmt_Echo:
        Expr_BinaryOp_Concat:
            left: Expr_Variable (result)
            right: Scalar_String ("\n")

Для большинства выражений можно подобрать правило для эквивалентной замены. При этом логично идти
от более низкоуровневых выражений (например, математических) к более высокоуровневым.
Составим список выражений из примера выше:

|    Выражение            | Описание                              | Примечание
-------------------------|----------------------------------------|-------------------------------------------------
    Name                 | Имена классов / трейтов / интерфейсов  | Могут редактироваться при переносе в другой скоп
    Identifier           | Имена методов и свойств                | -
    Scalar_LNumber       | Цифры                                  | -
    Scalar_String        | Строки                                 | -
    Stmt_If              | Условие                                | Много кейсов для замены
    Stmt_Echo            | Стандартный вывод                      | Подряд идущие можно склеивать
    Expr_FuncCall        | Вызов функции                          | Подмена телом функции
    Expr_BinaryOp_Div    | Деление                                | Вычисление, если нет переменных
    Expr_BinaryOp_Plus   | Сложение                               | Вычисление, если нет переменных
    Expr_BinaryOp_Concat | Конкатенация                           | Вычисление, если нет переменных
    Expr_Array           | Объявление массива                     | Каждое значение потенциально может быть вычисляемым
    Expr_ArrayDimFetch   | Чтение из массива                      | -
    Expr_Assign          | Присвоение                             | Может быть удалено с подменой переменной
    Expr_Variable        | Использование переменной               | Чтение может быть заменено на выражение со скалярами
    Expr_Isset           | Проверка на существование              | -
    Expr_New             | Вызов конструктора                     | Подмена телом конструктора
    Expr_MethodCall      | Вызов метода                           | Подмена телом метода

Итого видим 2 варианта замены: ВЫЧИСЛЕНИЕ и ЗАМЕЩЕНИЕ.
Признаком отсутствия возможных замен является наличие только вызовов "побочных эффектов" и операторов управления.

---

# Правила трансформации узлов

## Типы трансформаций

### 1. ВЫЧИСЛЕНИЕ (Evaluation)
Замена выражения на конкретное значение путём его вычисления в compile-time.

**Условия:**
- Все операнды являются скалярами или константами
- Операция детерминирована (не содержит побочных эффектов)
- Результат может быть вычислен на этапе компиляции

### 2. ЗАМЕЩЕНИЕ (Substitution)
Замена абстракции (функции, метода, переменной) её содержимым или значением.

**Условия:**
- Абстракция не содержит побочных эффектов
- Контекст использования совместим с содержимым
- Замена не нарушает семантику программы

### 3. УДАЛЕНИЕ (Removal)
Полное удаление узла из дерева.

**Условия:**
- Узел не влияет на результат выполнения программы
- Нет зависимых узлов
- Удаление не нарушает синтаксис

---

## Матрица трансформаций для узлов

| Тип узла | Тип трансформации | Реализован | Примечание |
|----------|-------------------|------------|------------|
| **Scalar (LNumber, DNumber, String_)** | - | N/A | Конечный узел, не трансформируется |
| **Expr_BinaryOp_Plus** | ВЫЧИСЛЕНИЕ | ✅ | `1 + 2` → `3` (BinaryAndIssetVisitor) |
| **Expr_BinaryOp_Minus** | ВЫЧИСЛЕНИЕ | ✅ | `5 - 2` → `3` (BinaryAndIssetVisitor) |
| **Expr_BinaryOp_Mul** | ВЫЧИСЛЕНИЕ | ✅ | `2 * 3` → `6` (BinaryAndIssetVisitor) |
| **Expr_BinaryOp_Div** | ВЫЧИСЛЕНИЕ | ✅ | `6 / 2` → `3` (BinaryAndIssetVisitor) |
| **Expr_BinaryOp_Concat** | ВЫЧИСЛЕНИЕ | ✅ | `'a' . 'b'` → `'ab'` (BinaryAndIssetVisitor) |
| **Expr_BinaryOp_Equal** | ВЫЧИСЛЕНИЕ | ⏳ | `1 == 1` → `true` |
| **Expr_BinaryOp_Identical** | ВЫЧИСЛЕНИЕ | ⏳ | `1 === 1` → `true` |
| **Expr_BinaryOp_Greater** | ВЫЧИСЛЕНИЕ | ⏳ | `2 > 1` → `true` |
| **Expr_BinaryOp_Smaller** | ВЫЧИСЛЕНИЕ | ⏳ | `1 < 2` → `true` |
| **Expr_BinaryOp_BooleanAnd** | ВЫЧИСЛЕНИЕ | ⏳ | `true && false` → `false` |
| **Expr_BinaryOp_BooleanOr** | ВЫЧИСЛЕНИЕ | ⏳ | `true \|\| false` → `true` |
| **Expr_Variable** | ЗАМЕЩЕНИЕ | ✅ | `$x` → `5` если `$x = 5` (VarToScalarVisitor) |
| **Expr_Assign** | УДАЛЕНИЕ | ✅ | При замене переменной скаляром (VarToScalarVisitor) |
| **Expr_FuncCall** | ЗАМЕЩЕНИЕ | ✅ | `test(1,2)` → тело функции (CallFunctionVisitor) |
| **Expr_MethodCall** | ЗАМЕЩЕНИЕ | ⏳ | `$obj->method()` → тело метода (ConstructorAndMethodsVisitor) |
| **Expr_StaticCall** | ЗАМЕЩЕНИЕ | ⏳ | `Class::method()` → тело статического метода |
| **Expr_New** | ЗАМЕЩЕНИЕ | ⏳ | `new Class()` → инстанцирование свойств (ConstructorAndMethodsVisitor) |
| **Expr_ClassConstFetch** | ЗАМЕЩЕНИЕ | ✅ | `Class::CONST` → значение константы (ConstClassVisitor) |
| **Expr_ConstFetch** | ЗАМЕЩЕНИЕ | ⏳ | `true`, `false`, `null` → скалярные значения |
| **Expr_Array** | ВЫЧИСЛЕНИЕ | ⏳ | `[1, 2+3]` → `[1, 5]` если все элементы вычисляемы |
| **Expr_ArrayDimFetch** | ЗАМЕЩЕНИЕ | ⏳ | `$arr['key']` → значение, если массив известен |
| **Expr_Ternary** | ЗАМЕЩЕНИЕ | ✅ | `if-else` → тернарный оператор (TernarReturnVisitor) |
| **Expr_Isset** | ВЫЧИСЛЕНИЕ/ЗАМЕЩЕНИЕ | ✅ | `if(isset($x)) $y=$x` → `$y=$x??$y` (BinaryAndIssetVisitor) |
| **Expr_Closure** | ЗАМЕЩЕНИЕ | ⏳ | Замыкание → его тело при вызове |
| **Expr_ArrowFunction** | ЗАМЕЩЕНИЕ | ⏳ | Стрелочная функция → её выражение при вызове |
| **Stmt_If** | ЗАМЕЩЕНИЕ | ✅ | Преобразование в тернарный (TernarReturnVisitor) |
| **Stmt_Echo** | ОБЪЕДИНЕНИЕ | ✅ | Слияние последовательных echo (ConcatAssertVisitor) |
| **Stmt_InlineHTML** | УДАЛЕНИЕ | ✅ | Удаление InlineHTML (RemoveVisitor) |
| **Stmt_Function** | УДАЛЕНИЕ | ✅ | После инлайнинга всех вызовов (ScopeVisitor) |
| **Stmt_Class** | УДАЛЕНИЕ | ⏳ | После инлайнинга всех использований |
| **Stmt_ClassConst** | УДАЛЕНИЕ | ✅ | После замены всех использований (ConstClassVisitor) |
| **Stmt_ClassMethod** | УДАЛЕНИЕ | ⏳ | После инлайнинга всех вызовов |
| **Stmt_Return** | ЗАМЕЩЕНИЕ | ✅ | В контексте инлайнинга функций |

---

## Приоритет трансформаций

Трансформации должны выполняться в определённом порядке для достижения максимальной оптимизации:

### Фаза 1: Сбор информации (Scope Collection)
1. **ScopeVisitor** - сбор всех объявлений функций и классов
2. **FunctionScopeVisitor** - анализ функций на предмет побочных эффектов
3. **ConstClassVisitor** - сбор констант классов

### Фаза 2: Базовые вычисления (Basic Evaluation)
1. **BinaryAndIssetVisitor** - вычисление бинарных операций
2. **VarToScalarVisitor** - замена переменных скалярами
3. **ConstClassVisitor** - замена констант классов

### Фаза 3: Инлайнинг (Inlining)
1. **CallFunctionVisitor** - инлайнинг вызовов функций
2. **ConstructorAndMethodsVisitor** - инлайнинг конструкторов и методов
3. **ParametersToArgsVisitor** - замена параметров аргументами

### Фаза 4: Упрощение (Simplification)
1. **TernarReturnVisitor** - преобразование if-else в тернарные операторы
2. **ConcatAssertVisitor** - объединение последовательных echo
3. **RemoveVisitor** - удаление мёртвого кода

### Фаза 5: Повторение
Повторение фаз 2-4 до тех пор, пока возможны трансформации.

---

## Критерии "чистоты" функций/методов

Функция/метод считается **"чистой"** и может быть инлайнена, если:

1. **Не содержит побочных эффектов:**
   - Не использует глобальные переменные (`$_GET`, `$_POST`, `$_SESSION`, etc.)
   - Не выполняет I/O операции (`echo`, `print`, `file_*`, `fopen`, etc.)
   - Не изменяет внешнее состояние (нет изменения свойств объектов вне области видимости)
   - Не использует недетерминированные функции (`rand`, `time`, `uniqid`, etc.)

2. **Детерминирована:**
   - Одинаковые входные данные всегда дают одинаковый результат
   - Не зависит от внешнего состояния

3. **Проста для инлайнинга:**
   - Содержит один return statement (или может быть преобразована к такому виду)
   - Не содержит циклы с изменяемым состоянием
   - Не содержит сложную логику управления потоком (goto, break, continue вне циклов)

### Примеры чистых функций:

```php
// ✅ Чистая - только математика
function add($a, $b) {
    return $a + $b;
}

// ✅ Чистая - только операции со строками
function concat($a, $b) {
    return $a . $b;
}

// ✅ Чистая - детерминированная логика
function isValid($username, $password) {
    return $username . '_' . $password === 'test_test';
}

// ❌ Нечистая - побочный эффект (echo)
function printSum($a, $b) {
    echo $a + $b;
}

// ❌ Нечистая - использует глобальное состояние
function getUsername() {
    return $_GET['username'] ?? 'default';
}

// ❌ Нечистая - недетерминированная
function getRandomId() {
    return uniqid();
}
```

---

## Карта зависимостей между узлами

Эта карта показывает, какие узлы зависят от других и в каком порядке они должны обрабатываться.

### Граф зависимостей

```
┌─────────────────────────────────────────────────────────────────┐
│                      ВЕРХНИЙ УРОВЕНЬ                             │
│  (контекст выполнения, объявления, глобальная область видимости) │
└─────────────────────────────────────────────────────────────────┘
                                 │
                    ┌────────────┴────────────┐
                    ↓                         ↓
          ┌──────────────────┐      ┌──────────────────┐
          │ Stmt_Function    │      │   Stmt_Class     │
          │ (объявления      │      │   (объявления    │
          │  функций)        │      │    классов)      │
          └──────────────────┘      └──────────────────┘
                    │                         │
                    │                    ┌────┴────┐
                    │                    ↓         ↓
                    │           ┌────────────┐ ┌──────────────┐
                    │           │Stmt_       │ │Stmt_         │
                    │           │ClassConst  │ │ClassMethod   │
                    │           └────────────┘ └──────────────┘
                    │                    │         │
                    │                    │         │
                    └────────────────────┴─────────┴───────────┐
                                                                ↓
                                                      ┌───────────────────┐
                                                      │  ВЫРАЖЕНИЯ        │
                                                      │  (Expressions)    │
                                                      └───────────────────┘
                                                                ↓
                    ┌───────────────────────────────────────────┴──────────────────────┐
                    ↓                                   ↓                               ↓
          ┌──────────────────┐              ┌──────────────────┐           ┌──────────────────┐
          │ Expr_FuncCall    │              │ Expr_MethodCall  │           │ Expr_StaticCall  │
          │ (вызовы функций) │              │ (вызовы методов) │           │(статич. методы)  │
          └──────────────────┘              └──────────────────┘           └──────────────────┘
                    │                                   │                               │
                    └───────────────────────────────────┴───────────────────────────────┘
                                                        ↓
                                              ┌───────────────────┐
                                              │ Expr_Variable     │
                                              │ Expr_Assign       │
                                              │ (переменные)      │
                                              └───────────────────┘
                                                        ↓
                                              ┌───────────────────┐
                                              │ Expr_BinaryOp     │
                                              │ (операции)        │
                                              └───────────────────┘
                                                        ↓
                                              ┌───────────────────┐
                                              │ Scalar            │
                                              │ (скаляры)         │
                                              └───────────────────┘
```

### Типы зависимостей

#### 1. Зависимость по определению (Definition Dependency)
Узел использует значение, определённое в другом узле.

**Примеры:**
```php
// Expr_Variable зависит от Expr_Assign
$x = 5;          // Stmt_Expression(Expr_Assign)
echo $x;         // Expr_Variable зависит от предыдущего присваивания

// Expr_FuncCall зависит от Stmt_Function
function add($a, $b) { return $a + $b; }  // Stmt_Function
$result = add(1, 2);                      // Expr_FuncCall зависит от объявления функции

// Expr_ClassConstFetch зависит от Stmt_ClassConst
class Auth {
    const HASH = 'test';  // Stmt_ClassConst
}
echo Auth::HASH;          // Expr_ClassConstFetch зависит от константы
```

#### 2. Зависимость по вычислению (Evaluation Dependency)
Узел может быть вычислен только после вычисления его дочерних узлов.

**Примеры:**
```php
// Expr_BinaryOp_Plus зависит от своих операндов
1 + (2 * 3)  // BinaryOp_Plus зависит от BinaryOp_Mul

// Expr_FuncCall зависит от своих аргументов
test(1 + 2, 3 * 4)  // FuncCall зависит от вычисления аргументов
```

#### 3. Зависимость по скопу (Scope Dependency)
Узел может использовать значения только из доступной области видимости.

**Примеры:**
```php
class Auth {
    const HASH = 'test';

    public function check() {
        // Может использовать self::HASH (скоп класса)
        return self::HASH;
    }
}

function outer() {
    $x = 5;

    function inner() {
        // НЕ может использовать $x (другой скоп)
        // Может использовать только глобальные или переданные параметры
    }
}
```

### Матрица зависимостей

| Узел источник → | Scalar | Variable | Assign | BinaryOp | FuncCall | MethodCall | ClassConst | Function | Class |
|----------------|--------|----------|--------|----------|----------|------------|------------|----------|-------|
| **Scalar** | - | - | - | - | - | - | - | - | - |
| **Variable** | → | → | ← | - | - | - | - | - | - |
| **Assign** | → | ← | - | → | → | → | → | - | - |
| **BinaryOp** | → | → | - | → | - | - | - | - | - |
| **FuncCall** | → | → | - | → | → | - | - | ← | - |
| **MethodCall** | → | → | - | → | → | → | - | - | ← |
| **ClassConst** | → | - | - | - | - | - | - | - | ← |
| **Function** | → | → | → | → | → | - | - | → | - |
| **Class** | → | → | → | → | → | → | ← | - | → |

**Обозначения:**
- `→` - может содержать в качестве дочернего узла
- `←` - зависит от (использует определение из)
- `-` - нет прямой зависимости

### Порядок обработки зависимостей

Для корректной развёртки необходимо обрабатывать узлы в следующем порядке:

1. **Снизу вверх (Bottom-up)** для вычисления выражений:
   ```
   Scalar → BinaryOp → Variable → Assign → Expression
   ```

2. **Сверху вниз (Top-down)** для разрешения определений:
   ```
   Function/Class → их использование (FuncCall/MethodCall/etc.)
   ```

3. **Итеративно** для взаимозависимых узлов:
   ```
   Несколько проходов до стабилизации (пока возможны трансформации)
   ```

### Примеры порядка обработки

#### Пример 1: Простое вычисление
```php
$x = 1 + 2;
echo $x * 3;
```

**Порядок обработки:**
1. `1 + 2` → `3` (BinaryOp)
2. `$x = 3` → сохранить в скоп (Assign)
3. `$x` → `3` (Variable замена)
4. `3 * 3` → `9` (BinaryOp)
5. `echo 9` (Echo)

#### Пример 2: Инлайнинг функции
```php
function add($a, $b) {
    return $a + $b;
}
echo add(1, 2);
```

**Порядок обработки:**
1. Сохранить `add` в скоп (Function)
2. Заменить `add(1, 2)` на `1 + 2` (FuncCall → тело функции)
3. `1 + 2` → `3` (BinaryOp)
4. `echo 3` (Echo)

#### Пример 3: Класс с методом
```php
class Auth {
    const HASH = 'test';
    public function check($pass) {
        return $pass === self::HASH;
    }
}
$auth = new Auth();
echo $auth->check('test');
```

**Порядок обработки:**
1. Сохранить `HASH = 'test'` в скоп класса (ClassConst)
2. Сохранить метод `check` в скоп класса (ClassMethod)
3. Сохранить класс `Auth` в глобальный скоп (Class)
4. `self::HASH` → `'test'` внутри метода (ClassConstFetch)
5. Заменить `$auth->check('test')` на тело метода с подстановкой `$pass = 'test'`
6. `'test' === 'test'` → `true` (BinaryOp)
7. `echo true` → `echo 1` (Echo)
