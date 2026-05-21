<?php

declare(strict_types=1);

namespace SyntaxZoo;

use Closure;
use RuntimeException;
use function implode;
use function sprintf;
use function strtoupper;

class ShelterException extends RuntimeException
{
}

final class Logger
{
    /** @var list<string> */
    private static array $entries = [];

    public static function log(string $level, string $msg): void
    {
        static::$entries[] = sprintf('[%s] %s', strtoupper($level), $msg);
    }

    public static function dump(): string
    {
        $body = implode("\n", static::$entries);
        return <<<LOG
            --- Shelter log ---
            $body
            --- end ---
            LOG;
    }

    public static function nowdocExample(): string
    {
        return <<<'NOWDOC'
            no $interpolation here, literal {$placeholder}
            NOWDOC;
    }

    public static function guarded(Closure $action): mixed
    {
        try {
            return $action();
        } catch (ShelterException $e) {
            self::log('error', $e->getMessage());
            return null;
        } finally {
            self::log('info', 'guarded call completed');
        }
    }

    public static function panic(string $reason): never
    {
        throw new ShelterException($reason);
    }
}
