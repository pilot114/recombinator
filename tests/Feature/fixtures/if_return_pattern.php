<?php

/**
 * If-return patterns that can be converted to ternary operators.
 *
 * Before: verbose if-else return statements
 * After: concise ternary expressions
 */

class UserValidator
{
    public function isAdult($age): bool
    {
        return $age >= 18;
    }

    public function getStatus($isActive): string
    {
        if ($isActive) {
            return "active";
        }

        return "inactive";
    }

    public function getRole($isAdmin): string
    {
        if ($isAdmin) {
            return "administrator";
        }

        return "user";
    }

    public function getMessage($hasErrors): string
    {
        if ($hasErrors) {
            return "Please fix the errors";
        }

        return "All good!";
    }
}

function checkPermission($level): string
{
    if ($level > 5) {
        return "allowed";
    }

    return "denied";
}

function getLabel($count): string
{
    if ($count == 1) {
        return "item";
    }

    return "items";
}
