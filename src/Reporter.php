<?php

namespace emteknetnz\TypeTransitioner;

use Exception;
use PhpParser\Builder\Method;
use ReflectionClass;
use SilverStripe\Core\ClassInfo;

// Parses log file and creates report
class Reporter
{
    private $userDefinedClassesAndInterfaces = null;

    function report()
    {
        $path = BASE_PATH . '/ett/ett.txt';
        if (!file_exists($path)) {
            echo "Missing $path\n";
            die;
        }
        $lines = explode("\n", file_get_contents(BASE_PATH . '/ett/ett.txt'));
        // remove header line
        array_shift($lines);

        $traceResults = [];

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            $data = explode("\t", $line);
            list(
                // ARG|RETURN
                $type,
                // only used for updating method calls after making strong types:
                $callingFile,
                $callingLine,
                // used for both making string param and return types:
                $calledClass,
                $calledMethod,
                // used for making strong param types:
                $paramName,
                $paramFlags,
                $paramStrongType,
                $paramDocblockType,
                $argType,
                // used for making strong return types:
                $returnStrongType,
                $returnDocblockType,
                $returnedType
            ) = $data;
            if ($type == 'ARG') {
                $traceResults[$calledClass][$calledMethod]['params'] ??= [];
                $traceResults[$calledClass][$calledMethod]['params'][$paramName] ??= [
                    'paramFlags' => $paramFlags,
                    'paramStrongType' => $paramStrongType,
                    'paramDocblockType' => $paramDocblockType,
                    'argTypes' => []
                ];
                $traceResults[$calledClass][$calledMethod]['params'][$paramName]['argTypes'][$argType] = true;
            }
            if ($type == 'RETURN') {
                $traceResults[$calledClass][$calledMethod]['return'] ??= [
                    'returnStrongType' => $returnStrongType,
                    'returnDocblockType' => $returnDocblockType,
                    'returnedTypes' => []
                ];
                $traceResults[$calledClass][$calledMethod]['return']['returnedTypes'][$returnedType] = true;
            }
        }

