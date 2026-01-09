<?php

/**
 * Complex isset pattern that can be simplified to null coalescing.
 *
 * Before: verbose isset checks with default values
 * After: concise ?? operator usage
 */

$username = "default_username";
if (isset($_GET["username"])) {
    $username = $_GET["username"];
}

$password = "default_password";
if (isset($_GET["password"])) {
    $password = $_GET["password"];
}

$role = "guest";
if (isset($_GET["role"])) {
    $role = $_GET["role"];
}

$theme = "light";
if (isset($_COOKIE["theme"])) {
    $theme = $_COOKIE["theme"];
}

echo "User: " . $username;
echo "Role: " . $role;
