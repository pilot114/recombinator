<?php

class Auth {
    public $secret = "s3cret";

    public function getToken() {
        return $this->secret;
    }
}

$auth = new Auth();
$token = $auth->getToken();
echo $token;
