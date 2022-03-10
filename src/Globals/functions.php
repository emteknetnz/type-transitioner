<?php

use emteknetnz\TypeTransitioner\Config\Config;
use emteknetnz\TypeTransitioner\Exceptions\TypeException;

/**
 * Note: mixed static types are only availabe from php8.0
 * 
 * @param mixed $arg
 * @param string $type
 * @return mixed
 */
function _a($arg, string $type = 'unknown')
{
    $NO_TYPE = '(none)';

    $d = debug_backtrace(0, 2);
    $file = $d[0]['file'] ?? '';
    $line = $d[0]['line'] ?? '';
    $class = $d[1]['class'] ?? '';
    $type = $d[1]['type'] ?? '';
    $method = $d[1]['function'] ?? '';
    $args = $d[0]['args'] ?? [];
    print_r([$file, $line, $class, $type, $method, $args]);

    if (!$class || !$method) {
        echo 'No class or method';die;
    }

    // https://www.php.net/manual/en/book.reflection.php
    $refClass = new ReflectionClass($class);
    $refMethod = $refClass->getMethod($method);
    
    $refDocblock = $refMethod->getDocComment();
    $refParams = $refMethod->getParameters();
    $refReturn = $refMethod->getReturnType();

    preg_match_all('#@param +([^ ]+)[ \t]+((?:\$)[A-Za-z0-9]+)#', $refDocblock, $m);

    $docNames = $m[2];
    $docTypes = $m[1];
    $actualNames = array_map(fn($p) => '$' . $p->getName(), $refParams);
    $actualTypes = array_map(fn($p) => $p->hasType() ? $p->getType()->getName() : $NO_TYPE, $refParams);
    $docParams = array_combine($docNames, $docTypes);
    $actualParams = array_combine($actualNames, $actualTypes);

    preg_match('#@return ([^ ]+)#', $refDocblock, $m);
    $docReturn = $m[1] ?? '';

    print_r([
        'docParams' => $docParams,
        'actualParams' => $actualParams,
        'docReturn' => $docReturn,
        'actualReturn' => $refReturn ? $refReturn->getName() : '',
    ]);
    die;

    // TODO: get call stack so can provide useful exception data
    $config = Config::inst();
    if ($config->get(Config::LOG)) {

    }
    if ($config->get(Config::THROW_EXCEPTION)) {
        if ($type === 'string' && !is_string($arg)) {
            throw new TypeException(sprintf('%s is not a string', $arg));
        }
    }
    if ($config->get(Config::CAST)) {
        if ($type === 'string') {
            return (string) $arg;
        }
    }
}
