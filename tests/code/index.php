<?php

require ('Auth.php');

function test($x, $y)
{
    return $x + $y;
}

echo 1 . 2 . 3 . 4 . 5 . true . false;

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
;;;;;;
?>

<div>test html</div>
