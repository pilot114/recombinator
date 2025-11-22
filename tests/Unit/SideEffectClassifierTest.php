<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use Recombinator\SideEffectClassifier;
use Recombinator\SideEffectType;

beforeEach(function () {
    $this->parser = (new ParserFactory())->createForHostVersion();
    $this->classifier = new SideEffectClassifier();
});

it('classifies pure mathematical operations as PURE', function () {
    $code = '<?php $x = 1 + 2 * 3;';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::PURE);
});

it('classifies pure string operations as PURE', function () {
    $code = '<?php $x = strtoupper("hello");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::PURE);
});

it('classifies pure array operations as PURE', function () {
    $code = '<?php $x = array_merge([1, 2], [3, 4]);';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::PURE);
});

it('classifies echo as IO', function () {
    $code = '<?php echo "Hello, World!";';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::IO);
});

it('classifies print as IO', function () {
    $code = '<?php print("Test");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]->expr);

    expect($effect)->toBe(SideEffectType::IO);
});

it('classifies file_get_contents as IO', function () {
    $code = '<?php $content = file_get_contents("/path/to/file.txt");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::IO);
});

it('classifies file_put_contents as IO', function () {
    $code = '<?php file_put_contents("/path/to/file.txt", "data");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::IO);
});

it('classifies $_GET as EXTERNAL_STATE', function () {
    $code = '<?php $username = $_GET["username"];';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::EXTERNAL_STATE);
});

it('classifies $_POST as EXTERNAL_STATE', function () {
    $code = '<?php $data = $_POST["data"];';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::EXTERNAL_STATE);
});

it('classifies $_SESSION as EXTERNAL_STATE', function () {
    $code = '<?php $id = $_SESSION["user_id"];';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::EXTERNAL_STATE);
});

it('classifies global variable as GLOBAL_STATE', function () {
    $code = '<?php global $config;';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::GLOBAL_STATE);
});

it('classifies ini_set as GLOBAL_STATE', function () {
    $code = '<?php ini_set("display_errors", "1");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::GLOBAL_STATE);
});

it('classifies session_start as GLOBAL_STATE', function () {
    $code = '<?php session_start();';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::GLOBAL_STATE);
});

it('classifies header as GLOBAL_STATE', function () {
    $code = '<?php header("Content-Type: application/json");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::GLOBAL_STATE);
});

it('classifies eval as GLOBAL_STATE', function () {
    $code = '<?php eval("echo 1;");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::GLOBAL_STATE);
});

it('classifies mysqli_query as DATABASE', function () {
    $code = '<?php mysqli_query($conn, "SELECT * FROM users");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::DATABASE);
});

it('classifies pg_query as DATABASE', function () {
    $code = '<?php pg_query($conn, "SELECT * FROM users");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::DATABASE);
});

it('classifies PDO query method as DATABASE', function () {
    $code = '<?php $stmt = $pdo->query("SELECT * FROM users");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::DATABASE);
});

it('classifies curl_exec as HTTP', function () {
    $code = '<?php $response = curl_exec($ch);';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::HTTP);
});

it('classifies fsockopen as HTTP', function () {
    $code = '<?php $socket = fsockopen("example.com", 80);';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::HTTP);
});

it('classifies mail as HTTP', function () {
    $code = '<?php mail("user@example.com", "Subject", "Message");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::HTTP);
});

it('classifies rand as NON_DETERMINISTIC', function () {
    $code = '<?php $random = rand(1, 100);';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::NON_DETERMINISTIC);
});

it('classifies time as NON_DETERMINISTIC', function () {
    $code = '<?php $now = time();';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::NON_DETERMINISTIC);
});

it('classifies uniqid as NON_DETERMINISTIC', function () {
    $code = '<?php $id = uniqid();';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::NON_DETERMINISTIC);
});

it('classifies date as NON_DETERMINISTIC', function () {
    $code = '<?php $today = date("Y-m-d");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::NON_DETERMINISTIC);
});

it('classifies include as MIXED', function () {
    $code = '<?php include "file.php";';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::MIXED);
});

it('classifies block with pure operations as PURE', function () {
    $code = '<?php
        $a = 1 + 2;
        $b = strtoupper("hello");
        $c = $a * 3;
    ';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classifyBlock($ast);

    expect($effect)->toBe(SideEffectType::PURE);
});

it('classifies block with IO as IO', function () {
    $code = '<?php
        $a = 1 + 2;
        echo $a;
    ';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classifyBlock($ast);

    expect($effect)->toBe(SideEffectType::IO);
});

it('classifies block with mixed effects as MIXED', function () {
    $code = '<?php
        $username = $_GET["username"];
        echo "Hello, " . $username;
        $data = file_get_contents("/path/to/file.txt");
    ';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classifyBlock($ast);

    expect($effect)->toBe(SideEffectType::MIXED);
});

it('classifies if statement by analyzing its body', function () {
    $code = '<?php
        if ($condition) {
            echo "test";
        }
    ';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::IO);
});

it('classifies ternary operator by analyzing branches', function () {
    $code = '<?php $x = $cond ? 1 : 2;';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::PURE);
});

it('classifies while loop by analyzing body', function () {
    $code = '<?php
        while ($i < 10) {
            echo $i;
            $i++;
        }
    ';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::IO);
});

it('classifies foreach loop by analyzing body', function () {
    $code = '<?php
        foreach ($arr as $item) {
            $sum += $item;
        }
    ';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::PURE);
});

it('classifies complex code with multiple effects', function () {
    $code = '<?php
        // EXTERNAL_STATE
        $username = $_GET["username"];

        // PURE
        $hash = md5($username);

        // DATABASE
        $user = mysqli_query($conn, "SELECT * FROM users WHERE hash = \"$hash\"");

        // IO
        echo "User: " . $user["name"];
    ';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classifyBlock($ast);

    expect($effect)->toBe(SideEffectType::MIXED);
});

it('classifies assignment to local variable as PURE', function () {
    $code = '<?php $x = 123;';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::PURE);
});

it('classifies assignment with IO on right side as IO', function () {
    $code = '<?php $content = file_get_contents("file.txt");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::IO);
});

it('classifies assignment to superglobal as EXTERNAL_STATE', function () {
    $code = '<?php $_SESSION["user_id"] = 123;';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::EXTERNAL_STATE);
});

it('handles unknown user-defined functions as MIXED', function () {
    $code = '<?php $result = unknownFunction();';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::MIXED);
});

it('classifies exit/die as IO', function () {
    $code = '<?php exit("Error");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::IO);
});

it('classifies static method calls correctly', function () {
    $code = '<?php $data = DB::query("SELECT * FROM users");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::DATABASE);
});

it('classifies HTTP method calls correctly', function () {
    $code = '<?php $response = $client->get("https://api.example.com");';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::HTTP);
});

it('classifies nested operations correctly', function () {
    $code = '<?php $result = strtoupper(substr("hello", 0, 2));';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classify($ast[0]);

    expect($effect)->toBe(SideEffectType::PURE);
});

it('stops analyzing when reaches MIXED', function () {
    $code = '<?php
        echo "test";
        $x = 1 + 2;
        file_get_contents("file.txt");
        mysqli_query($conn, "SELECT 1");
    ';
    $ast = $this->parser->parse($code);
    $effect = $this->classifier->classifyBlock($ast);

    // Должно быть MIXED (IO + IO + DATABASE = MIXED)
    expect($effect)->toBe(SideEffectType::MIXED);
});
