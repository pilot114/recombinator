<?php

/**
 * dump Auth
 * $auth = new Auth();
 * $result = $auth->login($username, $pass);
 * $result = $auth->login('test', 'test');
 */
class Auth
{
    const HASH = 'test_test';

    public function login($username, $password)
    {
        if ($username . '_' . $password == self::HASH) {
            return 'success';
        }
        return 'fail';
    }
}