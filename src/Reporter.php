<?php

namespace emteknetnz\TypeTransitioner;

use Exception;
use PhpParser\Builder\Method;

// Parses log file and creates report
class Reporter
{
    function report()
    {
        $path = BASE_PATH . '/artifacts/ett.txt';
        if (!file_exists($path)) {
            echo "Missing $path\n";
            die;
        }
        $lines = explode("\n", file_get_contents(BASE_PATH . '/artifacts/ett.txt'));
        // remove header line
        array_shift($lines);

        $res = [];

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
                $res[$calledClass][$calledMethod]['params'] ??= [];
                $res[$calledClass][$calledMethod]['params'][$paramName] ??= [
                    'paramFlags' => $paramFlags,
                    'paramStrongType' => $paramStrongType,
                    'paramDocblockType' => $paramDocblockType,
                    'argTypes' => []
                ];
                $res[$calledClass][$calledMethod]['params'][$paramName]['argTypes'][$argType] = true;
            }
            if ($type == 'RETURN') {
                $res[$calledClass][$calledMethod]['return'] ??= [
                    'returnStrongType' => $returnStrongType,
                    'returnDocblockType' => $returnDocblockType,
                    'returnedTypes' => []
                ];
                $res[$calledClass][$calledMethod]['params'][$paramName]['returnedTypes'][$returnedType] = true;
            }
        }
        print_r($res);
    }

    function report_legacy()
    {
        $dir = BASE_PATH . '/ett-combined';
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

        // TODO: update this file - was all-written with dockblocks in mind, which is now somewhat irrelevant

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
            if ($calledMethod == 'setContent' && $paramType == 'string|FormField') {
                $a=1;
            }
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

            $call = "{$callingFile}:{$callingLine}";
            if ($paramType == 'DYNAMIC') {
                $classMethodsWithoutDocblocks[$calledClass][$calledMethod][$paramName][$argType] ??= [];
                $classMethodsWithoutDocblocks[$calledClass][$calledMethod][$paramName][$argType][] = $call;
            }
            if ($paramType != 'DYNAMIC' && $argType == 'null') {
                $classMethodsWithDocblocksPassedNull[$calledClass][$calledMethod][$paramName] ?? [];
                $classMethodsWithDocblocksPassedNull[$calledClass][$calledMethod][$paramName][$paramType][] = $call;
            }
            if ($paramType != 'DYNAMIC') { //  && $argType != 'null'
                // possibly combine this with $classMethodsWithDocblocksPassedNull, or at least
                // just filter out when `null` is the only $argType that's wrong
                $classMethodsWrongDocblocks[$calledClass][$calledMethod][$paramName][$paramType][$argType] ??= [];
                $classMethodsWrongDocblocks[$calledClass][$calledMethod][$paramName][$paramType][$argType][] = $call;
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

        $c=0;

        $classMethodsWrongDocblocksExOnlyNull = [];
        $a = $classMethodsWrongDocblocks;
        foreach (array_keys($a) as $calledClass) {
            foreach (array_keys($a[$calledClass]) as $calledMethod) {
                foreach (array_keys($a[$calledClass][$calledMethod]) as $paramName) {
                    foreach (array_keys($a[$calledClass][$calledMethod][$paramName]) as $paramType) {
                        $argTypes = array_keys($a[$calledClass][$calledMethod][$paramName][$paramType]);
                        if (count($argTypes) == 1 && $argTypes[0] == 'null') {
                            continue;
                        }
                        $v = $a[$calledClass][$calledMethod][$paramName];
                        $classMethodsWrongDocblocksExOnlyNull[$calledClass][$calledMethod][$paramName] = $v;

                        $c++;
                        if ($c < 100) {
                            if (false) {
                                // this messes up some files - see CodeUpdate::updateDocblock()
                                CodeUpdater::getInstance()->updateDocblock($calledClass, $calledMethod, $paramName, $paramType, $argTypes);
                            }
                        }
                    }
                }
            }
        }

        // need to check these? they'll get affected by _c() casting
        // e.g. Permission::checkMember() maybe should change from int|Member to int|Member|null
        // print_r($classMethodsWithDocblocksPassedNull);

        // us to automatically add new docblocks as well as _c() (redo updateCode() call after write new docblocks)
        // print_r($classMethodsNewDocblocks);
        // print_r($classMethodsWithoutDocblocks);

        // manually check these calls and update docblocks, then generate new _c() calls
        // probably do as seperate PRs to automatic generation above
        // print_r($classMethodsWrongDocblocksExOnlyNull);

        // print_r($printrArr);die;
        die;
    }
}
