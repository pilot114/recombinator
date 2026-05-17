<?php

require __DIR__ . '/functions.php';

class Auth
{
    const string HASH = 'test_test';

    public string $test = 'empty';

    public function login(string $username, string $password): string
    {
        if ($username . '_' . $password === self::HASH) {
            return 'success';
        }

        return 'fail';
    }
}
