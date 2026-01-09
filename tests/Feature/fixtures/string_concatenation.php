<?php

/**
 * String concatenation that can be simplified.
 *
 * Before: multiple string concatenation operations
 * After: pre-concatenated strings where possible
 */

$firstName = "John";
$lastName = "Doe";
$fullName = $firstName . " " . $lastName;

$greeting = 'Hello, World!';
$separator = '-----';

$protocol = "https";
$domain = "example";
$tld = "com";
$url = $protocol . "://" . $domain . "." . $tld;

$prefix = "user_";
$id = "12345";
$suffix = "_data";
$key = $prefix . $id . $suffix;

$part1 = "This is ";
$part2 = "a very ";
$part3 = "long ";
$part4 = "message.";
$message = $part1 . $part2 . $part3 . $part4;

echo $fullName;
echo $greeting;
echo $url;
