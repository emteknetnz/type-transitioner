<?php

namespace emteknetnz\TypeTransitioner;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ClassLoader;
use Psr\SimpleCache\CacheInterface;
use Reflection;
use ReflectionException;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\InjectorLoader;

class MethodAnalyser extends Singleton implements Flushable
{
    public const ETT_OPTIONAL = 1;
    public const ETT_REFERENCE = 2;
    public const ETT_VARIADIC = 4;

    private $methodDataCache = [];
    private $fqcnCache = [];
    private $docDocblockTypeStrCache = [];

    function cleanDocblockTypeStr(string $docblockTypeStr): string
    {
        $key = md5($docblockTypeStr);
        if (array_key_exists($key, $this->docDocblockTypeStrCache)) {
            return $this->docDocblockTypeStrCache[$key];
        }
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
        $value = ltrim($str, '\\');
        $this->docDocblockTypeStrCache[$key] = $value;
        return $value;
    }

    // cache to disk? help with behat between requests
    function getMethodData(ReflectionClass $reflClass, ReflectionMethod $reflMethod)
    {
        // https://www.php.net/manual/en/book.reflection.php
        $reflDocblock = $reflMethod->getDocComment();
        $reflParams = $reflMethod->getParameters();
        $reflReturn = $reflMethod->getReturnType();

        preg_match_all('#@param +([^ ]+)[ \t]+((?:\$)[A-Za-z0-9]+)#', $reflDocblock, $m);
        $docblockParams = array_combine($m[2], $m[1]);
        $methodParamNames = array_map(fn($p) => '$' . $p->getName(), $reflParams);
        $methodParams = array_combine(
            array_map(fn($p) => '$' . $p->getName(), $reflParams),
            array_map(function($p) {
                if ($p->isVariadic()) {
                    // ... splat operator
                    return 'VARIADIC';
                }
                if ($p->hasType()) {
                    return $p->getType()->getName();
                }
                return 'DYNAMIC';
            }, $reflParams)
        );
        $methodParamFlags = array_combine(
            array_map(fn($p) => '$' . $p->getName(), $reflParams),
            array_map(function(ReflectionParameter $p) {
                $flags = 0;
                if ($p->isOptional()) {
                    $flags += self::ETT_OPTIONAL;
                }
                if ($p->isPassedByReference()) {
                    $flags += self::ETT_REFERENCE;
                }
                if ($p->isVariadic()) {
                    $flags += self::ETT_VARIADIC;
                }
                return $flags;
            }, $reflParams
        ));
        foreach (array_keys($methodParams) as $paramName) {
            if (!isset($docblockParams[$paramName])) {
                $docblockParams[$paramName] = 'DYNAMIC';
            }
        }

        preg_match('#@return ([^ ]+)#', $reflDocblock, $m);
        $docblockReturn = trim($m[1] ?? 'DYNAMIC');
        $methodReturn = $reflReturn ? $reflReturn->getName() : 'DYNAMIC';
        $visibility = $reflMethod->isPublic() ? 'public' : ($reflMethod->isProtected() ? 'protected' : 'private');
        return [
            'namespace' => $reflClass->getNamespaceName(),
            'class' => $reflClass->getName(),
            'path' => $reflClass->getFileName(),
            'method' => $reflMethod->getName(),
            'visibility' => $visibility,
            'abstract' => $reflMethod->isAbstract(),
            'docblockParams' => $docblockParams,
            'methodParamNames' => $methodParamNames,
            'methodParamTypes' => $methodParams,
            'methodParamFlags' => $methodParamFlags,
            'docblockReturn' => $docblockReturn,
            'methodReturn' => $methodReturn,
        ];
    }

    function getArgType($arg): string
    {
        $type = gettype($arg);
        switch($type) {
            case 'NULL':
                return 'null';
                break;
            case 'boolean':
                return 'bool';
                break;
            case 'integer':
                return 'int';
                break;
            case 'double':
                return 'float';
                break;
            case 'string':
                return 'string';
                break;
            case 'array':
                return 'array';
                break;
            case 'object':
                $class = get_class($arg);
                return $class == 'Closure' ? 'callable' : $class;
                break;
            case 'resource':
            case 'resource (closed)':
                return 'resource';
                break;
            default:
                return is_callable($arg) ? 'callable' : 'unknown';
                break;
        }
    }

    private $vowels = [
        'a' => true,
        'e' => true,
        'i' => true,
        'o' => true,
        'u' => true
    ];

    function describeType(string $str): string
    {
        if ($str == 'null') {
            return 'null';
        }
        $c = $str[0] ?? '';
        $a = array_key_exists($c, $this->vowels) ? 'an' : 'a';
        return "$a '$str'";
    }

    public static function getCache(): ?CacheInterface
    {
        try {
            $cache = Injector::inst()->get(CacheInterface::class . '.MethodAnalyser');
            return $cache;
        } catch (\Exception $e) {
            // if calling from constants.php on boot, Injector Manifest and other things
            // won't be available
            return null;
        } catch (\Error $e) {
            return null;
        }
    }

    public static function flush()
    {
        $cache = static::getCache();
        $cache->clear();
    }

