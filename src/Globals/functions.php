<?php

use emteknetnz\TypeTransitioner\Config\Config;
use emteknetnz\TypeTransitioner\Exceptions\TypeException;

global $_tt_method_cache;
$_tt_method_cache = [];

/**
 * Note: mixed static types are only availabe from php8.0
 * 
 * @param mixed $arg
 * @param string $type
 * @return mixed
 */
// function _a(string $castType, &$_arg)
function _a()
{
    global $_tt_method_cache;

    $outdir = str_replace('//', '/', BASE_PATH . '/artifacts');
    if (!file_exists($outdir)) {
        mkdir($outdir);
    }
    $outpath = "$outdir/tt.txt";

    $TYPE_DYNAMIC = 'type-dynamic'; // php7 type is blank
    $TYPE_MISSING = 'type-missing'; // dockblock return type is missing
    $WHERE_MISSING = 'where-missing'; // php7 type is blank and docblock type is missing

    $d = debug_backtrace(0, 2);
    $file = $d[0]['file'] ?? '';
    $line = $d[0]['line'] ?? '';
    $class = $d[1]['class'] ?? '';
    $callType = $d[1]['type'] ?? '';
    $method = $d[1]['function'] ?? '';
    $args = $d[1]['args'] ?? [];
    print_r([$file, $line, $class, $callType, $method, $args]);

    if (!$class || !$method) {
        echo 'No class or method';die;
    }

    $key = $class . '::' . $method;
    if (!array_key_exists($key, $_tt_method_cache)) {
        // https://www.php.net/manual/en/book.reflection.php
        $reflClass = new ReflectionClass($class);
        $reflMethod = $reflClass->getMethod($method);
        
        $reflDocblock = $reflMethod->getDocComment();
        $reflParams = $reflMethod->getParameters();
        $reflReturn = $reflMethod->getReturnType();

        preg_match_all('#@param +([^ ]+)[ \t]+((?:\$)[A-Za-z0-9]+)#', $reflDocblock, $m);
        $docblockParams = array_combine($m[2], $m[1]);
        $methodParams = array_combine(
            array_map(fn($p) => '$' . $p->getName(), $reflParams),
            array_map(fn($p) => $p->hasType() ? $p->getType()->getName() : $TYPE_DYNAMIC, $reflParams)
        );
        
        preg_match('#@return ([^ ]+)#', $reflDocblock, $m);
        $docblockReturn = $m[1] ?? $TYPE_MISSING;
        $methodReturn = $reflReturn ? $reflReturn->getName() : '';

        $_tt_method_cache[$key] = [
            'docblockParams' => $docblockParams,
            'methodParams' => $methodParams,
            'docblockReturn' => $docblockReturn,
            'methodReturn' => $methodReturn,
        ];
    }
    $o = $_tt_method_cache[$key];
    $vars = array_keys($o['methodParams']);
    for ($i = 0; $i < count($vars); $i++) {
        $var = $vars[$i];
        $arg = $args[$i];
        $paramType = $o['methodParams'][$var];
        $paramWhere = 'method';
        if ($paramType == $TYPE_DYNAMIC) {
            if (array_key_exists($var, $o['docblockParams'])) {
                $paramType = $o['docblockParams'][$var];
                $paramWhere = 'docblock';
            } else {
                $paramWhere = $WHERE_MISSING;
            }
        }
        if (is_null($arg)) {
            $argType = 'null';
        } elseif (is_object($arg)) {
            $argType = get_class($arg);
        } elseif (is_string($arg)) {
            $argType = 'string';
        } elseif (is_int($arg)) {
            $argType = 'int';
        } elseif (is_float($arg)) {
            $argType = 'float';
        } elseif (is_array($arg)) {
            $argType = 'array';
        } else {
            $argType = 'unknown';
        }
        if ($argType == $paramType) {
            continue;
        }
        $line = implode(',', [$file, $line, $class, $method, $var, $paramWhere, $paramType, $argType, $arg]);
        file_put_contents($outpath, "$line\n", FILE_APPEND);
    }

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
