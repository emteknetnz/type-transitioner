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
            $match = false;
            if ($paramType == 'mixed') {
                continue;
            }
            foreach (explode('|', $paramType) as $pType) {
                // paramType (docblock) is usually not a FQCN
                if ($pType == $argType || preg_match("#\\{$pType}$#", $argType)) {
                    $match = true;
                    break;
                }
            }
            if ($match) {
                continue;
            }
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
                    $docblockType = $data['docblockParams'][$var] ?? 'dynamic';
                    $as[] = "_a('{$docblockType}', {$var});";
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
            array_map(fn($p) => $p->hasType() ? $p->getType()->getName() : 'dynamic', $reflParams)
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
        return ;

        global $_ett_method_cache, $_ett_lines;

        $outdir = str_replace('//', '/', BASE_PATH . '/artifacts');
        if (!file_exists($outdir)) {
            mkdir($outdir);
        }
        $outpath = "$outdir/ett.txt";
        if (count($_ett_lines) == 0) {
            file_put_contents($outpath, '$callingFile, $callingLine, $calledClass, $calledMethod, $var, $paramWhere, $paramType, $argType'. "\n");
        }

        $d = debug_backtrace(0, 2);

        $callingFile = $d[1]['file'] ?? '';
        $callingLine = $d[1]['line'] ?? '';
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
            if ($paramType != 'dynamic') {
                // strongly typed method params are a non-issue
                continue;
            }
            $paramWhere = 'method';
            if (array_key_exists($var, $o['docblockParams'])) {
                $paramType = $o['docblockParams'][$var];
                $paramWhere = 'docblock';
            } else {
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
            if ($argType == $paramType) {
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
