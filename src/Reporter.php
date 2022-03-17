<?php

namespace emteknetnz\TypeTransitioner;

use PhpParser\Builder\Method;

// Parses log file and creates report
class Reporter
{
    function report()
    {
        $dir = BASE_PATH . '/artifacts/combined';
        $combined = false;
        $lines = [];
        if (file_exists($dir)) {
            $filenames = array_filter(scandir($dir), fn($fn) => strpos($fn, '.') !== 0);
            if (count($filenames) > 0) {
                $combined = true;
            }
            foreach ($filenames as $filename) {
                $lines = $lines + explode("\n", file_get_contents("$dir/$filename"));
            }
        }
        if (!$combined) {
            if (file_exists(BASE_PATH . '/artifacts/ett.txt')) {
                $lines = explode("\n", file_get_contents(BASE_PATH . '/artifacts/ett.txt'));
            } else {
                return;
            }
        }

        // unique lines (in case putting together multiple log files)
        $lines = array_unique($lines);

        // remove header line
        array_shift($lines);

        // (dynamic) -> string -- no docblock
        // (string) -> null -- null values being passed in (solved by _c() casting)
        // (string>) -> int -- wrong docblock / need to update method call
        $classMethodsWithoutDocblocks = [];
        $classMethodsWithDocblocksPassedNull = [];
        $classMethodsWrongDocblocks = [];

        $printrArr = [];
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
            if (MethodAnalyser::getInstance()->argTypeMatchesDockblockTypeStr($argType, $paramType)) {
                continue;
            }

            // docblock param (include undocblocked param) does not match arg
            $call = "({$argType}) {$callingFile}:{$callingLine}";
            $param = "({$paramType}) {$paramName}";
            $res[$calledClass][$calledMethod][$param] ??= [];
            if (!in_array($call, $res[$calledClass][$calledMethod][$param])) {
                $printrArr[$calledClass][$calledMethod][$param][] = $call;
            }

            if ($paramType == 'dynamic') {
                $classMethodsWithoutDocblocks[$calledClass][$calledMethod][$paramName][$argType] ??= [];
                $classMethodsWithoutDocblocks[$calledClass][$calledMethod][$paramName][$argType][] = "{$callingFile}:{$callingLine}";
            }
        }

        $classMethodsNewDocblocks = [];
        foreach (array_keys($classMethodsWithoutDocblocks) as $calledClass) {
            foreach (array_keys($classMethodsWithoutDocblocks[$calledClass]) as $calledMethod) {
                foreach (array_keys($classMethodsWithoutDocblocks[$calledClass][$calledMethod]) as $paramName) {
                    $argTypes = array_keys($classMethodsWithoutDocblocks[$calledClass][$calledMethod][$paramName]);
                    if (count($argTypes) == 1) {
                        $classMethodsNewDocblocks[$calledClass][$calledMethod][$paramName] = $argTypes[0];
                    } else {
                        // TODO: see if everything is non-scalar, see if there's a common parent class e.g DataObject
                        // see if null included here, to make ?DataObject.
                        // or maybe it's a string|int type of thing
                    }
                }
            }
        }
        // print_r($classMethodsWithoutDocblocks);
        print_r($classMethodsNewDocblocks);
        // print_r($printrArr);die;
        die;
    }
}