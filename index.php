<?php

require './vendor/autoload.php';

use Recombinator\Runner;

$code = file_get_contents('./tests/code/index.php');
echo Runner::flatting($code);
