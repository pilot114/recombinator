<?php

// EXTERNAL_STATE
$username = $_GET["username"];
$password = $_GET["password"];

// PURE
$hash = md5((string) $password);
$userKey = strtolower((string) $username) . "_" . $hash;

// IO
echo "Processing user: " . $username . "\n";
echo "Key: " . $userKey . "\n";

// PURE
$greeting = "Hello, " . ucfirst((string) $username);
$length = strlen($greeting);
