<?php
echo '1234510.231';
$username = $_GET['username'] ?? $username;
$pass = $_GET['pass'] ?? $pass;
$auth = new Auth();
$result = $auth->login($username, $pass);
echo $result . "\n";
$result = $auth->login('test', 'test');
echo $result . "\n";
