<?php

use emteknetnz\TypeTransitioner\Config\Config;
use emteknetnz\TypeTransitioner\Exceptions\TypeException;
use SilverStripe\Core\ClassInfo;

if (!defined('ETT_OPTIONAL')) {
    define('ETT_OPTIONAL', 1);
    define('ETT_REFERENCE', 2);
    define('ETT_VARIADIC', 4);
}

// this will get included twice
if (!function_exists('_write_function_calls')) {

    global $_writing_function_calls;
    $_writing_function_calls = false;

    global $_ett_method_cache;
    $_ett_method_cache = [];

    global $_ett_lines;
    $_ett_lines = [];

    function _write_function_calls()
    {
        global $_writing_function_calls;
        $_writing_function_calls = true;
        // need to update framework constants.php, which loads as part of the index.php during
        // require __DIR__ . '/../vendor/autoload.php';
        // i.e. before autoloader has loaded emteknetnz functions.php
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
            $reflClass = new ReflectionClass($fqcn);
            $methodDataArr = [];
            foreach ($reflClass->getMethods() as $reflMethod) {
                $name = $reflMethod->getName();
                // ReflectionClass::getMethods() sorts the methods by class (lowest in the inheritance tree first)
                // so as soon as we find an inherited method not in the $contents, we can break
                if (!preg_match("#function {$name} ?\(#", $contents)) {
                    break;
                }
                $methodDataArr[] = _ett_get_method_data($reflClass, $reflMethod);
            }
            // only include methods with dynamic params or a dynamic return type
            $methodDataArr = array_filter($methodDataArr, function (array $data) {
                if ($data['abstract']) {
                    return false;
                }
                $hasDynamicParams = !empty(array_filter($data['methodParamTypes'], fn(string $type) => $type == 'dynamic'));
                // TODO: return types
                // return $hasDynamicParams || $o['methodReturn'] == 'dynamic';
                return $hasDynamicParams;
            });
            /** @var array $m */
            $changed = false;
            foreach ($methodDataArr as $methodData) {
                $calls = [];
                $writeA = false;
                // $paramNum will start at 1 to match PHP error messages, e.g.
                // "[Warning] nl2br() expects parameter 1 to be string, array given"
                // where parameter 1 is the first paramter
                $paramNum = 0;
                $method = $methodData['method'];
                foreach ($methodData['methodParamTypes'] as $paramName => $methodParamType) {
                    $paramNum++;
                    if ($methodParamType != 'dynamic') {
                        continue;
                    }
                    // if (($methodData['methodParamFlags'][$paramName] & ETT_REFERENCE) == ETT_REFERENCE) {
                    //     continue;
                    // }
                    // variadic params are always an array, so no need to log
                    if (($methodData['methodParamFlags'][$paramName] & ETT_VARIADIC) == ETT_VARIADIC) {
                        continue;
                    }
                    // these methods are used by _c() so exclude as to not cause infinite loop
                    if ($class == 'ClassInfo' && in_array($method, ['ancestry', 'class_name'])) {
                        continue;
                    }
                    $docblockTypeStr = $methodData['docblockParams'][$paramName] ?? 'dynamic';
                    if (strpos($docblockTypeStr, 'mixed') !== false) {
                        continue;
                    }
                    if ($docblockTypeStr != 'dynamic') {
                        $docblockTypeStr = _ett_clean_docblock_type_str($docblockTypeStr);
                        
                        $calls[] = "_c('{$docblockTypeStr}', {$paramName}, {$paramNum});";
                    }
                    $writeA = true;
                }
                if ($writeA) {
                    array_unshift($calls, '_a();');
                }
                if (empty($calls)) {
                    continue;
                }
                preg_match("#(?s)(function {$method} ?\(.+)#", $contents, $m);
                $fncontents = $m[1];
                $callsStr = implode("\n        ", $calls);
                $newfncontents = preg_replace('#{#', "{\n        $callsStr", $fncontents, 1);
                $contents = str_replace($fncontents, $newfncontents, $contents);
                $changed = true;
            }
            if (!$changed) {
                continue;
            }
            file_put_contents($path, $contents);
        }
    }

    function _ett_clean_docblock_type_str(string $docblockTypeStr): string
    {
        $str = implode('|', array_map(function(string $type) {
            $type = ltrim($type, '\\');
            if ($type == 'true' || $type == 'false') {
                $type = 'bool';
            }
            // e.g. string[]
            if (strpos($type, '[]') !== false) {
                $type = 'array';
            }
            if ($type == 'Object') {
                $type = 'object';
            }
            if (strtolower($type) == 'boolean') {
                $type = 'bool';
            }
            if (strtolower($type) == 'integer') {
                $type = 'int';
            }
            return $type;
        }, explode('|', $docblockTypeStr)));
        $str = str_replace('|\\', '|', $str);
        return ltrim($str, '\\');
    }

    function _analyse_data()
    {
        global $_writing_function_calls;
        if ($_writing_function_calls) {
            return;
        }
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
                $paramName,
                $paramWhere,
                $paramType,
                $argType
            ) = $data;
            // docblock param is mixed, so arg can be anything
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
            // docblock param (include undocblocked param) does not match arg
            $param = "({$paramType}) {$paramName}";
            $res[$calledClass][$calledMethod][$param] ??= [];
            $call = "({$argType}) {$callingFile}:{$callingLine}";
            if (!in_array($call, $res[$calledClass][$calledMethod][$param])) {
                $res[$calledClass][$calledMethod][$param][] = $call;
            }
        }
        print_r($res);die;
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
        $methodParamFlags = array_combine(
            array_map(fn($p) => '$' . $p->getName(), $reflParams),
            array_map(function(ReflectionParameter $p) {
                $flags = 0;
                if ($p->isOptional()) {
                    $flags += ETT_OPTIONAL;
                }
                if ($p->isPassedByReference()) {
                    $flags += ETT_REFERENCE;
                }
                if ($p->isVariadic()) {
                    $flags += ETT_VARIADIC;
                }
                return $flags;
            }, $reflParams
        ));

        preg_match('#@return ([^ ]+)#', $reflDocblock, $m);
        $docblockReturn = $m[1] ?? 'missing';
        $methodReturn = $reflReturn ? $reflReturn->getName() : 'dynamic';

        return [
            'namespace' => $reflClass->getNamespaceName(),
            'class' => $reflClass->getName(),
            'method' => $reflMethod->getName(),
            'abstract' => $reflMethod->isAbstract(),
            'docblockParams' => $docblockParams,
            'methodParamTypes' => $methodParams,
            'methodParamFlags' => $methodParamFlags,
            'docblockReturn' => $docblockReturn,
            'methodReturn' => $methodReturn,
        ];
    }

    function _ett_get_arg_type($arg): string
    {
        if (is_null($arg)) {
            return 'null';
        } elseif (is_string($arg)) {
            return 'string';
        } elseif (is_int($arg)) {
            return 'int';
        } elseif (is_float($arg)) {
            return 'float';
        } elseif (is_array($arg)) {
            return 'array';
        } elseif (is_bool($arg)) {
            return 'bool';
        } elseif (is_resource($arg)){
            return 'resource';
        } elseif (is_callable($arg)) {
            return 'callable';
        } elseif (is_object($arg)) {
            return get_class($arg);
        }
        return 'unknown';
    }

    function _ett_get_classname_only(string $fqcn): string
    {
        $a = explode('\\', $fqcn);
        return $a[count($a) - 1];
    }

    function _ett_describe_type(string $str): string
    {
        if ($str == 'null') {
            return 'null';
        }
        $c = $str[0] ?? '';
        $a = ($c == 'a' || $c == 'e' || $c == 'i' || $c =='o' || $c == 'u') ? 'an' : 'a';
        return "$a '$str'";
    }

    function _ett_backtrace_reflection(): array
    {
        global $_ett_method_cache;
        $d = debug_backtrace(0, 4);
        // will use $d[3] in the case of call_user_func
        $callingFile = $d[2]['file'] ?? ($d[3]['file'] ?? '');
        $callingLine = $d[2]['line'] ?? ($d[3]['line'] ?? '');
        $calledClass = $d[2]['class'] ?? '';
        // $callType = $d[2]['type'] ?? '';
        $calledMethod = $d[2]['function'] ?? '';
        $args = $d[2]['args'] ?? [];
        $key = $calledClass . '::' . $calledMethod;
        if (!array_key_exists($key, $_ett_method_cache)) {
            $reflClass = new ReflectionClass($calledClass);
            $reflMethod = $reflClass->getMethod($calledMethod);
            $_tt_method_cache[$key] = _ett_get_method_data($reflClass, $reflMethod);
        }
        $methodData = $_tt_method_cache[$key];
        return [
            'callingFile' => $callingFile,
            'callingLine' => $callingLine,
            'calledClass' => $calledClass,
            'calledMethod' => $calledMethod,
            'args' => $args,
            'methodData' => $methodData
        ];
    }

    function _ett_arg_matches_docblock_type_str($arg, string $docblockTypeStr)
    {
        $docBlockTypes = explode('|', _ett_clean_docblock_type_str($docblockTypeStr));
        $argType = _ett_get_arg_type($arg);
        $isObject = is_object($arg);
        foreach ($docBlockTypes as $docBlockType) {
            if ($argType == $docBlockType) {
                return true;
            }
            if ($isObject) {
                if ($docBlockType == 'object') {
                    return true;
                }
                $docBlockTypeClassNameOnly = _ett_get_classname_only($docBlockType);
                if (_ett_get_classname_only($argType) == $docBlockTypeClassNameOnly) {
                    return true;
                }
                foreach (ClassInfo::ancestry($arg) as $ancestorClass) {
                    if (_ett_get_classname_only($ancestorClass) == $docBlockTypeClassNameOnly) {
                        return true;;
                    }
                }
            }
        }
        return false;
    }

    function _c(string $docBlockTypeStr, &$arg, int $paramNum): void
    {
        global $_writing_function_calls;
        if ($_writing_function_calls) {
            return;
        }
        $nonObjectTypes = [
            'string' => true,
            'bool' => true,
            'int' => true,
            'float' => true,
            'array' => true
        ];
        $config = Config::inst();
        $docBlockTypes = explode('|', $docBlockTypeStr);

        // cast to the first casttype found e.g string|int will cast null to (string) '' 
        if ($config->get(Config::CAST_NULL) && is_null($arg) && !in_array('null', $docBlockTypes)) {
            foreach ($docBlockTypes as $docBlockType) {
                // can only cast to a non-object type
                if (!array_key_exists($docBlockType, $nonObjectTypes)) {
                    continue;
                }
                // cast null $arg - set by reference
                settype($arg, $docBlockType);
                break;
            }
        }

        // Throw warnings about incorrect param types
        // PHP wrong argument errors are actually simpler, they show arguments number (starting at 1)
        // rather than the name of the variable that's wrong, e.g.
        // "[Warning] nl2br() expects parameter 1 to be string, array given"
        if ($config->get(Config::TRIGGER_E_USER_DEPRECATED) || $config->get(Config::THROW_TYPE_EXCEPTION)) {
            if (!_ett_arg_matches_docblock_type_str($arg, $docBlockTypeStr)) {
                $argType = _ett_get_arg_type($arg);
                $backRefl = _ett_backtrace_reflection();
                $paramName = array_keys($backRefl['methodData']['methodParamTypes'])[$paramNum - 1];
                if ($config->get(Config::TRIGGER_E_USER_DEPRECATED)) {
                    trigger_error(sprintf(
                        implode("\n", [
                            "%s::%s() - %s is %s but should be %s",
                            'Called from %s:%s'
                        ]),
                        $backRefl['calledClass'],
                        $backRefl['calledMethod'],
                        $paramName,
                        _ett_describe_type($argType),
                        _ett_describe_type($docBlockTypeStr),
                        $backRefl['callingFile'],
                        $backRefl['callingLine']
                    ), \E_USER_DEPRECATED);
                }
                if ($config->get(Config::THROW_TYPE_EXCEPTION)) {
                    throw new TypeException(sprintf(
                        "%s::%s() - %s is %s but must be %s",
                        $backRefl['calledClass'],
                        $backRefl['calledMethod'],
                        $paramName,
                        $paramName,
                        _ett_describe_type($argType),
                        _ett_describe_type($docBlockTypeStr),
                    ));
                }
            }
        }
    }

    // dev only - uses reflection and backtraces
    // idea is to get info to update docblocks / strongly typed params
    function _a(): void
    {
        global $_writing_function_calls, $_ett_lines;
        if ($_writing_function_calls) {
            return;
        }

        $outdir = str_replace('//', '/', BASE_PATH . '/artifacts');
        if (!file_exists($outdir)) {
            mkdir($outdir);
        }
        $outpath = "$outdir/ett.txt";
        if (count($_ett_lines) == 0) {
            file_put_contents($outpath, '$callingFile, $callingLine, $calledClass, $calledMethod, $var, $paramWhere, $paramType, $argType'. "\n");
        }

        $backRefl = _ett_backtrace_reflection();
        $methodData = $backRefl['methodData'];

        $paramNames = array_keys($methodData['methodParamTypes']);
        for ($i = 0; $i < count($paramNames); $i++) {
            $paramName = $paramNames[$i];
            $arg = $backRefl['args'][$i] ?? null;
            // null optional args are a non issue, will use default which assumed to always be valid
            if (is_null($arg) && ($methodData['methodParamFlags'][$paramName] & ETT_OPTIONAL) == ETT_OPTIONAL) {
                continue;
            }
            // null by reference arguments can start life as undefined and become a return var
            // of sorts e.g. $m in preg_match($rx, $subject, $m);
            if (is_null($arg) && ($methodData['methodParamFlags'][$paramName] & ETT_REFERENCE) == ETT_REFERENCE) {
                continue;
            }
            // variadic args are always a mixed array, no need to validate
            if (($methodData['methodParamFlags'][$paramName] & ETT_VARIADIC) == ETT_VARIADIC) {
                continue;
            }
            // strongly typed method params are a non-issue since they throw exceptions
            if ($methodData['methodParamTypes'][$paramName] != 'dynamic') {
                return;
            }
            $docBlockTypeStr = $methodData['docblockParams'][$paramName] ?? '';
            if ($docBlockTypeStr != '') {
                $paramType = $docBlockTypeStr;
                $paramWhere = 'docblock';
            } else {
                $paramType = 'dynamic';
                $paramWhere = 'undocumented';
            }
            $argType = _ett_get_arg_type($arg);
            // arg matches dockblock type, no need to log
            if (_ett_arg_matches_docblock_type_str($arg, $docBlockTypeStr)) {
                return;
            }
            $line = implode(',', [
                $backRefl['callingFile'],
                $backRefl['callingLine'],
                $backRefl['calledClass'],
                $backRefl['calledMethod'],
                $paramName,
                $paramWhere,
                $paramType,
                $argType
            ]);
            // only log the exact same call once
            if (array_key_exists($line, $_ett_lines)) {
                return;
            }
            $_ett_lines[$line] = true;
            file_put_contents($outpath, "$line\n", FILE_APPEND);
        }
    }
}
