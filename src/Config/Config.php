<?php

namespace emteknetnz\TypeTransitioner\Config;

// TODO: something better that reads from silverstripe yml config

final class Config
{
    public const LOG = 'LOG';
    public const THROW_EXCEPTION = 'THROW_EXCEPTION';
    public const CAST = 'CAST';

    private $config = [
        self::LOG => false,
        self::THROW_EXCEPTION => false,
        self::CAST => false,
    ];

    private static ?Config $inst = null;

    public static function inst(): self
    {
        if (is_null(self::$inst)) {
            self::$inst = new self();
        }
        return self::$inst;
    }

    public function get(string $key): bool
    {
        return self::$config[$key];
    }

    public function set(string $key, bool $value): void
    {
        self::$config[$key] = $value;
    }
}
