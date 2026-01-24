<?php

require __DIR__ . '/functions.php';

/**
 * dump Auth
 * $auth = new Auth();
 * $result = $auth->login($username, $pass);
 * $result = $auth->login('test', 'test');
 */
class Auth
{
    const HASH = 'test_test';

    public $test = 'empty';

    public function login(string $username, string $password): string
    {
        if ($username . '_' . $password === self::HASH) {
            return 'success';
        }

        return 'fail';
    }
}
