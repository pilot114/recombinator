<?php

# Прочее
declare (strict_types=1);

# Вывод
echo 'Admitted: 3' . PHP_EOL . ('rex says Woof! (canidae)' . PHP_EOL) . ('milo says Woof! (canidae)' . PHP_EOL) . ('Whiskers purrs softly...
' . ('Dog #0: Rex' . PHP_EOL . ('Dog #1: Milo' . PHP_EOL)) . ('Total: 3' . PHP_EOL)) . ('Split: 7/8' . PHP_EOL . 'tick 4
tick 2
') . ("greater" . PHP_EOL);

# Вычисления
print 'is animal' . PHP_EOL;

# Вывод
echo <<<'NOWDOC'
no $interpolation here, literal {$placeholder}
NOWDOC . PHP_EOL . (42 . PHP_EOL) . (42 . PHP_EOL) . ('Whiskers the cat, age 7' . PHP_EOL) . ('Whiskers performs jump' . PHP_EOL) . ('Result: null' . PHP_EOL) . ('Rex the dog, age 4' . PHP_EOL) . ('>> tagged' . PHP_EOL) . (15 . PHP_EOL) . ('empty' . PHP_EOL) . ('Berlin' . PHP_EOL) . ('Rex II the dog, age 4' . PHP_EOL . 'terminal' . PHP_EOL . ('--- Shelter log ---

--- end ---' . PHP_EOL));