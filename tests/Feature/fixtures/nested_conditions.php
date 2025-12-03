<?php

/**
 * Deeply nested conditions that can be simplified.
 *
 * Before: deeply nested if statements
 * After: flattened conditions using early returns or combined conditions
 */

function processUser($user, $permissions, $context) {
    if ($user !== null) {
        if ($user["active"]) {
            if ($permissions["canEdit"]) {
                if ($context["mode"] === "edit") {
                    return "editing";
                }
            }
        }
    }
    return "denied";
}

function validateOrder($order, $inventory, $payment) {
    if ($order !== null) {
        if (count($order["items"]) > 0) {
            if ($inventory["available"]) {
                if ($payment["verified"]) {
                    if ($payment["amount"] >= $order["total"]) {
                        return "approved";
                    }
                }
            }
        }
    }
    return "rejected";
}

function checkAccess($level, $role, $department) {
    if ($level > 0) {
        if ($role === "admin") {
            echo "Admin access";
        } else {
            if ($role === "manager") {
                if ($department === "sales") {
                    echo "Sales manager access";
                }
            }
        }
    }
}
