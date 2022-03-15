<?php

namespace emteknetnz\TypeTransitioner\Config;

// TODO: something better that reads from silverstripe yml config

final class Config
{
    public const CAST_NULL = 'CAST_NULL';
    public const TRIGGER_E_USER_DEPRECATED = 'TRIGGER_E_USER_DEPRECATED';
    public const THROW_TYPE_EXCEPTION = 'THROW_TYPE_EXCEPTION';

    private $config = [
        self::CAST_NULL => false,
        self::TRIGGER_E_USER_DEPRECATED => true,
        self::THROW_TYPE_EXCEPTION => false,
    ];

    private static ?Config $inst = null;

    public static function inst(): self
    {
        if (is_null(self::$inst)) {
            self::$inst = new self();
        }
        return self::$inst;
    }
    public static function get(string $key): bool
    {
        return self::inst()->getConfig()[$key];
    }

    public static function set(string $key, bool $value): void
    {
        self::inst()->getConfig()[$key] = $value;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

}
