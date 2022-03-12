<?php

use emteknetnz\TypeTransitioner\Config\Config;
use emteknetnz\TypeTransitioner\Exceptions\TypeException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Manifest\ClassManifest;

function _scan_methods()
{
    $out = shell_exec('find ' . BASE_PATH . '/vendor/silverstripe/framework | grep .php$');
    $paths = explode("\n", $out);
    foreach ($paths as $path) {
        if (strpos($path, '/src/') === false && strpos($path, '/code/') === false) {
            continue;
        }
        if (strpos($path, '/tests/') !== false) {
            continue;
        }
        $contents = file_get_contents($path);
        $namespace = '';
        if (preg_match('#[\r\n]namespace (.+?);#', $contents, $m)) {
            $namespace = $m[1];
        }
        $class = '';
        if (preg_match('#[\r\n]class (.+?)[ \r\n]#', $contents, $m)) {
            $class = $m[1];
        } else {
            echo "Could not find class for path $path\n";
            continue;
        }
        $fqcn = "$namespace\\$class";
        $reflClass = new ReflectionClass($fqcn);
        $methodData = [];
        foreach ($reflClass->getMethods() as $reflMethod) {
            $name = $reflMethod->getName();
            // ReflectionClass::getMethods() sorts the methods by class (lowest in the inheritance tree first)
            // so as soon as we find an inherited method not in the $contents, we can break
            if (strpos($contents, "function $name") === false) {
                break;
            }
            $methodData[] = _ett_get_method_data($reflClass, $reflMethod);
        }
        // only include methods with dynamic params or a dynamic return type
        $methodData = array_filter($methodData, function (array $data) {
            $hasDynamicParams = !empty(array_filter($data['methodParams'], fn(string $type) => $type == 'dynamic'));
            // TODO:
            // return $hasDynamicParams || $o['methodReturn'] == 'dynamic';
            return $hasDynamicParams;
        });
        // print_r($methodData);
        /** @var array $m */
        foreach ($methodData as $data) {
            $method = $data['method'];
            preg_match("#(?s)(function {$method}.+)#", $contents, $m);
            $fncontents = $m[1];
            preg_match('#( *){#', $fncontents, $m2);
            $indent = $m2[1];
            $newfncontents = preg_replace('#{#', "{\n$indent    _a('dynamic', 'test');", $fncontents, 1);
            $contents = str_replace($fncontents, $newfncontents, $contents);
        }

        echo $contents;
        die;
    }
    die;
}

// This should be a static method on a utility class or similar
function _ett_get_method_data(ReflectionClass $reflClass, ReflectionMethod $reflMethod)
{
    // https://www.php.net/manual/en/book.reflection.php
    $reflDocblock = $reflMethod->getDocComment();
    $reflParams = $reflMethod->getParameters();
    $reflReturn = $reflMethod->getReturnType();

    preg_match_all('#@param +([^ ]+)[ \t]+((?:\$)[A-Za-z0-9]+)#', $reflDocblock, $m);
    $docblockParams = array_combine($m[2], $m[1]);
    $methodParams = array_combine(
        array_map(fn($p) => '$' . $p->getName(), $reflParams),
        array_map(fn($p) => $p->hasType() ? $p->getType()->getName() : 'dynamic', $reflParams)
    );

    preg_match('#@return ([^ ]+)#', $reflDocblock, $m);
    $docblockReturn = $m[1] ?? 'missing';
    $methodReturn = $reflReturn ? $reflReturn->getName() : 'dynamic';

    return [
        'namespace' => $reflClass->getNamespaceName(),
        'class' => $reflClass->getName(),
        'method' => $reflMethod->getName(),
        'docblockParams' => $docblockParams,
        'methodParams' => $methodParams,
        'docblockReturn' => $docblockReturn,
        'methodReturn' => $methodReturn,
    ];
}

global $_ett_method_cache;
$_ett_method_cache = [];

global $_ett_lines;
$_ett_lines = [];

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
    global $_ett_method_cache, $_ett_lines;

    $outdir = str_replace('//', '/', BASE_PATH . '/artifacts');
    if (!file_exists($outdir)) {
        mkdir($outdir);
    }
    $outpath = "$outdir/ett.txt";

    $TYPE_DYNAMIC = 'type-dynamic'; // php7 type is blank
    $TYPE_MISSING = 'type-missing'; // dockblock return type is missing
    $WHERE_MISSING = 'where-missing'; // php7 type is blank and docblock type is missing

    $d = debug_backtrace(0, 2);

    $callingFile = $d[1]['file'] ?? '';
    $callingLine = $d[1]['line'] ?? '';
    $calledClass = $d[1]['class'] ?? '';
    // $callType = $d[1]['type'] ?? '';
    $calledMethod = $d[1]['function'] ?? '';
    $args = $d[1]['args'] ?? [];
    // print_r([
    //     $callingFile,
    //     $callingLine,
    //     // $calledLine,
    //     $calledClass,
    // //    $callType,
    //     $calledMethod,
    //     $args
    // ]);

    if (!$calledClass || !$calledMethod) {
        return;
        echo 'No class or method';die;
    }

    $key = $calledClass . '::' . $calledMethod;
    if (!array_key_exists($key, $_ett_method_cache)) {

        try {
            $reflClass = new ReflectionClass($calledClass);
            $reflMethod = $reflClass->getMethod($calledMethod);
            $_tt_method_cache[$key] = _ett_get_method_data($reflClass, $reflMethod);
        } catch (Exception $e) {
            echo "Exception when doing Refelction\n";
            return;
        }
    }
    $o = $_tt_method_cache[$key];
    $vars = array_keys($o['methodParams']);
    for ($i = 0; $i < count($vars); $i++) {
        $var = $vars[$i];
        $arg = $args[$i] ?? null;
        $paramType = $o['methodParams'][$var];
        if ($paramType != $TYPE_DYNAMIC) {
            // strongly typed method params are a non-issue
            continue;
        }
        $paramWhere = 'method';
        if (array_key_exists($var, $o['docblockParams'])) {
            $paramType = $o['docblockParams'][$var];
            if (is_object($arg) && strpos($paramType, '\\') === false && $o['namespace']) {
                // convert to FQCN
                $paramType = $o['namespace'] . '\\' . $paramType;
            }
            $paramWhere = 'docblock';
        } else {
            $paramWhere = $WHERE_MISSING;
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
        if ($paramWhere == 'docblock' && is_object($arg)) {
            // is the docblock type an interface or parent class?
            $subclasses = ClassInfo::subclassesFor($paramType);
            echo "Subclasses are:\n";
            var_dump($paramType);
            print_r($subclasses);die;
        }
        // headers set in /app/_config.php
        $line = implode(',', [$callingFile, $callingLine, $calledClass, $calledMethod, $var, $paramWhere, $paramType, $argType]);
        if (array_key_exists($line, $_ett_lines)) {
            continue;
        }
        $_ett_lines[$line] = true;
        file_put_contents($outpath, "$line\n", FILE_APPEND);
    }

    // return;

    // // TODO: get call stack so can provide useful exception data
    // $config = Config::inst();
    // if ($config->get(Config::LOG)) {

    // }
    // if ($config->get(Config::THROW_EXCEPTION)) {
    //     if ($type === 'string' && !is_string($arg)) {
    //         throw new TypeException(sprintf('%s is not a string', $arg));
    //     }
    // }
    // if ($config->get(Config::CAST)) {
    //     if ($type === 'string') {
    //         return (string) $arg;
    //     }
    // }
}
