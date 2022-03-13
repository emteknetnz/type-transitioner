<?php

use emteknetnz\TypeTransitioner\Config\Config;
use emteknetnz\TypeTransitioner\Exceptions\TypeException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Manifest\ClassManifest;

// this will get included twice
if (!function_exists('_scan_methods')) {

    function _analyse_data()
    {
        $lines = explode("\n", file_get_contents(BASE_PATH . '/artifacts/ett.txt'));
        // remove header
        array_shift($lines);

        $res = [];

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            $data = explode(',', $line);
            list(
                $callingFile,
                $callingLine,
                $calledClass,
                $calledMethod,
                $var,
                $paramWhere,
                $paramType,
                $argType
            ) = $data;
            // dockblock param is mixed, so arg can be anything
            if ($paramType == 'mixed') {
                continue;
            }
            // see if docblock param matched arg
            $match = false;
            foreach (explode('|', $paramType) as $pType) {
                // paramType (docblock) is usually not a FQCN
                $pType = preg_replace('#[^A-Za-z0-9_]#', '', $pType);
                if ($pType == $argType || preg_match("#\\{$pType}$#", $argType)) {
                    $match = true;
                    break;
                }
            }
            if ($match) {
                continue;
            }
            // dockblock param (include undocblocked param) does not match arg
            $param = "({$paramType}) {$var}";
            $res[$calledClass][$calledMethod][$param] ??= [];
            $call = "({$argType}) {$callingFile}:{$callingLine}";
            if (!in_array($call, $res[$calledClass][$calledMethod][$param])) {
                $res[$calledClass][$calledMethod][$param][] = $call;
            }
        }
        print_r($res);die;
        die;
    }

    function _scan_methods()
    {
        $path = __DIR__ . '/../../../../silverstripe/framework/src/includes/constants.php';
        $contents = file_get_contents($path);
        if (strpos($contents, 'emteknetnz') === false) {
            $s = "require_once __DIR__ . '/functions.php';";
            $r ="require_once __DIR__ . '/../../../../emteknetnz/type-transitioner/src/Globals/functions.php';";
            file_put_contents($path, str_replace($s, "$r\n$s", $contents));
        }
        require_once __DIR__ . '/../../../../emteknetnz/type-transitioner/src/Globals/functions.php';
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
            if (strpos($contents, ' _a(') !== false) {
                continue;
            }
            $namespace = '';
            if (preg_match('#\nnamespace (.+?);#', $contents, $m)) {
                $namespace = $m[1];
            }
            $class = '';
            if (preg_match('#(\nabstract |\n)class (.+?)[ \n]#', $contents, $m)) {
                $class = $m[2];
            } else {
                // echo "Could not find class for path $path\n";
                continue;
            }
            $fqcn = "$namespace\\$class";
            // if (in_array($fqcn, [
            //     'SilverStripe\Core\Environment',
            //     'SilverStripe\Core\EnvironmentLoader',
            //     'SilverStripe\Core\TempFolder',
            //     'SilverStripe\Core\Path',
            // ])) {
            //     // skip as this will happen before functions.php is loaded, so _a() is not available
            //     continue;
            // }
            if ($class == 'HTTPRequest') {
                $a=1;
            }
            $reflClass = new ReflectionClass($fqcn);
            $methodData = [];
            foreach ($reflClass->getMethods() as $reflMethod) {
                $name = $reflMethod->getName();
                // ReflectionClass::getMethods() sorts the methods by class (lowest in the inheritance tree first)
                // so as soon as we find an inherited method not in the $contents, we can break
                if (!preg_match("#function {$name} ?\(#", $contents)) {
                    break;
                }
                $methodData[] = _ett_get_method_data($reflClass, $reflMethod);
            }
            // only include methods with dynamic params or a dynamic return type
            $methodData = array_filter($methodData, function (array $data) {
                if ($data['abstract']) {
                    return false;
                }
                $hasDynamicParams = !empty(array_filter($data['methodParams'], fn(string $type) => $type == 'dynamic'));
                // TODO:
                // return $hasDynamicParams || $o['methodReturn'] == 'dynamic';
                return $hasDynamicParams;
            });
            // print_r($methodData);
            /** @var array $m */
            $changed = false;
            foreach ($methodData as $data) {
                $as = [];
                foreach ($data['methodParams'] as $var => $type) {
                    if ($type != 'dynamic') {
                        continue;
                    }
                    // TODO: change to $isSpecial - default value, variadic or is reference
                    $hasDefault = $data['methodParamsHasDefault'][$var] ? ', true' : '';
                    $docblockType = $data['docblockParams'][$var] ?? 'dynamic';
                    $as[] = "_a('{$docblockType}', {$var}{$hasDefault});";
                }
                $method = $data['method'];
                $b = preg_match("#(?s)(function {$method} ?\(.+)#", $contents, $m);
                $fncontents = $m[1];
                preg_match('#( *){#', $fncontents, $m2);
                $indent = $m2[1];
                $aStr = implode("\n{$indent}    ", $as);
                $newfncontents = preg_replace('#{#', "{\n$indent    $aStr", $fncontents, 1);
                $contents = str_replace($fncontents, $newfncontents, $contents);
                $changed = true;
            }
            if (!$changed) {
                continue;
            }
            file_put_contents($path, $contents);
        }
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
            array_map(function($p) {
                if ($p->isVariadic()) {
                    // ... splat operator
                    return 'variadic';
                }
                if ($p->hasType()) {
                    return $p->getType()->getName();
                }
                return 'dynamic';
            }, $reflParams)
        );
        $methodParamsHasDefault = array_combine(
            array_map(fn($p) => '$' . $p->getName(), $reflParams),
            array_map(fn($p) => $p->isDefaultValueAvailable(), $reflParams)
        );
        $methodParamsIsReference = array_combine(
            array_map(fn($p) => '$' . $p->getName(), $reflParams),
            array_map(fn($p) => $p->isPassedByReference(), $reflParams)
        );

        preg_match('#@return ([^ ]+)#', $reflDocblock, $m);
        $docblockReturn = $m[1] ?? 'missing';
        $methodReturn = $reflReturn ? $reflReturn->getName() : 'dynamic';

        return [
            'namespace' => $reflClass->getNamespaceName(),
            'class' => $reflClass->getName(),
            'method' => $reflMethod->getName(),
            'abstract' => $reflMethod->isAbstract(),
            'docblockParams' => $docblockParams,
            'methodParams' => $methodParams,
            'methodParamsHasDefault' => $methodParamsHasDefault,
            'methodParamsIsReference' => $methodParamsIsReference,
            'docblockReturn' => $docblockReturn,
            'methodReturn' => $methodReturn,
        ];
    }

    global $_ett_method_cache;
    $_ett_method_cache = [];

    global $_ett_lines;
    $_ett_lines = [];

     // TODO ensure $paramIsVariadic is populated when writing _a() calls
    function _a($type, &$_arg, $paramHasDefault = false, $paramIsVariadic = false)
    {
        // TODO: this function is doing too much, it's looping all params each call
        // it should work on the single arg it's being called on, so that it does casting
        // it ends up with probably the same ett.txt, but it's innefficient

        // return ;

        global $_ett_method_cache, $_ett_lines;

        $outdir = str_replace('//', '/', BASE_PATH . '/artifacts');
        if (!file_exists($outdir)) {
            mkdir($outdir);
        }
        $outpath = "$outdir/ett.txt";
        if (count($_ett_lines) == 0) {
            file_put_contents($outpath, '$callingFile, $callingLine, $calledClass, $calledMethod, $var, $paramWhere, $paramType, $argType'. "\n");
        }

        $d = debug_backtrace(0, 3);

        // will use $d[2] in the case of call_user_func
        $callingFile = $d[1]['file'] ?? ($d[2]['file'] ?? '');
        $callingLine = $d[1]['line'] ?? ($d[2]['line'] ?? '');
        $calledClass = $d[1]['class'] ?? '';
        // $callType = $d[1]['type'] ?? '';
        $calledMethod = $d[1]['function'] ?? '';
        $args = $d[1]['args'] ?? [];

        if ($callingFile == '') {
            $a=1;
        }

        if (!$calledClass || !$calledMethod) {
            return;
            echo 'No class or method';die;
        }

        // shouldn't be doing reflection at this point, should only do
        // relfection when writing _a() calls
        $key = $calledClass . '::' . $calledMethod;
        if (!array_key_exists($key, $_ett_method_cache)) {
            $reflClass = new ReflectionClass($calledClass);
            $reflMethod = $reflClass->getMethod($calledMethod);
            $_tt_method_cache[$key] = _ett_get_method_data($reflClass, $reflMethod);
        }
        $data = $_tt_method_cache[$key];
        $vars = array_keys($data['methodParams']);
        // TODO: shouldn't be looping here, should be using function args
        for ($i = 0; $i < count($vars); $i++) {
            $var = $vars[$i];
            if (!array_key_exists($i, $args)) {
                // default args are a non issue
                if ($data['methodParamsHasDefault'][$var]) {
                    continue;
                }
                // by reference arguments can start life as undefined and become a return var
                // of sorts e.g. $m in preg_match($rx, $subject, $m);
                if ($data['methodParamsIsReference'][$var]) {
                    continue;
                }
                // variadic args are always mixed
                if ($data['methodParams'][$var] == 'variadic') {
                    continue;
                }
            }
            $arg = $args[$i];
            $paramType = $data['methodParams'][$var];
            $paramWhere = 'method';
            if ($paramType != 'dynamic') {
                // strongly typed method params are a non-issue since they throw exceptions
                continue;
            }
            if (array_key_exists($var, $data['docblockParams'])) {
                $paramType = $data['docblockParams'][$var];
                $paramWhere = 'docblock';
            } else {
                $paramType = 'dynamic';
                $paramWhere = 'nowhere';
            }
            if (is_null($arg)) {
                $argType = 'null';
            } elseif (is_string($arg)) {
                $argType = 'string';
            } elseif (is_int($arg)) {
                $argType = 'int';
            } elseif (is_float($arg)) {
                $argType = 'float';
            } elseif (is_array($arg)) {
                $argType = 'array';
            } elseif (is_bool($arg)) {
                $argType = 'bool';
            } elseif (is_callable($arg)) {
                $argType = 'callable';
            } elseif (is_object($arg)) {
                $argType = get_class($arg);
            } else {
                $argType = 'unknown';
            }
            // correctly documented, no need to log
            if ($paramType == $argType) {
                continue;
            }
            // docblock says object, various DataObject are passed in, this is OK
            if ($paramType == 'object' && is_object($arg)) {
                continue;
            }
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
}
