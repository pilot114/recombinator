# @028;0 @072Q@B:8 (Unfolding Rules)

-B>B 4>:C<5=B >?8AK205B ?@028;0 B@0=AD>@<0F88 PHP :>40 =0 MB0?5 @072Q@B:8.  072Q@B:0  MB> ?@>F5AA 70<5=K 2A5E 01AB@0:F89 (DC=:F89, :;0AA>2, ?5@5<5==KE) 8E :>=:@5B=K<8 7=0G5=8O<8 8;8 B5;0<8.

---

## 3;02;5=85

1. [1I85 ?@8=F8?K](#>1I85-?@8=F8?K)
2. [@028;0 4;O :>=AB0=B](#?@028;0-4;O-:>=AB0=B)
3. [@028;0 4;O DC=:F89](#?@028;0-4;O-DC=:F89)
4. [@028;0 4;O <5B>4>2 :;0AA>2](#?@028;0-4;O-<5B>4>2-:;0AA>2)
5. [@028;0 4;O :>=AB@C:B>@>2](#?@028;0-4;O-:>=AB@C:B>@>2)
6. [@028;0 4;O AB0B8G5A:8E <5B>4>2](#?@028;0-4;O-AB0B8G5A:8E-<5B>4>2)
7. [@028;0 4;O 70<K:0=89 8 AB@5;>G=KE DC=:F89](#?@028;0-4;O-70<K:0=89-8-AB@5;>G=KE-DC=:F89)
8. [@028;0 4;O ?5@5<5==KE](#?@028;0-4;O-?5@5<5==KE)
9. [@028;0 4;O <0AA82>2](#?@028;0-4;O-<0AA82>2)
10. [@028;0 4;O CA;>289](#?@028;0-4;O-CA;>289)
11. [3@0=8G5=8O 8 edge cases](#>3@0=8G5=8O-8-edge-cases)

---

## 1I85 ?@8=F8?K

### @8=F8? 1: '8AB>B0
"@0=AD>@<0F88 ?@8<5=ONBAO B>;L:> : "G8ABK<" :>=AB@C:F8O<, :>B>@K5:
- 5 8<5NB ?>1>G=KE MDD5:B>2
- 5B5@<8=8@>20=K (>48=0:>2K9 2E>4 ’ >48=0:>2K9 2KE>4)
- 5 7028AOB >B 2=5H=53> A>AB>O=8O

### @8=F8? 2: !>E@0=5=85 A5<0=B8:8
N10O B@0=AD>@<0F8O 4>;6=0 A>E@0=OBL 8AE>4=CN A5<0=B8:C ?@>3@0<<K. >A;5 B@0=AD>@<0F88 ?@>3@0<<0 4>;6=0 2K4020BL B>B 65 @57C;LB0B.

### @8=F8? 3: >@O4>: 2K?>;=5=8O
"@0=AD>@<0F88 2K?>;=ONBAO A=87C 225@E (>B ;8ABL52 45@520 : :>@=N) 8 8B5@0B82=> (=5A:>;L:> ?@>E>4>2 4> AB018;870F88).

### @8=F8? 4: 57>?0A=>ABL
 A;CG05 A><=5=89 2 157>?0A=>AB8 B@0=AD>@<0F88  >=0 =5 ?@8<5=O5BAO. CGH5 A>E@0=8BL :>4 :0: 5ABL, G5< A;><0BL 53>.

---

## @028;0 4;O :>=AB0=B

### @028;> CONST-1: !:0;O@=K5 :>=AB0=BK

**?8A0=85:** 0<5=0 8A?>;L7>20=8O :>=AB0=BK 5Q 7=0G5=85<.

**@8<5=8<> ::**
- `const NAME = value;` (3;>10;L=K5 :>=AB0=BK)
- `class::CONST` (:>=AB0=BK :;0AA>2)
- `self::CONST` (2=CB@8 :;0AA0)

**#A;>28O:**
- =0G5=85 :>=AB0=BK O2;O5BAO A:0;O@>< (int, float, string, bool)
- >=AB0=B0 =5 ?5@5>?@545;O5BAO

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
class Auth {
    const HASH = 'test_test';

    public function check($pass) {
        return $pass === self::HASH;
    }
}

// >A;5 B@0=AD>@<0F88
class Auth {
    public function check($pass) {
        return $pass === 'test_test';
    }
}

// 0;55 :>=AB0=BC <>6=> C40;8BL
```

** 50;870F8O:** `ConstClassVisitor`

---

### @028;> CONST-2: 0AA82K :0: :>=AB0=BK

**?8A0=85:** 0<5=0 :>=AB0=B=KE <0AA82>2 8E 7=0G5=8O<8.

**@8<5=8<> ::**
```php
const ARRAY_CONST = [1, 2, 3];
class::ARRAY_CONST
```

**#A;>28O:**
- A5 M;5<5=BK <0AA820 O2;ONBAO A:0;O@0<8 8;8 2;>65==K<8 <0AA820<8 A:0;O@>2
- 0AA82 =5 87<5=O5BAO

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
class Config {
    const DEFAULTS = ['host' => 'localhost', 'port' => 3306];

    public function getHost() {
        return self::DEFAULTS['host'];
    }
}

// >A;5 B@0=AD>@<0F88 (H03 1)
class Config {
    public function getHost() {
        return ['host' => 'localhost', 'port' => 3306]['host'];
    }
}

// >A;5 B@0=AD>@<0F88 (H03 2 - 2KG8A;5=85 ArrayDimFetch)
class Config {
    public function getHost() {
        return 'localhost';
    }
}
```

** 50;870F8O:** "@51C5B @0AH8@5=8O `ConstClassVisitor` 4;O ?>445@6:8 <0AA82>2

**!B0BCA:** ó 5 @50;87>20=>

---

### @028;> CONST-3: >=AB0=BK A 2KG8A;5=8O<8

**?8A0=85:** KG8A;5=85 :>=AB0=B=KE 2K@065=89.

**@8<5=8<> ::**
```php
const COMPUTED = 1 + 2 * 3;
const CONCAT = 'Hello' . ' ' . 'World';
```

**#A;>28O:**
- K@065=85 A>AB>8B B>;L:> 87 A:0;O@=KE >?5@0F89
- A5 >?5@0=4K 8725AB=K =0 MB0?5 :><?8;OF88

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
const TIMEOUT = 60 * 5;  // 5 <8=CB

function wait() {
    sleep(TIMEOUT);
}

// >A;5 B@0=AD>@<0F88
function wait() {
    sleep(300);
}
```

** 50;870F8O:** ><18=0F8O `BinaryAndIssetVisitor` + `ConstClassVisitor`

**!B0BCA:** ó '0AB8G=> @50;87>20=>

---

## @028;0 4;O DC=:F89

### @028;> FUNC-1: =;09=8=3 G8ABKE >4=>AB@>G=KE DC=:F89

**?8A0=85:** 0<5=0 2K7>20 DC=:F88 5Q B5;><.

**@8<5=8<> ::**
```php
function name($params) {
    return expression;
}
```

**#A;>28O:**
- $C=:F8O A>45@68B B>;L:> >48= `return` statement
- $C=:F8O =5 A>45@68B ?>1>G=KE MDD5:B>2
- $C=:F8O =5 @5:C@A82=0
- A5 ?0@0<5B@K 8A?>;L7CNBAO 2 2K@065=88 8;8 2K@065=85 >B =8E =5 7028A8B

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
function add($a, $b) {
    return $a + $b;
}

$result = add(1, 2);

// >A;5 B@0=AD>@<0F88 (H03 1: ?>4AB0=>2:0 B5;0)
$result = 1 + 2;

// >A;5 B@0=AD>@<0F88 (H03 2: 2KG8A;5=85)
$result = 3;
```

**;3>@8B<:**
1. 09B8 >?@545;5=85 DC=:F88
2. @>25@8BL CA;>28O ?@8<5=8<>AB8
3. 0<5=8BL ?0@0<5B@K 0@3C<5=B0<8 2 B5;5 DC=:F88
4. 0<5=8BL 2K7>2 DC=:F88 ?@5>1@07>20==K< B5;><
5. B<5B8BL >?@545;5=85 DC=:F88 4;O C40;5=8O

** 50;870F8O:** `CallFunctionVisitor`, `ParametersToArgsVisitor`

**!B0BCA:**   50;87>20=>

---

### @028;> FUNC-2: =;09=8=3 DC=:F89 A =5A:>;L:8<8 statements

**?8A0=85:** 0<5=0 2K7>20 DC=:F88 ?>A;54>20B5;L=>ABLN statements.

**@8<5=8<> ::**
```php
function name($params) {
    $var1 = expr1;
    $var2 = expr2;
    return expr3;
}
```

**#A;>28O:**
- $C=:F8O A>45@68B B>;L:> ?@8A20820=8O ;>:0;L=KE ?5@5<5==KE
- 0:0=G8205BAO >4=8< `return`
- 5 A>45@68B ?>1>G=KE MDD5:B>2
- 5 A>45@68B CA;>289 8 F8:;>2

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
function calculate($x) {
    $doubled = $x * 2;
    $result = $doubled + 10;
    return $result;
}

$value = calculate(5);
echo $value;

// >A;5 B@0=AD>@<0F88 (H03 1: @072Q@B:0 DC=:F88)
$calculate_5_doubled = 5 * 2;
$calculate_5_result = $calculate_5_doubled + 10;
$value = $calculate_5_result;
echo $value;

// >A;5 B@0=AD>@<0F88 (H03 2: 2KG8A;5=8O 8 ?>4AB0=>2:8)
$value = 20;
echo $value;

// >A;5 B@0=AD>@<0F88 (H03 3: 70<5=0 ?5@5<5==>9)
echo 20;
```

**;3>@8B<:**
1. !>740BL C=8:0;L=K5 8<5=0 4;O ;>:0;L=KE ?5@5<5==KE DC=:F88
2. 0<5=8BL ?0@0<5B@K 0@3C<5=B0<8
3. AB028BL 2A5 statements ?5@54 2K7>2><
4. 0<5=8BL 2K7>2 =0 ?5@5<5==CN @57C;LB0B0

** 50;870F8O:** "@51C5B @0AH8@5=8O `CallFunctionVisitor`

**!B0BCA:** ó 5 @50;87>20=>

---

### @028;> FUNC-3: KG8A;5=85 AB0=40@B=KE DC=:F89

**?8A0=85:** @542K?>;=5=85 45B5@<8=8@>20==KE 2AB@>5==KE DC=:F89 PHP.

**@8<5=8<> ::**
- 0B5<0B8G5A:85: `abs()`, `max()`, `min()`, `round()`, `ceil()`, `floor()`
- !B@>:>2K5: `strlen()`, `strtolower()`, `strtoupper()`, `trim()`, `substr()`
- 0AA82K: `count()`, `array_merge()` (4;O :>=AB0=B)
- @>25@:8 B8?>2: `is_array()`, `is_int()`, `is_string()`

**#A;>28O:**
- A5 0@3C<5=BK O2;ONBAO :>=AB0=B0<8
- $C=:F8O 45B5@<8=8@>20=0
-  57C;LB0B <>6=> 2KG8A;8BL =0 MB0?5 :><?8;OF88

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
$length = strlen('hello');
$upper = strtoupper('world');
$isArray = is_array([1, 2, 3]);

// >A;5 B@0=AD>@<0F88
$length = 5;
$upper = 'WORLD';
$isArray = true;
```

** 50;870F8O:** `EvalStandartFunction`

**!B0BCA:**  '0AB8G=> @50;87>20=>

---

## @028;0 4;O <5B>4>2 :;0AA>2

### @028;> METHOD-1: =;09=8=3 G8ABKE <5B>4>2

**?8A0=85:** 0<5=0 2K7>20 <5B>40 53> B5;>< A CGQB>< :>=B5:AB0 >1J5:B0.

**@8<5=8<> ::**
```php
class Name {
    public function method($params) {
        return expression;
    }
}
```

**#A;>28O:**
- 5B>4 A>45@68B B>;L:> `return` statement
- 5B>4 =5 8A?>;L7C5B `$this` 8;8 8A?>;L7C5B B>;L:> 4;O GB5=8O A2>9AB2
- 5B>4 =5 2K7K205B 4@C385 <5B>4K A ?>1>G=K<8 MDD5:B0<8
- ;0AA 8=AB0=F8@C5BAO >48= @07

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
class Math {
    private $multiplier = 10;

    public function multiply($x) {
        return $x * $this->multiplier;
    }
}

$math = new Math();
$result = $math->multiply(5);

// >A;5 B@0=AD>@<0F88 (H03 1: @072Q@B:0 A2>9AB2)
$math_multiplier = 10;
$result = $math->multiply(5);

// >A;5 B@0=AD>@<0F88 (H03 2: 8=;09= <5B>40)
$result = 5 * $math_multiplier;

// >A;5 B@0=AD>@<0F88 (H03 3: ?>4AB0=>2:0 8 2KG8A;5=85)
$result = 50;
```

**;3>@8B<:**
1. =AB0=F8@>20BL A2>9AB20 :;0AA0 :0: ?5@5<5==K5
2. 0<5=8BL `$this->prop` =0 A>>B25BAB2CNI85 ?5@5<5==K5
3. 0<5=8BL ?0@0<5B@K 0@3C<5=B0<8
4. 0<5=8BL 2K7>2 <5B>40 ?@5>1@07>20==K< B5;><

** 50;870F8O:** `ConstructorAndMethodsVisitor` (2 ?@>F5AA5)

**!B0BCA:** ó  ?@>F5AA5 (A>45@68B `die()`)

---

### @028;> METHOD-2: &5?>G:8 2K7>2>2 <5B>4>2 (Fluent Interface)

**?8A0=85:**  072Q@B:0 F5?>G5: 2K7>2>2 <5B>4>2.

**@8<5=8<> ::**
```php
$obj->method1()->method2()->method3();
```

**#A;>28O:**
- 064K9 <5B>4 2>72@0I05B `$this` 8;8 =>2K9 >1J5:B
- 5B>4K G8ABK5 (157 ?>1>G=KE MDD5:B>2)
- ;0AA ?@>AB>9 (157 A;>6=>9 ;>38:8)

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
class Builder {
    private $value = '';

    public function add($str) {
        $this->value .= $str;
        return $this;
    }

    public function get() {
        return $this->value;
    }
}

$result = (new Builder())->add('Hello')->add(' ')->add('World')->get();

// >A;5 B@0=AD>@<0F88
$builder_value = '';
$builder_value .= 'Hello';
$builder_value .= ' ';
$builder_value .= 'World';
$result = $builder_value;

// >A;5 B@0=AD>@<0F88 (C?@>I5=85)
$result = 'Hello World';
```

** 50;870F8O:** "@51C5B =>2>3> Visitor

**!B0BCA:** ó 5 @50;87>20=>

---

## @028;0 4;O :>=AB@C:B>@>2

### @028;> CTOR-1:  072Q@B:0 ?@>AB>3> :>=AB@C:B>@0

**?8A0=85:** 0<5=0 `new Class()` 8=8F80;870F859 A2>9AB2.

**@8<5=8<> ::**
```php
class Name {
    private $prop1;
    private $prop2;

    public function __construct($param1, $param2) {
        $this->prop1 = $param1;
        $this->prop2 = $param2;
    }
}
```

**#A;>28O:**
- >=AB@C:B>@ A>45@68B B>;L:> ?@8A20820=8O A2>9AB20<
- 5B ?>1>G=KE MDD5:B>2 2 :>=AB@C:B>@5
- ;0AA 8A?>;L7C5BAO >48= @07

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
class Point {
    private $x;
    private $y;

    public function __construct($x, $y) {
        $this->x = $x;
        $this->y = $y;
    }

    public function distance() {
        return sqrt($this->x ** 2 + $this->y ** 2);
    }
}

$point = new Point(3, 4);
$dist = $point->distance();

// >A;5 B@0=AD>@<0F88 (H03 1: @072Q@B:0 :>=AB@C:B>@0)
$point_x = 3;
$point_y = 4;
$dist = $point->distance();

// >A;5 B@0=AD>@<0F88 (H03 2: @072Q@B:0 <5B>40)
$dist = sqrt($point_x ** 2 + $point_y ** 2);

// >A;5 B@0=AD>@<0F88 (H03 3: 2KG8A;5=85)
$dist = sqrt(9 + 16);
$dist = sqrt(25);
$dist = 5.0;
```

**;3>@8B<:**
1. 0<5=8BL `new Class(args)` =0 ?>A;54>20B5;L=>ABL ?@8A20820=89
2. !>740BL ?5@5<5==K5 4;O :064>3> A2>9AB20 A ?@5D8:A>< `{instanceName}_{propertyName}`
3. @>8=8F80;878@>20BL A2>9AB20 7=0G5=8O<8 87 0@3C<5=B>2 :>=AB@C:B>@0
4. 0<5=8BL `$this->prop` 2 <5B>40E =0 A>>B25BAB2CNI85 ?5@5<5==K5

** 50;870F8O:** `ConstructorAndMethodsVisitor` (2 ?@>F5AA5)

**!B0BCA:** ó  ?@>F5AA5

---

### @028;> CTOR-2: >=AB@C:B>@ A> 7=0G5=8O<8 ?> C<>;G0=8N

**?8A0=85:** 1@01>B:0 :>=AB@C:B>@>2 A 7=0G5=8O<8 ?> C<>;G0=8N.

**@8<5=8<> ::**
```php
public function __construct($param1 = default1, $param2 = default2) {
    // ...
}
```

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
class Config {
    private $host;
    private $port;

    public function __construct($host = 'localhost', $port = 3306) {
        $this->host = $host;
        $this->port = $port;
    }
}

$config1 = new Config();
$config2 = new Config('192.168.1.1');
$config3 = new Config('192.168.1.1', 5432);

// >A;5 B@0=AD>@<0F88
$config1_host = 'localhost';
$config1_port = 3306;

$config2_host = '192.168.1.1';
$config2_port = 3306;

$config3_host = '192.168.1.1';
$config3_port = 5432;
```

** 50;870F8O:** "@51C5B @0AH8@5=8O `ConstructorAndMethodsVisitor`

**!B0BCA:** ó 5 @50;87>20=>

---

## @028;0 4;O AB0B8G5A:8E <5B>4>2

### @028;> STATIC-1: =;09=8=3 G8ABKE AB0B8G5A:8E <5B>4>2

**?8A0=85:** 0<5=0 2K7>20 AB0B8G5A:>3> <5B>40 53> B5;><.

**@8<5=8<> ::**
```php
class Name {
    public static function method($params) {
        return expression;
    }
}
```

**#A;>28O:**
- 5B>4 =5 8A?>;L7C5B AB0B8G5A:85 A2>9AB20 8;8 8A?>;L7C5B B>;L:> :>=AB0=BK
- 5B>4 =5 A>45@68B ?>1>G=KE MDD5:B>2
- 5B>4 A>45@68B B>;L:> `return` statement

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
class Math {
    public static function add($a, $b) {
        return $a + $b;
    }

    public static function multiply($a, $b) {
        return $a * $b;
    }
}

$result = Math::add(Math::multiply(2, 3), 4);

// >A;5 B@0=AD>@<0F88 (H03 1: 2=CB@5==89 2K7>2)
$result = Math::add(2 * 3, 4);

// >A;5 B@0=AD>@<0F88 (H03 2: 2KG8A;5=85)
$result = Math::add(6, 4);

// >A;5 B@0=AD>@<0F88 (H03 3: 2=5H=89 2K7>2)
$result = 6 + 4;

// >A;5 B@0=AD>@<0F88 (H03 4: 2KG8A;5=85)
$result = 10;
```

**;3>@8B<:**
1. 09B8 >?@545;5=85 AB0B8G5A:>3> <5B>40
2. @>25@8BL >BACBAB285 ?>1>G=KE MDD5:B>2
3. 0<5=8BL ?0@0<5B@K 0@3C<5=B0<8
4. 0<5=8BL 2K7>2 ?@5>1@07>20==K< B5;><

** 50;870F8O:** "@51C5B =>2>3> Visitor 8;8 @0AH8@5=8O `CallFunctionVisitor`

**!B0BCA:** ó 5 @50;87>20=>

---

### @028;> STATIC-2: !B0B8G5A:85 <5B>4K A :>=AB0=B0<8 :;0AA0

**?8A0=85:** =;09=8=3 AB0B8G5A:8E <5B>4>2, 8A?>;L7CNI8E :>=AB0=BK :;0AA0.

**@8<5=8<> ::**
```php
class Name {
    const CONSTANT = value;

    public static function method() {
        return expression_with_self::CONSTANT;
    }
}
```

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
class Auth {
    const HASH_ALGO = 'sha256';

    public static function hash($data) {
        return hash(self::HASH_ALGO, $data);
    }
}

$hashed = Auth::hash('password');

// >A;5 B@0=AD>@<0F88 (H03 1: ?>4AB0=>2:0 :>=AB0=BK)
$hashed = Auth::hash('password');  // 2=CB@8 <5B>40: hash('sha256', $data)

// >A;5 B@0=AD>@<0F88 (H03 2: 8=;09= <5B>40)
$hashed = hash('sha256', 'password');

// >A;5 B@0=AD>@<0F88 (H03 3: 2KG8A;5=85 hash)
$hashed = '5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8';
```

** 50;870F8O:** ><18=0F8O `ConstClassVisitor` + =>2K9 Visitor 4;O AB0B8G5A:8E <5B>4>2

**!B0BCA:** ó 5 @50;87>20=>

---

## @028;0 4;O 70<K:0=89 8 AB@5;>G=KE DC=:F89

### @028;> CLOSURE-1: =;09=8=3 =5<54;5==> 2K7K205<KE 70<K:0=89 (IIFE)

**?8A0=85:** 0<5=0 =5<54;5==> 2K7K205<>3> 70<K:0=8O 53> B5;><.

**@8<5=8<> ::**
```php
$result = (function($params) {
    return expression;
})($args);
```

**#A;>28O:**
- 0<K:0=85 2K7K205BAO A@07C ?>A;5 >?@545;5=8O
- 0<K:0=85 =5 8A?>;L7C5B `use` 8;8 8A?>;L7C5B B>;L:> :>=AB0=BK
- 0<K:0=85 =5 A>45@68B ?>1>G=KE MDD5:B>2

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
$result = (function($x, $y) {
    return $x * $y + 10;
})(5, 3);

// >A;5 B@0=AD>@<0F88 (H03 1: ?>4AB0=>2:0 ?0@0<5B@>2)
$result = 5 * 3 + 10;

// >A;5 B@0=AD>@<0F88 (H03 2: 2KG8A;5=85)
$result = 25;
```

** 50;870F8O:** "@51C5B =>2>3> Visitor

**!B0BCA:** ó 5 @50;87>20=>

---

### @028;> CLOSURE-2: 0<K:0=8O A use

**?8A0=85:**  072Q@B:0 70<K:0=89, 70E20BK20NI8E ?5@5<5==K5 87 2=5H=53> A:>?0.

**@8<5=8<> ::**
```php
$var1 = value1;
$fn = function($param) use ($var1) {
    return expression_with_var1_and_param;
};
```

**#A;>28O:**
- A5 70E20G5==K5 ?5@5<5==K5 O2;ONBAO A:0;O@0<8 8;8 8725AB=K<8 7=0G5=8O<8
- 0<K:0=85 =5 87<5=O5B 70E20G5==K5 ?5@5<5==K5 (=5B `use (&$var)`)
- 0<K:0=85 2K7K205BAO 2 B>< 65 A:>?5

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
$multiplier = 10;
$fn = function($x) use ($multiplier) {
    return $x * $multiplier;
};
$result = $fn(5);

// >A;5 B@0=AD>@<0F88 (H03 1: ?>4AB0=>2:0 use ?5@5<5==KE)
$fn = function($x) {
    return $x * 10;
};
$result = $fn(5);

// >A;5 B@0=AD>@<0F88 (H03 2: 8=;09= 70<K:0=8O)
$result = 5 * 10;

// >A;5 B@0=AD>@<0F88 (H03 3: 2KG8A;5=85)
$result = 50;
```

**;3>@8B<:**
1. 0<5=8BL `use` ?5@5<5==K5 8E 7=0G5=8O<8 2 B5;5 70<K:0=8O
2. @8<5=8BL ?@028;> CLOSURE-1 4;O 8=;09=8=30

** 50;870F8O:** "@51C5B =>2>3> Visitor

**!B0BCA:** ó 5 @50;87>20=>

---

### @028;> ARROW-1: =;09=8=3 AB@5;>G=KE DC=:F89

**?8A0=85:** 0<5=0 2K7>20 AB@5;>G=>9 DC=:F88 5Q 2K@065=85<.

**@8<5=8<> ::**
```php
$fn = fn($params) => expression;
$result = $fn($args);
```

**#A;>28O:**
- !B@5;>G=0O DC=:F8O 2K7K205BAO 2 B>< 65 A:>?5
- A5 8A?>;L7C5<K5 2=5H=85 ?5@5<5==K5 8725AB=K
- K@065=85 =5 A>45@68B ?>1>G=KE MDD5:B>2

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
$multiplier = 10;
$fn = fn($x) => $x * $multiplier;
$result = $fn(5);

// >A;5 B@0=AD>@<0F88 (H03 1: ?>4AB0=>2:0 ?0@0<5B@>2 8 2=5H=8E ?5@5<5==KE)
$result = 5 * 10;

// >A;5 B@0=AD>@<0F88 (H03 2: 2KG8A;5=85)
$result = 50;
```

**A>15==>AB8:**
- !B@5;>G=K5 DC=:F88 02B><0B8G5A:8 70E20BK20NB 2A5 ?5@5<5==K5 87 2=5H=53> A:>?0
- 5 =C6=> O2=> C:07K20BL `use`
- A5340 A>45@60B B>;L:> >4=> 2K@065=85

** 50;870F8O:** "@51C5B =>2>3> Visitor

**!B0BCA:** ó 5 @50;87>20=>

---

### @028;> ARROW-2: !B@5;>G=K5 DC=:F88 2 array_map/array_filter

**?8A0=85:**  072Q@B:0 AB@5;>G=KE DC=:F89, 8A?>;L7C5<KE 2 DC=:F8OE 2KAH53> ?>@O4:0.

**@8<5=8<> ::**
```php
array_map(fn($x) => expression, $array)
array_filter(fn($x) => expression, $array)
```

**#A;>28O:**
- 0AA82 A>45@68B B>;L:> A:0;O@=K5 7=0G5=8O 8;8 8725AB5= ?>;=>ABLN
- K@065=85 2 AB@5;>G=>9 DC=:F88 =5 A>45@68B ?>1>G=KE MDD5:B>2

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
$numbers = [1, 2, 3, 4, 5];
$doubled = array_map(fn($x) => $x * 2, $numbers);

// >A;5 B@0=AD>@<0F88 (H03 1: @072Q@B:0 array_map)
$doubled = [
    1 * 2,
    2 * 2,
    3 * 2,
    4 * 2,
    5 * 2
];

// >A;5 B@0=AD>@<0F88 (H03 2: 2KG8A;5=85)
$doubled = [2, 4, 6, 8, 10];
```

** 50;870F8O:** "@51C5B =>2>3> Visitor

**!B0BCA:** ó 5 @50;87>20=>

---

## @028;0 4;O ?5@5<5==KE

### @028;> VAR-1: 0<5=0 ?5@5<5==>9 A:0;O@><

**?8A0=85:** 0<5=0 8A?>;L7>20=8O ?5@5<5==>9 5Q A:0;O@=K< 7=0G5=85<.

**@8<5=8<> ::**
```php
$var = scalar_value;
// ... 8A?>;L7>20=85 $var ...
```

**#A;>28O:**
- 5@5<5==0O ?@8A208205BAO >48= @07
- =0G5=85 O2;O5BAO A:0;O@><
- 5@5<5==0O =5 87<5=O5BAO ?>A;5 ?@8A20820=8O
- 5@5<5==0O =5 ?5@540QBAO ?> AAK;:5

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
$x = 5;
$y = 10;
$result = $x + $y;
echo $result;

// >A;5 B@0=AD>@<0F88 (H03 1: 70<5=0 $x 8 $y)
$result = 5 + 10;
echo $result;

// >A;5 B@0=AD>@<0F88 (H03 2: 2KG8A;5=85)
$result = 15;
echo $result;

// >A;5 B@0=AD>@<0F88 (H03 3: 70<5=0 $result)
echo 15;
```

** 50;870F8O:** `VarToScalarVisitor`

**!B0BCA:**   50;87>20=>

---

### @028;> VAR-2: #40;5=85 =58A?>;L7C5<KE ?5@5<5==KE

**?8A0=85:** #40;5=85 ?@8A20820=89 ?5@5<5==K<, :>B>@K5 =8345 =5 8A?>;L7CNBAO.

**@8<5=8<> ::**
```php
$var = expression;
// $var =8345 =5 8A?>;L7C5BAO
```

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
$x = 5;
$y = 10;
$unused = $x * 2;
echo $x + $y;

// >A;5 B@0=AD>@<0F88 (C40;5=85 $unused)
$x = 5;
$y = 10;
echo $x + $y;

// >A;5 40;L=59H8E B@0=AD>@<0F89
echo 15;
```

** 50;870F8O:** "@51C5B 0=0;870 8A?>;L7>20=8O ?5@5<5==KE

**!B0BCA:** ó 5 @50;87>20=>

---

### @028;> VAR-3: Null coalescing

**?8A0=85:** @5>1@07>20=85 `if (isset(...))` 2 null coalescing >?5@0B>@.

**@8<5=8<> ::**
```php
if (isset($var)) {
    $result = $var;
} else {
    $result = $default;
}
```

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
$username = 'default';
if (isset($_GET['username'])) {
    $username = $_GET['username'];
}

// >A;5 B@0=AD>@<0F88
$username = $_GET['username'] ?? 'default';
```

** 50;870F8O:** `BinaryAndIssetVisitor`

**!B0BCA:**   50;87>20=> (G0AB8G=>)

---

## @028;0 4;O <0AA82>2

### @028;> ARRAY-1: KG8A;5=85 AB0B8G5A:8E <0AA82>2

**?8A0=85:** KG8A;5=85 <0AA82>2 A :>=AB0=B=K<8 7=0G5=8O<8.

**@8<5=8<> ::**
```php
$array = [expression1, expression2, ...];
```

**#A;>28O:**
- A5 M;5<5=BK <0AA820 2KG8A;O5<K
- 0AA82 =5 87<5=O5BAO ?>A;5 A>740=8O

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
$numbers = [1 + 1, 2 * 2, 3 + 3];

// >A;5 B@0=AD>@<0F88
$numbers = [2, 4, 6];
```

** 50;870F8O:** "@51C5B =>2>3> Visitor

**!B0BCA:** ó 5 @50;87>20=>

---

### @028;> ARRAY-2: >ABC? : M;5<5=B0< :>=AB0=B=>3> <0AA820

**?8A0=85:** KG8A;5=85 `$array[key]` 4;O 8725AB=KE <0AA82>2.

**@8<5=8<> ::**
```php
$array = [known_values];
$value = $array[constant_key];
```

**#A;>28O:**
- 0AA82 ?>;=>ABLN 8725AB5=
- ;NG O2;O5BAO :>=AB0=B>9
- ;NG ACI5AB2C5B 2 <0AA825

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
$config = ['host' => 'localhost', 'port' => 3306];
$host = $config['host'];
$port = $config['port'];

// >A;5 B@0=AD>@<0F88
$host = 'localhost';
$port = 3306;
```

** 50;870F8O:** "@51C5B =>2>3> Visitor

**!B0BCA:** ó 5 @50;87>20=>

---

### @028;> ARRAY-3:  072Q@B:0 array_merge

**?8A0=85:** !;8O=85 :>=AB0=B=KE <0AA82>2.

**@8<5=8<> ::**
```php
$result = array_merge($array1, $array2);
```

**#A;>28O:**
- A5 <0AA82K 8725AB=K ?>;=>ABLN
- 0AA82K A>45@60B B>;L:> A:0;O@=K5 7=0G5=8O

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
$defaults = ['a' => 1, 'b' => 2];
$overrides = ['b' => 3, 'c' => 4];
$config = array_merge($defaults, $overrides);

// >A;5 B@0=AD>@<0F88
$config = ['a' => 1, 'b' => 3, 'c' => 4];
```

** 50;870F8O:** `EvalStandartFunction` (@0AH8@5=85)

**!B0BCA:** ó 5 @50;87>20=>

---

## @028;0 4;O CA;>289

### @028;> COND-1: KG8A;5=85 :>=AB0=B=KE CA;>289

**?8A0=85:** #40;5=85 <Q@B2KE 25B>: if/else.

**@8<5=8<> ::**
```php
if (constant_condition) {
    // branch1
} else {
    // branch2
}
```

**#A;>28O:**
- #A;>285 <>65B 1KBL 2KG8A;5=> 2 compile-time
- #A;>285 O2;O5BAO :>=AB0=B>9 (true/false)

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
$debug = false;
if ($debug) {
    echo "Debug info";
} else {
    echo "Production";
}

// >A;5 B@0=AD>@<0F88 (5A;8 $debug 8725AB5=)
echo "Production";
```

** 50;870F8O:** "@51C5B =>2>3> Visitor

**!B0BCA:** ó 5 @50;87>20=>

---

### @028;> COND-2: @5>1@07>20=85 if-else 2 B5@=0@=K9 >?5@0B>@

**?8A0=85:** #?@>I5=85 ?@>ABKE if-else :>=AB@C:F89.

**@8<5=8<> ::**
```php
if (condition) {
    return value1;
} else {
    return value2;
}
```

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
if ($x > 0) {
    return 'positive';
} else {
    return 'negative';
}

// >A;5 B@0=AD>@<0F88
return $x > 0 ? 'positive' : 'negative';
```

** 50;870F8O:** `TernarReturnVisitor`

**!B0BCA:**   50;87>20=>

---

### @028;> COND-3: #?@>I5=85 2;>65==KE CA;>289

**?8A0=85:** #?@>I5=85 2;>65==KE if/else.

**@8<5=8<> ::**
```php
if (condition1) {
    if (condition2) {
        return value1;
    }
}
return value2;
```

**"@0=AD>@<0F8O:**

```php
// AE>4=K9 :>4
if ($user !== null) {
    if ($user->isActive()) {
        return 'active';
    }
}
return 'inactive';

// >A;5 B@0=AD>@<0F88
return ($user !== null && $user->isActive()) ? 'active' : 'inactive';
```

** 50;870F8O:** "@51C5B =>2>3> Visitor

**!B0BCA:** ó 5 @50;87>20=>

---

## 3@0=8G5=8O 8 edge cases

### 1.  5:C@A8O

**@>1;5<0:**  5:C@A82=K5 DC=:F88 =5 <>3CB 1KBL @072Q@=CBK.

** 5H5=85:** 5 ?@8<5=OBL 8=;09=8=3 : @5:C@A82=K< DC=:F8O<.

**?@545;5=85 @5:C@A88:**
```php
function factorial($n) {
    if ($n <= 1) return 1;
    return $n * factorial($n - 1);  //  5:C@A82=K9 2K7>2
}
```

---

### 2. =>65AB25==K5 ?@8A20820=8O

**@>1;5<0:** 5@5<5==0O 87<5=O5BAO =5A:>;L:> @07.

** 5H5=85:** 5 70<5=OBL ?5@5<5==K5, :>B>@K5 87<5=ONBAO 1>;55 >4=>3> @070, 8;8 8A?>;L7>20BL SSA D>@<C.

**@8<5@:**
```php
$x = 5;
$x = $x + 1;  // 7<5=5=85
$x = $x * 2;  // IQ >4=> 87<5=5=85
```

---

### 3. 5@540G0 ?> AAK;:5

**@>1;5<0:** 0@0<5B@K/?5@5<5==K5 ?5@540NBAO ?> AAK;:5.

** 5H5=85:** 5 ?@8<5=OBL B@0=AD>@<0F88 : ?5@5<5==K<, ?5@540205<K< ?> AAK;:5.

**@8<5@:**
```php
function increment(&$x) {
    $x++;
}

$value = 5;
increment($value);  // $value AB0=5B 6
```

---

### 4. >1>G=K5 MDD5:BK 2 2K@065=8OE

**@>1;5<0:** K@065=85 A>45@68B 2K7>2K A ?>1>G=K<8 MDD5:B0<8.

** 5H5=85:** 5 2KG8A;OBL 2K@065=8O A ?>1>G=K<8 MDD5:B0<8.

**@8<5@:**
```php
// 5;L7O 2KG8A;8BL
$x = rand(1, 10);  // 545B5@<8=8@>20=>
$y = time();       // 028A8B >B 2@5<5=8
$z = $_GET['key']; // 028A8B >B 2=5H=53> A>AB>O=8O
```

---

### 5. 8=0<8G5A:85 2K7>2K

**@>1;5<0:** <O DC=:F88/<5B>40 >?@545;O5BAO 48=0<8G5A:8.

** 5H5=85:** 5 ?@8<5=OBL 8=;09=8=3 : 48=0<8G5A:8< 2K7>20<.

**@8<5@:**
```php
$funcName = 'myFunction';
$funcName();  // 8=0<8G5A:89 2K7>2

$methodName = 'myMethod';
$obj->$methodName();  // 8=0<8G5A:89 2K7>2 <5B>40
```

---

### 6. ;>10;L=K5 ?5@5<5==K5

**@>1;5<0:** $C=:F8O 8A?>;L7C5B 3;>10;L=K5 ?5@5<5==K5.

** 5H5=85:** 5 8=;09=8BL DC=:F88, 8A?>;L7CNI85 `global`.

**@8<5@:**
```php
$globalVar = 10;

function useGlobal() {
    global $globalVar;
    return $globalVar * 2;
}
```

---

### 7. 038G5A:85 <5B>4K 8 :>=AB0=BK

**@>1;5<0:** A?>;L7>20=85 `__METHOD__`, `__FUNCTION__`, `__CLASS__` 8 B.4.

** 5H5=85:** >@@5:B=> 70<5=OBL <038G5A:85 :>=AB0=BK ?@8 8=;09=8=35.

**@8<5@:**
```php
function myFunction() {
    return __FUNCTION__;  // >;6=> 25@=CBL 'myFunction'
}

// @8 8=;09=8=35 =C6=> 70<5=8BL =0 AB@>:C 'myFunction'
$name = 'myFunction';
```

---

### 8. &8:;K

**@>1;5<0:** $C=:F8O A>45@68B F8:;K.

** 5H5=85:** @8<5=OBL @072Q@B:C F8:;>2 B>;L:> 4;O ?@>ABKE A;CG052 A 8725AB=K< :>;8G5AB2>< 8B5@0F89.

**@8<5@ 157>?0A=>9 @072Q@B:8:**
```php
// AE>4=K9 :>4
$sum = 0;
for ($i = 1; $i <= 3; $i++) {
    $sum += $i;
}

// >A;5 @072Q@B:8
$sum = 0;
$sum += 1;
$sum += 2;
$sum += 3;

// >A;5 >?B8<870F88
$sum = 6;
```

---

### 9. A:;NG5=8O

**@>1;5<0:** >4 A>45@68B try-catch 1;>:8.

** 5H5=85:** 5 ?@8<5=OBL 03@5AA82=CN >?B8<870F8N : :>4C A >1@01>B:>9 8A:;NG5=89.

**@8<5@:**
```php
try {
    $result = riskyOperation();
} catch (Exception $e) {
    $result = 'error';
}
// 5;L7O ?@54A:070BL @57C;LB0B
```

---

### 10. 0A;54>20=85 8 ?>;8<>@D87<

**@>1;5<0:** 5B>4 ?5@5>?@545;O5BAO 2 4>G5@=8E :;0AA0E.

** 5H5=85:** 5 8=;09=8BL <5B>4K, :>B>@K5 <>3CB 1KBL ?5@5>?@545;5=K (=5 final, =5 private).

**@8<5@:**
```php
class Base {
    public function method() {  // >65B 1KBL ?5@5>?@545;Q=
        return 'base';
    }
}

class Child extends Base {
    public function method() {
        return 'child';
    }
}

$obj = getObject();  // 58725AB=>, Base 8;8 Child
$result = $obj->method();  // 5;L7O 8=;09=8BL
```

---

## 5B@8:8 ?@8<5=8<>AB8 ?@028;

;O :064>3> ?@028;0 >?@545;O5BAO:

1. **57>?0A=>ABL** (Safety): =0A:>;L:> 157>?0A=> ?@8<5=OBL ?@028;>
   - =â 57>?0A=>: @57C;LB0B 30@0=B8@>20==> M:2820;5=B5=
   - =á #A;>2=> 157>?0A=>: 157>?0A=> ?@8 A>1;N45=88 CA;>289
   - =4 5157>?0A=>: <>65B 87<5=8BL A5<0=B8:C

2. **'0AB>B0 ?@8<5=5=8O** (Frequency): :0: G0AB> 2AB@5G05BAO 2 @50;L=>< :>45
   - =% G5=L G0AB> (>50% :>40)
   - < '0AB> (20-50% :>40)
   - P  54:> (<20% :>40)

3. **;8O=85 =0 @07<5@** (Impact): =0A:>;L:> C<5=LH05BAO :>4
   - =É=É=É =0G8B5;L=>5 C<5=LH5=85 (>30%)
   - =É=É !@54=55 C<5=LH5=85 (10-30%)
   - =É 51>;LH>5 C<5=LH5=85 (<10%)

### !2>4=0O B01;8F0

| @028;> | 57>?0A=>ABL | '0AB>B0 | ;8O=85 | @8>@8B5B |
|---------|-------------|---------|---------|-----------|
| CONST-1 | =â | =% | =É=É | 1 |
| CONST-2 | =â | < | =É=É | 2 |
| FUNC-1 | =á | =% | =É=É=É | 1 |
| FUNC-2 | =á | < | =É=É=É | 2 |
| FUNC-3 | =â | =% | =É | 1 |
| METHOD-1 | =á | < | =É=É=É | 2 |
| METHOD-2 | =á | P | =É=É | 3 |
| CTOR-1 | =á | < | =É=É=É | 2 |
| CTOR-2 | =á | < | =É=É | 3 |
| STATIC-1 | =á | < | =É=É | 2 |
| STATIC-2 | =á | P | =É | 3 |
| CLOSURE-1 | =â | P | =É=É | 3 |
| CLOSURE-2 | =á | P | =É=É | 3 |
| ARROW-1 | =â | < | =É=É | 2 |
| ARROW-2 | =â | P | =É=É | 3 |
| VAR-1 | =â | =% | =É=É | 1 |
| VAR-2 | =â | < | =É | 2 |
| VAR-3 | =â | =% | =É | 1 |
| ARRAY-1 | =â | < | =É | 2 |
| ARRAY-2 | =â | < | =É=É | 2 |
| ARRAY-3 | =â | P | =É | 3 |
| COND-1 | =â | < | =É=É | 2 |
| COND-2 | =â | =% | =É | 1 |
| COND-3 | =á | P | =É=É | 3 |

**@8>@8B5B:**
- 1: @8B8G=K5 ?@028;0, 4>;6=K 1KBL @50;87>20=K 2 ?5@2CN >G5@54L
- 2: 06=K5 ?@028;0, @50;87CNBAO 2> 2B>@CN >G5@54L
- 3: >?>;=8B5;L=K5 ?@028;0, @50;87CNBAO ?> <5@5 =5>1E>48<>AB8

---

## 0:;NG5=85

-B>B 4>:C<5=B >?8AK205B D>@<0;L=K5 ?@028;0 @072Q@B:8 PHP :>40. @8 @50;870F88 =>2KE ?@028; =5>1E>48<>:

1. >1028BL >?8A0=85 ?@028;0 2 MB>B 4>:C<5=B
2.  50;87>20BL Visitor 4;O ?@8<5=5=8O ?@028;0
3. 0?8A0BL unit-B5ABK 4;O ?@028;0
4. >1028BL feature-B5ABK =0 @50;L=KE ?@8<5@0E
5. 1=>28BL <0B@8FC B@0=AD>@<0F89 2 docs/AST.md

A5 ?@028;0 4>;6=K AB@>3> A;54>20BL ?@8=F8?0< 157>?0A=>AB8 8 A>E@0=5=8O A5<0=B8:8 :>40.
