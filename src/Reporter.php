<?php

namespace emteknetnz\TypeTransitioner;

use Exception;
use PhpParser\Builder\Method;
use ReflectionClass;

// Parses log file and creates report
class Reporter
{
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
        // print_r($res);
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
                    $traced[] = "$fqcn\\$methodName";
                } else {
                    $not_traced[] = "$fqcn\\$methodName";
                }
            }
        }
        $t = count($traced);
        $nt = count($not_traced);
        print_r([
            'traced' => $t . ' (' . round(($t / ($t + $nt) * 100), 1)  . '%)',
            'not_traced' => $nt . ' (' . round(($nt / ($t + $nt)) * 100, 1)  . '%)',
        ]);
        // print_r($not_traced);
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
