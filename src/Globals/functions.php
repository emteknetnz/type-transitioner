<?php

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
    // TODO: get call stack so can provide useful exception data
    $doLog = false;
    $doThrowException = true;
    $doCast = false;
    if ($doThrowException) {
        if ($type === 'string' && !is_string($arg)) {
            throw new TypeException(sprintf('%s is not a string', $arg));
        }
    }
    if ($doCast) {
        if ($type === 'string') {
            return (string) $arg;
        }
    }
    return $arg;
}
