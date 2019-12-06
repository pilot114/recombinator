<?php

require ('Auth.php');

function test($x, $y)
{
    return $x + $y;
}

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

?>

<!--невыполняющийся код просто вырезается (запоминается)-->

<!-- Оптимизацией может быть выполнение выражений без переменных и вызовов (ВЫЧИСЛЕНИЕ) -->
echo 3;
echo true;

<!-- тоже оптимизация - уже на уровне замены конструкций (ЗАМЕЩЕНИЕ) -->
$username = $_GET['username'] ?? 'default_username';
$pass = $_GET['pass'] ?? 'default_pass';

<!-- Вызов подменён вычислением результата выполнения вызываемого скопа -->
<!-- Тут следует осторожно подходить к return -->
$result = 'fail';
if ($username . '_' . $pass == 'test_test') {
    $result = 'success';
}
echo $result . "\n";

<!-- Подмена и вычисление вместе дают только вызов "побочного эффекта" -->
echo "success\n";

<!-- Супероптимизация - ситуация, когда остались только побочные эффекты. Должно быть промежуточным звеном -->
<!-- Тут особую проблему создают порядок в скобочках и отличия кавычках -->
echo "31\n" .
    . (($_GET['username'] ?? 'default_username' . '_' . $_GET['pass'] ?? 'default_pass') == 'test_test') ? 'success' : 'fail'
    . "\n";
