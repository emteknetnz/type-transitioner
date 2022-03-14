<?php

use emteknetnz\TypeTransitioner\Config\Config;
use emteknetnz\TypeTransitioner\Exceptions\TypeException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Manifest\ClassManifest;

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
                // TODO: return types
                // return $hasDynamicParams || $o['methodReturn'] == 'dynamic';
                return $hasDynamicParams;
            });
            /** @var array $m */
            $changed = false;
            foreach ($methodData as $data) {
                $calls = [];
                $writeA = false;
                foreach ($data['methodParams'] as $var => $type) {
                    if ($type != 'dynamic') {
                        continue;
                    }
                    if ($data['methodParamFlags'][$var] == 0) {
                        $docblockType = $data['docblockParams'][$var] ?? 'dynamic';
                        $docblockType = implode('|', array_map(function(string $type) {
                            $type = ltrim($type, '\\');
                            if ($type == 'true' || $type == 'false') {
                                $type = 'bool';
                            }
                            // e.g. string[]
                            if (strpos($type, '[]')) {
                                $type = 'array';
                            }
                            if ($type == 'boolean') {
                                $type = 'bool';
                            }
                            return $type;
                        }, explode('|', $docblockType)));
                        
                        $docblockType = str_replace('|\\', '|', $docblockType);
                        $docblockType = ltrim($docblockType, '\\');
                        $calls[] = "_c('{$docblockType}', {$var});";
                    }
                    $writeA = true;
                }
                if ($writeA) {
                    array_unshift($calls, '_a();');
                }
                $method = $data['method'];
                preg_match("#(?s)(function {$method} ?\(.+)#", $contents, $m);
                $fncontents = $m[1];
                preg_match('#( *){#', $fncontents, $m2);
                $indent = $m2[1];
                $callsStr = implode("\n{$indent}    ", $calls);
                $newfncontents = preg_replace('#{#', "{\n$indent    $callsStr", $fncontents, 1);
                $contents = str_replace($fncontents, $newfncontents, $contents);
                $changed = true;
            }
            if (!$changed) {
                continue;
            }
            file_put_contents($path, $contents);
        }
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
            'methodParams' => $methodParams,
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
        } elseif (is_callable($arg)) {
            return 'callable';
        } elseif (is_object($arg)) {
            return  get_class($arg);
        }
        return 'unknown';
    }

    function _ett_get_classname_only(string $fqcn): string
    {
        $a = explode('\\', $fqcn);
        return $a[count($a) - 1];
    }

    // cast scalars if null - used for php81 compatibility - dev + live
    function _c(string $docBlockTypeStr, &$arg): void
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
                $arg = settype($arg, $docBlockType);
                break;
            }
        }

        if ($config->get(Config::TRIGGER_E_USER_DEPRECATED) || $config->get(Config::THROW_TYPE_EXCEPTION)) {
            $isValidType = false;
            $argType = _ett_get_arg_type($arg);
            $isObject = is_object($argType);
            foreach ($docBlockTypes as $docBlockType) {
                if ($argType == $docBlockType) {
                    $isValidType = true;
                    break;
                }
                if ($isObject) {
                    $docBlockTypeClassNameOnly = _ett_get_classname_only($docBlockType);
                    if (_ett_get_classname_only($argType) == $docBlockTypeClassNameOnly) {
                        $isValidType = true;
                        break;
                    }
                    foreach (ClassInfo::ancestry($arg) as $ancestorClass) {
                        if (_ett_get_classname_only($ancestorClass) == $docBlockTypeClassNameOnly) {
                            $isValidType = true;
                            break;
                        }
                    }
                }
            }
            if (!$isValidType) {
                // TODO - may need reflection/backtrace
                // name of the argument that failed validation so can make a coherent message
                $message = sprintf("My message");
                if ($config->get(Config::TRIGGER_E_USER_DEPRECATED)) {
                    @trigger_error($message, \E_USER_DEPRECATED);
                }
                if ($config->get(Config::THROW_TYPE_EXCEPTION)) {
                    throw new TypeException($message);
                }
            }
        }
    }

    // dev only - uses reflection and backtraces
    // idea is to get info to update docblocks / strongly typed params
    function _a(): void
    {
        global $_writing_function_calls, $_ett_method_cache, $_ett_lines;
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

        $d = debug_backtrace(0, 3);

        // will use $d[2] in the case of call_user_func
        $callingFile = $d[1]['file'] ?? ($d[2]['file'] ?? '');
        $callingLine = $d[1]['line'] ?? ($d[2]['line'] ?? '');
        $calledClass = $d[1]['class'] ?? '';
        // $callType = $d[1]['type'] ?? '';
        $calledMethod = $d[1]['function'] ?? '';
        $args = $d[1]['args'] ?? [];

        if (!$calledClass || !$calledMethod) {
            return;
            echo 'No class or method';die;
        }

        $key = $calledClass . '::' . $calledMethod;
        if (!array_key_exists($key, $_ett_method_cache)) {
            $reflClass = new ReflectionClass($calledClass);
            $reflMethod = $reflClass->getMethod($calledMethod);
            $_tt_method_cache[$key] = _ett_get_method_data($reflClass, $reflMethod);
        }
        $data = $_tt_method_cache[$key];
        $vars = array_keys($data['methodParams']);
        for ($i = 0; $i < count($vars); $i++) {
            $var = $vars[$i];
            if (!array_key_exists($i, $args)) {

                // optional args are a non issue, will use default which assumed to always be valid
                if (($data['methodParamFlags'] & ETT_OPTIONAL) == ETT_OPTIONAL) {
                    continue;
                }
                // by reference arguments can start life as undefined and become a return var
                // of sorts e.g. $m in preg_match($rx, $subject, $m);
                if (($data['methodParamFlags'] & ETT_REFERENCE) == ETT_REFERENCE) {
                    continue;
                }
                // variadic args are always a mixed array, no need to validate
                if (($data['methodParamFlags'] & ETT_VARIADIC) == ETT_VARIADIC) {
                    continue;
                }
            }
            $arg = $args[$i];
            $paramType = $data['methodParams'][$var];
            $paramWhere = 'method';
            if ($paramType != 'dynamic') {
                // strongly typed method params are a non-issue since they throw exceptions
                return;
            }
            if (array_key_exists($var, $data['docblockParams'])) {
                $paramType = $data['docblockParams'][$var];
                $paramWhere = 'docblock';
            } else {
                $paramType = 'dynamic';
                $paramWhere = 'nowhere';
            }
            $argType = _ett_get_arg_type($arg);
            // correctly documented, no need to log
            if ($paramType == $argType) {
                return;
            }
            // docblock says object, various DataObject are passed in, this is OK
            if ($paramType == 'object' && is_object($arg)) {
                return;
            }
            $line = implode(',', [$callingFile, $callingLine, $calledClass, $calledMethod, $var, $paramWhere, $paramType, $argType]);
            if (array_key_exists($line, $_ett_lines)) {
                return;
            }
            $_ett_lines[$line] = true;
            file_put_contents($outpath, "$line\n", FILE_APPEND);
        }
    }
}
