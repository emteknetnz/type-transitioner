<?php

use emteknetnz\TypeTransitioner\CodeUpdater;
use emteknetnz\TypeTransitioner\Config;
use emteknetnz\TypeTransitioner\Logger;
use emteknetnz\TypeTransitioner\MethodAnalyser;
use emteknetnz\TypeTransitioner\TypeException;

// this will get included twice
if (!function_exists('_c')) {

    function _c(string $docBlockTypeStr, &$arg, int $paramNum): void
    {
        if (CodeUpdater::getInstance()->isUpdatingCode()) {
            return;
        }
        $config = Config::getInstance();
        $nonObjectTypes = [
            'string' => true,
            'bool' => true,
            'int' => true,
            'float' => true,
            'array' => true
        ];
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
        $methodAnalyser = MethodAnalyser::getInstance();

        // Throw warnings about incorrect param types
        // PHP wrong argument errors are actually simpler, they show arguments number (starting at 1)
        // rather than the name of the variable that's wrong, e.g.
        // "[Warning] nl2br() expects parameter 1 to be string, array given"
        if ($config->get(Config::TRIGGER_E_USER_DEPRECATED) || $config->get(Config::THROW_TYPE_EXCEPTION)) {
            if (!$methodAnalyser->argMatchesDockblockTypeStr($arg, $docBlockTypeStr)) {
                $argType = $methodAnalyser->getArgType($arg);
                $backRefl = $methodAnalyser->getBacktraceReflection();
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
                        $methodAnalyser->describeType($argType),
                        $methodAnalyser->describeType($docBlockTypeStr),
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
                        $methodAnalyser->describeType($argType),
                        $methodAnalyser->describeType($docBlockTypeStr),
                    ));
                }
            }
        }
    }

    // dev only - uses reflection and backtraces
    // idea is to get info to update docblocks / strongly typed params
    function _a(): void
    {
        if (CodeUpdater::getInstance()->isUpdatingCode()) {
            return;
        }
        $logger = Logger::getInstance();
        $methodAnalyser = MethodAnalyser::getInstance();

        $backRefl = $methodAnalyser->getBacktraceReflection();
        $methodData = $backRefl['methodData'];

        $paramNames = array_keys($methodData['methodParamTypes']);
        for ($i = 0; $i < count($paramNames); $i++) {
            $paramName = $paramNames[$i];
            $arg = $backRefl['args'][$i] ?? null;
            $flags = $methodData['methodParamFlags'][$paramName];
            // null optional args are a non issue, will use default which assumed to always be valid
            if (is_null($arg) && ($flags & MethodAnalyser::ETT_OPTIONAL) == MethodAnalyser::ETT_OPTIONAL) {
                continue;
            }
            // null by reference arguments can start life as undefined and become a return var
            // of sorts e.g. $m in preg_match($rx, $subject, $m);
            if (is_null($arg) && ($flags & MethodAnalyser::ETT_REFERENCE) == MethodAnalyser::ETT_REFERENCE) {
                continue;
            }
            // variadic args are always a mixed array, no need to validate
            if (($flags & MethodAnalyser::ETT_VARIADIC) == MethodAnalyser::ETT_VARIADIC) {
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
            $argType = $methodAnalyser->getArgType($arg);
            // arg matches dockblock type, no need to log
            if ($methodAnalyser->argMatchesDockblockTypeStr($arg, $docBlockTypeStr)) {
                return;
            }
            $logger->writeLine(implode(',', [
                $backRefl['callingFile'],
                $backRefl['callingLine'],
                $backRefl['calledClass'],
                $backRefl['calledMethod'],
                $paramName,
                $paramWhere,
                $paramType,
                $argType
            ]));
        }
    }
}
