<?php
echo '1234510.231';
$username = $_GET['username'] ?? 'default_username';
$pass = $_GET['pass'] ?? 'default_pass';
$auth = new Auth();
$result = $auth->login($username, $pass);
echo $result . "\n";
$result = $auth->login('test', 'test');
echo $result . "\n";
