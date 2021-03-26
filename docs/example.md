Входной код (несколько файлов):

Auth.php

    /**
     * dump Auth
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

index.php

    require ('Auth.php');
    
    function test($x, $y)
    {
        return $x + $y;
    }
    
    echo 1 / (2 + 3);
    
    echo test(1, 2);
    
    echo is_array([]);
    
    $username = 'default_username';
    if (isset($_GET['username'])) {
        $username = $_GET['username'];
    }
    
    $pass = 'default_pass';
    if (isset($_GET['pass'])) {
        $pass = $_GET['pass'];
    }
    
    $auth = new Auth();
    $result = $auth->login($username, $pass);
    echo $result . "\n";
    
    $result = $auth->login('test', 'test');
    echo $result . "\n";

После супероптимизации получаем следующий результат:

    echo "0.231\n" .
        . (($_GET['username'] ?? 'default_username' . '_' . $_GET['pass'] ?? 'default_pass') === 'test_test') ? 'success' : 'fail'
        . "success\n";

Если разделить побочные эффекты по видам и ввести промежуточные переменные,
получаем желаемый итоговый результат:

    $username = $_GET['username'] ?? 'default_username';
    $pass = $_GET['pass'] ?? 'default_pass';
    
    $isLogin = ($username. '_' . $pass) === 'test_test';
    
    echo "0.231\n" . ($isLogin ? 'success' : 'fail') . "success\n";
    
Этот код эквивалентен исходному, но более лаконичен и понятен
