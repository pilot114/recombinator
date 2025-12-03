<?php

/**
 * If-return patterns that can be converted to ternary operators.
 *
 * Before: verbose if-else return statements
 * After: concise ternary expressions
 */

class UserValidator {
    public function isAdult($age) {
        if ($age >= 18) {
            return true;
        }
        return false;
    }

    public function getStatus($isActive) {
        if ($isActive) {
            return "active";
        }
        return "inactive";
    }

    public function getRole($isAdmin) {
        if ($isAdmin) {
            return "administrator";
        }
        return "user";
    }

    public function getMessage($hasErrors) {
        if ($hasErrors) {
            return "Please fix the errors";
        }
        return "All good!";
    }
}

function checkPermission($level) {
    if ($level > 5) {
        return "allowed";
    }
    return "denied";
}

function getLabel($count) {
    if ($count == 1) {
        return "item";
    }
    return "items";
}
