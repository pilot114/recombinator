<?php
echo 0.2;
echo test(1, 2);
echo is_array([]);
$username = 'default_username';
$username = $_GET['username'] ?? $username;
$pass = 'default_pass';
$pass = $_GET['pass'] ?? $pass;
$auth = new Auth();
$result = $auth->login($username, $pass);
echo $result . "\n";
$result = $auth->login('test', 'test');
echo $result . "\n";