    function getBacktraceReflection(): array
    {
        $d = debug_backtrace(0, 4);
        // will use $d[3] in the case of call_user_func
        $callingFile = $d[2]['file'] ?? ($d[3]['file'] ?? '');
        $callingLine = $d[2]['line'] ?? ($d[3]['line'] ?? '');
        $calledClass = $d[2]['class'] ?? '';
        // $callType = $d[2]['type'] ?? '';
        $calledMethod = $d[2]['function'] ?? '';
        $args = $d[2]['args'] ?? [];

        // Disabling cache because it's causing some weird issue with _a()
        // - if caching is enabled, phpunit jobs strangely fail
        // - if caching is disabled, phpunit runs a couple of tests, cancel, then re-enabled
        //   caching, then things strangely work
        // - didn't look to far into it, though it seemed like the memroy cache was somehow problematic?
        //   (could be wrong there)

        // use memory cache first, then disk cache
        // $key = md5($calledClass . '.' . $calledMethod);
        // if (!array_key_exists($key, $this->methodDataCache)) {
        //     $cache = $this->getCache();
        //     $cache = null;
        //     if ($cache && $cache->has($key)) {
        //         $methodData = $cache->get($key);
        //     } else {
        //         $reflClass = new ReflectionClass($calledClass);
        //         try {
        //             $reflMethod = $reflClass->getMethod($calledMethod);
        //             $methodData = $this->getMethodData($reflClass, $reflMethod);
        //         } catch (ReflectionException $e) {
        //             $methodData = null;
        //         }
        //         if ($cache) {
        //             $cache->set($key, $methodData);
        //         }
        //     }
        //     $this->methodDataCache[$key] = $methodData;
        // }
        // $methodData = $this->methodDataCache[$key];

        // Memory only caching
        try {
            $reflClass = new ReflectionClass($calledClass);
            $reflMethod = $reflClass->getMethod($calledMethod);
            $key = md5($calledClass . '.' . $calledMethod);
            if (!array_key_exists($key, $this->methodDataCache)) {
                $methodData = $this->getMethodData($reflClass, $reflMethod);
                $this->methodDataCache[$key] = $methodData;
            }
            $methodData = $this->methodDataCache[$key];
        } catch (ReflectionException $e) {
            $methodData = null;
        }

        // No caching, more reliable
        // $reflClass = new ReflectionClass($calledClass);
        // try {
        //     $reflMethod = $reflClass->getMethod($calledMethod);
        //     $methodData = $this->getMethodData($reflClass, $reflMethod);
        // } catch (ReflectionException $e) {
        //     $methodData = null;
        // }

        return [
            'callingFile' => $callingFile,
            'callingLine' => $callingLine,
            'calledClass' => $calledClass,
            'calledMethod' => $calledMethod,
            'args' => $args,
            'methodData' => $methodData
        ];
    }

    function argMatchesDockblockTypeStr($arg, string $docblockTypeStr)
    {
        return $this->argTypeMatchesDockblockTypeStr($this->getArgType($arg), $docblockTypeStr);
    }

    function argTypeMatchesDockblockTypeStr(string $argType, string $docblockTypeStr)
    {
        $lcArgType = strtolower($this->fqcnToShortClassName($argType));
        $docBlockTypes = explode('|', $this->cleanDocblockTypeStr($docblockTypeStr));
        foreach ($docBlockTypes as $docBlockType) {
            $lcDocBlockType = strtolower($this->fqcnToShortClassName($docBlockType));
            if ($lcDocBlockType == 'object' || $lcArgType == $lcDocBlockType) {
                return true;
            }
            if ($lcDocBlockType == 'closure' && $lcArgType == 'callable') {
                return true;
            }
            if ($lcDocBlockType == 'callable' && $lcArgType == 'closure') {
                return true;
            }
            if (!$this->typeIsClass($argType)) {
                continue;
            }
            $lcDocblockShortClassName = strtolower($this->fqcnToShortClassName($docBlockType));
            if ($lcArgType == $lcDocblockShortClassName) {
                return true;
            }
            $fqcnDocBlockTypes = $this->shortClassNameToFqcns($docBlockType);
            foreach ($fqcnDocBlockTypes as $fqcnDocBlockType) {
                if (is_subclass_of($argType, $fqcnDocBlockType)) {
                    return true;
                }
            }
        }
        return false;
    }

    private $nonClassTypes = [
        'string' => true,
        'bool' => true,
        'boolean' => true,
        'true' => true,
        'false' => true,
        'int' => true,
        'integer' => true,
        'float' => true,
        'double' => true,
        'array' => true,
        // 'object' => true,
        'null' => true,
        'callable' => true
    ];

    function typeIsClass(string $type): bool
    {
        return !array_key_exists(strtolower($type), $this->nonClassTypes);
    }

    function shortClassNameToFqcns(string $shortClassName): array
    {
        if ($shortClassName == '' || !$this->typeIsClass($shortClassName)) {
            return [$shortClassName];
        }
        if (strpos($shortClassName, "\\") !== false || strtolower($shortClassName) == 'object') {
            return [$shortClassName];
        }
        if (empty($this->fqcnCache)) {
            $manifest = ClassLoader::inst()->getManifest();
            if ($manifest && !empty($manifest->getClasses())) {
                $fqcns = array_merge($manifest->getClassNames(), $manifest->getInterfaceNames());
                foreach ($fqcns as $fqcn) {
                    $a = explode("\\", $fqcn);
                    $lcShortClassName = strtolower(array_pop($a));
                    $this->fqcnCache[$lcShortClassName] ??= [];
                    $this->fqcnCache[$lcShortClassName][] = $fqcn;
                }
            }
        }
        $lcShortClassName = strtolower($shortClassName);
        return $this->fqcnCache[$lcShortClassName] ?? [$shortClassName];
    }

    function fqcnToShortClassName(string $fqcn): string
    {
        $a = explode('\\', $fqcn);
        return $a[count($a) - 1];
    }
}
