<?php

namespace Recombinator;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeDumper;

// maybe TODO
class YamlDumper extends NodeDumper
{
    protected function red($text) {
        return "\e[31m".$text."\033[0m";
    }
    protected function yellow($text) {
        return "\e[33m".$text."\033[0m";
    }
    protected function blue($text) {
        return "\e[36m".$text."\033[0m";
    }
    protected function green($text) {
        return "\e[32m".$text."\033[0m";
    }
}
