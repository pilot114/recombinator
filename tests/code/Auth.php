<?php

/**
 * simple Auth
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