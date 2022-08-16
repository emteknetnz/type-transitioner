<?php

namespace emteknetnz\TypeTransitioner;

use Exception;
use PhpParser\Builder\Method;
use ReflectionClass;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Kernel;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ClassManifest;

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
        $debug = [];
        $traced = [];
        $not_traced = [];
        $traced_param_has_docblock = [];
        $traced_param_missing_docblock = [];
        $traced_arg_in_docblock = [];
        $traced_arg_null_not_in_docblock = [];
        $traced_arg_in_docblock_as_mixed = [];
        $traced_arg_not_in_docblock = [];
        $traced_return_has_docblock = [];
        $traced_return_missing_docblock = [];
        $traced_return_in_docblock = [];
        $traced_return_in_docblock_as_mixed = [];
        $traced_return_not_in_docblock = [];
        $not_traced_param_has_docblock = [];
        $not_traced_param_missing_docblock = [];
        $not_traced_return_has_docblock = [];
        $not_traced_return_missing_docblock = [];

        // regerenate the class manaifest including TestOnly objects
        // this is so that TestOnly args match a docblock with DataObject
        $kernel = Injector::inst()->get(Kernel::class);
        /** @var ClassManifest $classManifest */
        $classManifest = $kernel->getClassLoader()->getManifest();
        $classManifest->regenerate(true);

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
                    # params
                    #foreach ($combined[$fqcn][$methodName]['trace']['results']['params'] ?? [] as $paramName => $paramData) {
                    $methodData = $combined[$fqcn][$methodName];
                    foreach ($methodData['methodParamNames'] as $paramName) {
                        $iden = "$fqcn::$methodName:$paramName";
                        $paramData = $combined[$fqcn][$methodName]['trace']['results']['params'][$paramName] ?? [];
                        // only a tiny number (5) were missing $paramData, not worth worrying about
                        if (!empty($paramData)) {
                            if ($paramData['paramDocblockType'] != 'DYNAMIC') {
                                $traced_param_has_docblock[] = $iden;
                                $argTypes = $paramData['argTypes'];
                                $docblockTypes = $this->cleanDocblockTypes(explode('|', $paramData['paramDocblockType']));
                                foreach (array_keys($argTypes) as $argType) {
                                    if ($this->actualTypeIsInstanceOfDocblockTypes($argType, $docblockTypes, $fqcn)) {
                                        $traced_arg_in_docblock[] = $iden;
                                        break;
                                    } elseif ($argType === 'null') {
                                        $traced_arg_null_not_in_docblock[] = $iden;
                                        break;
                                    } elseif (strpos($paramData['paramDocblockType'], 'mixed') !== false) {
                                        $traced_arg_in_docblock_as_mixed[] = $iden;
                                        break;
                                    } else {
                                        $traced_arg_not_in_docblock[] = $iden;
                                    }
                                }
                            } else {
                                $traced_param_missing_docblock[] = $iden;
                            }
                        }
                    }
                    # return
                    $returnData = $combined[$fqcn][$methodName]['trace']['results']['return'] ?? [];
                    $iden = "$fqcn::$methodName";
                    if (!empty($returnData)) {
                        // only a small number (45 - 1.1%) were missing data, so safe to ignore
                        $returnDocblockTypes = $this->cleanDocblockTypes(explode('|', $returnData['returnDocblockType']));
                        if ($returnData['returnDocblockType'] != 'DYNAMIC') {
                            $traced_return_has_docblock[] = $iden;
                            $returnedTypes = $returnData['returnedTypes'];
                            foreach (array_keys($returnedTypes) as $returnedType) {
                                if ($this->actualTypeIsInstanceOfDocblockTypes($returnedType, $returnDocblockTypes, $fqcn)) {
                                    $traced_return_in_docblock[] = $iden;
                                } elseif (strpos($returnData['returnDocblockType'], 'mixed') !== false) {
                                    $traced_return_in_docblock_as_mixed[] = $iden;
                                } else {
                                    $traced_return_not_in_docblock[] = $iden;
                                }
                            }
                        } else {
                            $traced_return_missing_docblock[] = $iden;
                        }
                    }
                } else {
                    $not_traced[] = "$fqcn::$methodName";
                    // of those untraced, what is docblock coverage
                    $methodData = $combined[$fqcn][$methodName];
                    foreach ($methodData['methodParamNames'] as $paramName) {
                        $iden = "$fqcn::$methodName:$paramName";
                        if ($methodData['docblockParams'][$paramName] != 'DYNAMIC') {
                            $not_traced_param_has_docblock[] = $iden;
                        } else {
                            $not_traced_param_missing_docblock[] = $iden;
                        }
                    }
                    $iden = "$fqcn::$methodName";
                    if ($methodData['docblockReturn'] != 'DYNAMIC' || $methodData['methodReturn'] == 'void') {
                        $not_traced_return_has_docblock[] = $iden;
                    } else {
                        $not_traced_return_missing_docblock[] = $iden;
                    }
                }
            }
        }
        // $debug = array_unique($debug);
        // sort($debug);
        // print_r($debug);

        $t = count($traced);
        $nt = count($not_traced);
        $tphd = count($traced_param_has_docblock);
        $tpmd = count($traced_param_missing_docblock);
        $taid = count($traced_arg_in_docblock);
        $tannid = count($traced_arg_null_not_in_docblock);
        $taidam = count($traced_arg_in_docblock_as_mixed);
        $tanid = count($traced_arg_not_in_docblock);
        $trhd = count($traced_return_has_docblock);
        $trmd = count($traced_return_missing_docblock);
        $trid = count($traced_return_in_docblock);
        $tridam = count($traced_return_in_docblock_as_mixed);
        $trnid = count($traced_return_not_in_docblock);
        $ntphd = count($not_traced_param_has_docblock);
        $ntpmd = count($not_traced_param_missing_docblock);
        $ntrhd = count($not_traced_return_has_docblock);
        $ntrmd = count($not_traced_return_missing_docblock);

        print_r([
            'method_traced' => $t . ' (' . round(($t / ($t + $nt) * 100), 1) . '%)',
            'method_not_traced' => $nt . ' (' . round(($nt / ($t + $nt)) * 100, 1) . '%)',
            '-' => '',
            'traced_param_has_docblock' => $tphd . ' (' . round(($tphd / ($tphd + $tpmd)) * 100, 1) . '%)',
            'traced_param_missing_docblock' => $tpmd . ' (' . round(($tpmd / ($tphd + $tpmd)) * 100, 1) . '%)',
            '--' => '',
            'traced_arg_in_docblock' => $taid . ' (' . round(($taid / ($taid + $tannid + $taidam + $tanid)) * 100, 1) . '%)',
            'traced_arg_null_not_in_docblock' => $tannid . ' (' . round(($tannid / ($taid + $tannid + $taidam + $tanid)) * 100, 1) . '%)',
            'traced_arg_in_docblock_as_mixed' => $taidam . ' (' . round(($taidam / ($taid + $tannid + $taidam + $tanid)) * 100, 1) . '%)',
            'traced_arg_not_in_docblock' => $tanid . ' (' . round(($tanid / ($taid + $tannid + $taidam + $tanid)) * 100, 1) . '%)',
            '---' => '',
            'traced_return_has_docblock' => $trhd . ' (' . round(($trhd / ($trhd + $trmd)) * 100, 1) . '%)',
            'traced_return_missing_docblock' => $trmd . ' (' . round(($trmd / ($trhd + $trmd)) * 100, 1) . '%)',
            '----' => '',
            'traced_return_in_docblock' => $trid . ' (' . round(($trid / ($trid + $tridam + $trnid)) * 100, 1) . '%)',
            'traced_return_in_docblock_as_mixed' => $tridam . ' (' . round(($tridam / ($trid + $tridam + $trnid)) * 100, 1) . '%)',
            'traced_return_not_in_docblock' => $trnid . ' (' . round(($trnid / ($trid + $tridam + $trnid)) * 100, 1) . '%)',
            '-----' => '',
            'not_traced_param_has_docblock' => $ntphd . ' (' . round(($ntphd / ($ntphd + $ntpmd)) * 100, 1) . '%)',
            'not_traced_param_missing_docblock' => $ntpmd . ' (' . round(($ntpmd / ($ntphd + $ntpmd)) * 100, 1) . '%)',
            '------' => '',
            'not_traced_return_has_docblock' => $ntrhd . ' (' . round(($ntrhd / ($ntrhd + $ntrmd)) * 100, 1) . '%)',
            'not_traced_return_missing_docblock' => $ntrmd . ' (' . round(($ntrmd / ($ntrhd + $ntrmd)) * 100, 1) . '%)',
        ]);
        // print_r($not_traced);
    }

    private function actualTypeIsInstanceOfDocblockTypes(string $argType, array $docblockTypes, string $fqcn): bool
    {
        if (substr($argType, 0, 5) == 'Mock_') {
            preg_match('#^Mock_(.+?)_[a-f0-9]{8}$#', $argType, $m);
            $argType = $m[1];
        }
        if ($argType == 'nothing') {
            // allows docblock types of void to pass
            $argType = 'void';
        }
        $classesAndInterfaces = $this->getUserDefinedClassesAndInterfaces();
        foreach ($docblockTypes as $docblockType) {
            if (strtolower($docblockType) == 'boolean') {
                $docblockType = 'bool';
            }
            if (strtolower($docblockType) == 'true') {
                $docblockType = 'bool';
            }
            if (strtolower($docblockType) == 'false') {
                $docblockType = 'bool';
            }
            if (strtolower($docblockType) == 'integer') {
                $docblockType = 'int';
            }
            if (strtolower($argType) == strtolower($docblockType)) {
                return true;
            }
            if (in_array($docblockType, ['$this', 'static', 'self'])) {
                if (is_a($argType, $fqcn, true)) {
                    return true;
                }
            }
            $shortDocblockType = $this->shortType($docblockType);
            foreach ($classesAndInterfaces as $classOrInterface) {
                // making assumption that user-defined docblock types missing namespace don't collide
                $short = $this->shortType($classOrInterface);
                if (strtolower($short) != strtolower($shortDocblockType)) {
                    continue;
                }
                if (is_a($argType, $classOrInterface, true)) {
                    return true;
                }
            }
        }
        $dbt = implode('|', $docblockTypes);
        if ($dbt != 'object' && $dbt != 'mixed' && $argType != 'null') {
            $o = "argType: $argType // docblock: $dbt";
            echo "$o\n";
            if ($o == 'SilverStripe\Control\RSS\RSSFeed---DataObject|array') {
                $a=1;
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
        $rx = '#^(SilverStripe|Symbiote|DNADesign|Psr\SimpleCache|Symfony\Component)#';
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
