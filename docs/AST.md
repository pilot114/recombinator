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
