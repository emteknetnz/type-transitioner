<?php

namespace emteknetnz\TypeTransitioner;

// TODO: read from silverstripe yml config, probably get rid of this class

final class Config extends Singleton
{
    public const CAST_NULL = 'CAST_NULL';
    public const TRIGGER_E_USER_DEPRECATED = 'TRIGGER_E_USER_DEPRECATED';
    public const THROW_TYPE_EXCEPTION = 'THROW_TYPE_EXCEPTION';
    public const CODE_UPDATE_A = 'CODE_WRITE_A';
    public const CODE_UPDATE_C = 'CODE_UPDATE_C';

    private $config = [
        self::CAST_NULL => false,
        self::TRIGGER_E_USER_DEPRECATED => false,
        self::THROW_TYPE_EXCEPTION => false,
        self::CODE_UPDATE_A => true, // <<<
        self::CODE_UPDATE_C => false,
    ];

    public static function get(string $key): bool
    {
        return self::getInstance()->getConfig()[$key];
    }

    public static function set(string $key, bool $value): void
    {
        self::getInstance()->getConfig()[$key] = $value;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

}
