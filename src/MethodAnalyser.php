<?php

namespace emteknetnz\TypeTransitioner;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Manifest\ClassLoader;

class MethodAnalyser extends Singleton
{
    public const ETT_OPTIONAL = 1;
    public const ETT_REFERENCE = 2;
    public const ETT_VARIADIC = 4;

    private $methodCache = [];
    private $fqcnCache = [];

    function cleanDocblockTypeStr(string $docblockTypeStr): string
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

    function getMethodData(ReflectionClass $reflClass, ReflectionMethod $reflMethod)
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

    function getArgType($arg): string
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

    function getClassNameOnly(string $fqcn): string
    {
        $a = explode('\\', $fqcn);
        return $a[count($a) - 1];
    }

    function describeType(string $str): string
    {
        if ($str == 'null') {
            return 'null';
        }
        $c = $str[0] ?? '';
        $a = ($c == 'a' || $c == 'e' || $c == 'i' || $c =='o' || $c == 'u') ? 'an' : 'a';
        return "$a '$str'";
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
        $key = $calledClass . '::' . $calledMethod;
        if (!array_key_exists($key, $this->methodCache)) {
            $reflClass = new ReflectionClass($calledClass);
            $reflMethod = $reflClass->getMethod($calledMethod);
            $this->methodCache[$key] = $this->getMethodData($reflClass, $reflMethod);
        }
        $methodData = $this->methodCache[$key];
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
        $docBlockTypes = explode('|', $this->cleanDocblockTypeStr($docblockTypeStr));
        $argType = $this->getArgType($arg);
        $isObject = is_object($arg);
        foreach ($docBlockTypes as $docBlockType) {
            $docBlockType = $this->shortClassNameToFqcn($docBlockType);
            if ($argType == $docBlockType) {
                return true;
            }
            if ($isObject) {
                if ($docBlockType == 'object') {
                    return true;
                }
                $docBlockTypeClassNameOnly = $this->getClassNameOnly($docBlockType);
                if ($this->getClassNameOnly($argType) == $docBlockTypeClassNameOnly) {
                    return true;
                }
                foreach (ClassInfo::ancestry($arg) as $ancestorClass) {
                    if ($this->getClassNameOnly($ancestorClass) == $docBlockTypeClassNameOnly) {
                        return true;;
                    }
                }
            }
        }
        return false;
    }

    function argTypeMatchesDockblockTypeStr(string $argType, string $docblockTypeStr)
    {
        $docBlockTypes = explode('|', $this->cleanDocblockTypeStr($docblockTypeStr));
        foreach ($docBlockTypes as $docBlockType) {
            $docBlockType = $this->shortClassNameToFqcn($docBlockType);
            if ($argType == $docBlockType) {
                return true;
            }
        }
        return false;
    }

    function typeIsClass(string $type): bool
    {
        return !array_key_exists(strtolower($type), [
            'string' => false,
            'bool' => false,
            'boolean' => false,
            'true' => false,
            'false' => false,
            'int' => false,
            'integer' => false,
            'float' => false,
            'double' => false,
            'array' => false,
            // 'object' => false,
            'null' => false,
            'callable' => false
        ]);
    }

    function shortClassNameToFqcn(string $shortClassName): string
    {
        if ($shortClassName == '' || !$this->typeIsClass($shortClassName)) {
            return $shortClassName;
        }
        if (strpos($shortClassName, "\\") !== false || strtolower($shortClassName) == 'object') {
            return $shortClassName;
        }
        if (empty($this->fqcnLookup)) {
            $manifest = ClassLoader::inst()->getManifest();
            if ($manifest && !empty($manifest->getClasses())) {
                $fqcns = array_merge($manifest->getClassNames(), $manifest->getInterfaceNames());
                foreach ($fqcns as $fqcn) {
                    $a = explode("\\", $fqcn);
                    $lcShortClassName = strtolower(array_pop($a));
                    $this->fqcnLookup[$lcShortClassName] = $fqcn;
                }
            }
        }
        return $this->fqcnLookup[strtolower($shortClassName)] ?? $shortClassName;
    }
}
