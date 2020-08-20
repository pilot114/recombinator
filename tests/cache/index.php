<?php
echo '123451' . 0.2 . test(1, 2) . is_array([]);
$username = $_GET['username'] ?? $username;
$pass = $_GET['pass'] ?? $pass;
$auth = new Auth();
$result = $auth->login($username, $pass);
echo $result . "\n";
$result = $auth->login('test', 'test');
echo $result . "\n";
