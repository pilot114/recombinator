<?php

namespace Recombinator;

/**
 * Класс для обмена данными между разными скопами в рамках одного Visitor
 */
class ScopeStore
{
    public $functions = [];
    public $scopes = [];
    public $currentScope;

    public function setVarToScope($name, $value)
    {
        if (!isset($this->scopes[$this->currentScope])) {
            $this->scopes[$this->currentScope] = [
                'vars' => []
            ];
        }
        $this->scopes[$this->currentScope]['vars'][$name] = $value;
    }
    public function getVarFromScope($name)
    {
        return $this->scopes[$this->currentScope]['vars'][$name] ?? null;
    }
    public function removeVarFromScope($name)
    {
        unset($this->scopes[$this->currentScope]['vars'][$name]);
    }


    public function setConstToScope($name, $value)
    {
        if (!isset($this->scopes[$this->currentScope])) {
            $this->scopes[$this->currentScope] = [
                'consts' => []
            ];
        }
        $this->scopes[$this->currentScope]['consts'][$name] = $value;
    }
    public function getConstFromScope($name)
    {
        return $this->scopes[$this->currentScope]['consts'][$name] ?? null;
    }
}