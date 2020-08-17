<?php
echo 12345 . true . false;;
echo 0.2;
echo test(1, 2);
echo is_array([1, null, '']);
$username = $_GET['username'] ?? 'default_username';
$pass = $_GET['pass'] ?? 'default_pass';
$auth = new Auth();
$result = $auth->login($username, $pass);
echo $result . "\n";
$result = $auth->login('test', 'test');
echo $result . "\n";
