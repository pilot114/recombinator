<?php

/**
 * Deeply nested conditions that can be simplified.
 *
 * Before: deeply nested if statements
 * After: flattened conditions using early returns or combined conditions
 */
function processUser(array $user, array $permissions, array $context): string
{
    if ($user["active"] && ($permissions["canEdit"] && $context["mode"] === "edit")) {
        return "editing";
    }

    return "denied";
}

function validateOrder(array $order, array $inventory, array $payment): string
{
    if (count($order["items"]) <= 0) {
        return "rejected";
    }

    if (!($inventory["available"] && $payment["verified"])) {
        return "rejected";
    }

    if ($payment["amount"] >= $order["total"]) {
        return "approved";
    }

    return "rejected";
}

function checkAccess($level, $role, $department): void
{
    if ($level > 0) {
        if ($role === "admin") {
            echo "Admin access";
        } elseif ($role === "manager") {
            if ($department === "sales") {
                echo "Sales manager access";
            }
        }
    }
}
