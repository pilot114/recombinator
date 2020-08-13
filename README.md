Генерация плоского кода, эквивалентного оригинальному.
Для этого нужно разворачивать функции и классы, правильно подставляя
полученую развертку в места использования.

# roadmap

- выделить всё, что может быть "побочным эффектом" (Супероптимизация)
- разделение побочных эффектов по типам
- выполнение кода в рунтайме оптимизации (предвыполнение)
- стоп скопы - то, что оптимизировать не надо (например, внешние либы)


### Пример

Auth.php

    /**
     * dump Auth
     */
    class Auth
    {
        const HASH = 'test_test';
    
        public function login($username, $password)
        {
            if ($username . '_' . $password == self::HASH) {
                return 'success';
            }
            return 'fail';
        }
    }

index.php

    require ('Auth.php');
    
    function test($x, $y)
    {
        return $x + $y;
    }
    
    echo 1 / (2 + 3);
    
    echo test(1, 2);
    
    echo is_array([]);
    
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

После супероптимизации дает следующий результат:

    echo "0.231\n" .
        . (($_GET['username'] ?? 'default_username' . '_' . $_GET['pass'] ?? 'default_pass') === 'test_test') ? 'success' : 'fail'
        . "success\n";

Супероптимизация - ситуация, когда остались только побочные эффекты. Должно быть промежуточным звеном.
Тут особую проблему создают порядок в скобочках и отличия в кавычках.

Если разделить побочные эффекты по видам и ввести промежуточные переменные,
получаем желаемый итоговый результат:

    $username = $_GET['username'] ?? 'default_username';
    $pass = $_GET['pass'] ?? 'default_pass';
    
    $isLogin = ($username. '_' . $pass) === 'test_test';
    
    echo "0.231\n" . ($isLogin ? 'success' : 'fail') . "success\n";

Этот код эквивалентен исходному, но более лаконичен и понятен.



### Подробнее

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
