<?php

namespace emteknetnz\TypeTransitioner\Config;

// TODO: something better that reads from silverstripe yml config

final class Config
{
    public const CAST_NULL = 'CAST_NULL';
    public const TRIGGER_USER_DEPRECATED = 'TRIGGER_USER_DEPRECATED';
    public const THROW_EXCEPTION = 'THROW_EXCEPTION';

    private $config = [
        self::CAST_NULL => false,
        self::TRIGGER_USER_DEPRECATED => false,
        self::THROW_EXCEPTION => false,
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
