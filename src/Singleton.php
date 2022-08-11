<?php

namespace emteknetnz\TypeTransitioner;

class Singleton
{
    private static $instances = [];

    // protected constructor to prevent use of 'new'
    protected function __construct()
    {
    }

    // protected clone method to prevent cloning
    protected function __clone()
    {
    }

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    /**
     * static return type not supported until php 8.0
     *
     * @return static The singleton instance
     */
    #[\ReturnTypeWillChange]
    public static function getInstance()
    {
        $class = static::class;
        if (!isset(self::$instances[$class])) {
            self::$instances[$class] = new static();
        }
        return self::$instances[$class];
    }
}
