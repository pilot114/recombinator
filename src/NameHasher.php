<?php

namespace Recombinator;

class NameHasher
{
    static public $ids;

    /**
     * For diff context need generate diff hashes
     * for the same identifers
     *
     * @param string $identifer
     * @param string $context class__method
     */
    static public function hash($identifer, $context = null)
    {
        if ($context) {
            return sprintf('%s__%s', $context, $identifer);
        }
        return $identifer;
    }
}