<?php

namespace emteknetnz\TypeTransitioner;

// Parses log file and creates report
class Reporter
{
    function report()
    {
        if (file_exists(BASE_PATH . '/artifacts/combined.txt')) {
            $lines = explode("\n", file_get_contents(BASE_PATH . '/artifacts/ett-combined.txt'));
        } else {
            $lines = explode("\n", file_get_contents(BASE_PATH . '/artifacts/ett.txt'));
        }

        // unique lines (in case putting together multiple log files)
        $lines = array_unique($lines);

        // remove header line
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
}