        $staticScan = $this->staticScanClasses();
        $combined = $staticScan;
        foreach (array_keys($combined) as $fqcn) {
            foreach (array_keys($combined[$fqcn]) as $methodName) {
                $combined[$fqcn][$methodName]['trace'] = [
                    'traced' => false,
                    'results' => null,
                ];
                if (!isset($traceResults[$fqcn][$methodName])) {
                    continue;
                }
                $combined[$fqcn][$methodName]['trace']['traced'] = true;
                $combined[$fqcn][$methodName]['trace']['results'] = $traceResults[$fqcn][$methodName];
            }
        }
        $traced = [];
        $not_traced = [];
        $traced_in_docblock = [];
        $traced_in_docblock_as_mixed = [];
        $traced_not_in_docblock = [];
        foreach (array_keys($combined) as $fqcn) {
            foreach (array_keys($combined[$fqcn]) as $methodName) {
                // see if there are either some dynamic params or return type
                // don't need to do anything with fully strongly typed methods
                $someDynamic = false;
                foreach ($combined[$fqcn][$methodName]['methodParamTypes'] as $paramType) {
                    if ($paramType == 'DYNAMIC') {
                        $someDynamic = true;
                    }
                }
                if ($combined[$fqcn][$methodName]['methodReturn'] == 'DYNAMIC') {
                    $someDynamic = true;
                }
                if (!$someDynamic) {
                    continue;
                }
                // tmp - only check framework
                if (strpos($combined[$fqcn][$methodName]['path'], '/framework/') === false) {
                    continue;
                }
                if ($combined[$fqcn][$methodName]['trace']['traced']) {
                    $traced[] = "$fqcn::$methodName";
                    // of those traced, what is docblock accuracy?
                    foreach ($combined[$fqcn][$methodName]['trace']['results']['params'] ?? [] as $paramName => $paramData) {
                        $iden = "$fqcn::$methodName:$paramName";
                        $argTypes = $paramData['argTypes'];
                        $docblockTypes = $this->cleanDocblockTypes(explode('|', $paramData['paramDocblockType']));
                        foreach (array_keys($argTypes) as $argType) {
                            if ($this->argTypeIsInstanceOfDocblockTypes($argType, $docblockTypes)) {
                                $traced_in_docblock[] = "$iden-$argType>" . implode('|', $docblockTypes);
                            } elseif (strpos($paramData['paramDocblockType'], 'mixed') !== false) {
                                $traced_in_docblock_as_mixed[] = "$iden-$argType>" . implode('|', $docblockTypes);
                            } else {
                                $traced_not_in_docblock[] = "$iden-$argType>" . implode('|', $docblockTypes);
                            }
                        }
                    }
                    // TODO: ['return']

                    // if multiple dockblock types available, then it only needs to hit once
                    // params + docblock
                    // do we collect docblock return??
                } else {
                    $not_traced[] = "$fqcn::$methodName";
                    // of those untraced, what is docblock coverage
                }
            }
        }
        $t = count($traced);
        $nt = count($not_traced);
        $tid = count($traced_in_docblock);
        $tidam = count($traced_in_docblock_as_mixed);
        $tnid = count($traced_not_in_docblock);
        print_r([
            'method_traced' => $t . ' (' . round(($t / ($t + $nt) * 100), 1) . '%)',
            '- param_traced_in_docblock' => $tid . ' (' . round(($tid / ($tid + $tidam + $tnid)) * 100, 1) . '%)',
            '- param_traced_in_docblock_as_mixed' => $tidam . ' (' . round(($tidam / ($tid + $tidam + $tnid)) * 100, 1) . '%)',
            '- param_traced_not_in_docblock' => $tnid . ' (' . round(($tnid / ($tid + $tidam + $tnid)) * 100, 1) . '%)',
            'method_not_traced' => $nt . ' (' . round(($nt / ($t + $nt)) * 100, 1) . '%)',
        ]);
        // print_r($not_traced);
    }

    private function argTypeIsInstanceOfDocblockTypes(string $argType, array $docblockTypes): bool
    {
        // making assumption that user-defined docblock types missing namespace don't collide
        $classesAndInterfaces = $this->getUserDefinedClassesAndInterfaces();
        foreach ($docblockTypes as $docblockType) {
            $shortDocblockType = $this->shortType($docblockType);
            foreach($classesAndInterfaces as $classOrInterface) {
                $short = $this->shortType($classOrInterface);
                if (strtolower($short) != strtolower($shortDocblockType)) {
                    continue;
                }
                if (is_a($argType, $classOrInterface, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function getUserDefinedClassesAndInterfaces(): array
    {
        if (!is_null($this->userDefinedClassesAndInterfaces)) {
            return $this->userDefinedClassesAndInterfaces;
        }
        $this->userDefinedClassesAndInterfaces = [];
        $rx = '#^(SilverStripe|Symbiote|DNADesign)#';
        foreach(get_declared_classes() as $class) {
            if (preg_match($rx, $class)) {
                $this->userDefinedClassesAndInterfaces[] = $class;
            }
        }
        foreach(get_declared_interfaces() as $interface) {
            if (preg_match($rx, $interface)) {
                $this->userDefinedClassesAndInterfaces[] = $interface;
            }
        }
        return $this->userDefinedClassesAndInterfaces;
    }

    private function shortType(string $type): string
    {
        if (strpos($type, '\\') === false) {
            return $type;
        }
        preg_match('#\\\([a-zA-Z0-9_]+)$#', $type, $m);
        return $m[1];
    }

    private function shortTypes(array $types): array
    {
        $ret = [];
        foreach ($types as $type) {
            $ret[] = $this->shortType($type);
        }
        return $ret;
    }

    private function cleanDocblockTypes(array $docblockTypes): array
    {
        $ret = [];
        foreach ($docblockTypes as $docblockType) {
            $docblockType = str_replace(['(', ')'], '', $docblockType);
            if (strpos($docblockType, '[]') !== false) {
                $docblockType = 'array';
            }
            $ret[] = $docblockType;
        }
        $ret = array_unique($ret);
        return $ret;
    }

    private function staticScanClasses(
        string $dir = 'vendor',
        array &$ret = []
    ): array {
        $methodAnalyser = MethodAnalyser::getInstance();
        foreach (scandir($dir) as $filename) {
            if (in_array($filename, ['.', '..'])) {
                continue;
            }
            $path = "$dir/$filename";
            if (strpos($path, '/tests/') !== false) {
                continue;
            }
            if (!preg_match('#vendor/(silverstripe|symbiote|dnadesign)#', $path)) {
                continue;
            }
            if (is_dir($path)) {
                $this->staticScanClasses($path, $ret);
            }
            if (pathinfo($filename, PATHINFO_EXTENSION) != 'php') {
                continue;
            }
            $className = str_replace('.php', '', $filename);
            preg_match('#namespace ([a-zA-Z0-9\\\]+)+#', file_get_contents($path), $m);
            $namespace = $m[1] ?? '';
            $fqcn = $namespace ? "$namespace\\$className" : $className;
            try {
                // don't include interfaces and things like _register_database
                if (!class_exists($fqcn) && !trait_exists($fqcn)) {
                    continue;
                }
            } catch (\Error $e) {
                // Interface 'Composer\Plugin\Capability\CommandProvider' not found, etc
                // probably this comes from dead code of some sort
                continue;
            }
            $ret[$fqcn] = [];
            // ReflectionClass also works on traits
            $reflClass = new ReflectionClass($fqcn);
            $s = file_get_contents($reflClass->getFileName());
            foreach ($reflClass->getMethods() as $reflMethod) {
                // don't count inherited methods
                $method = $reflMethod->getName();
                if (!preg_match("#function &?$method ?\(#", $s)) {
                    continue;
                }
                $methodData = $methodAnalyser->getMethodData($reflClass, $reflMethod);
                $ret[$fqcn][$method] = $methodData;
            }
        }
        return $ret;
    }
}
