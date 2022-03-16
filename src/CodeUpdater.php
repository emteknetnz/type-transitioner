<?php

namespace emteknetnz\TypeTransitioner;

use ReflectionClass;

class CodeUpdater extends Singleton
{
    private $updatingCode = false;

    public function isUpdatingCode(): bool
    {
        return $this->updatingCode;
    }

    public function updateCode()
    {
        $methodAnalyser = MethodAnalyser::getInstance();
        $this->updatingCode = true;
        $this->updateFrameworkConstants();
        
        $path = str_replace('//', '/', BASE_PATH . '/vendor/silverstripe/framework');;
        if (!file_exists($path)) {
            // Running CI on framework module
            $path = str_replace('//', '/', BASE_PATH);
        }
        $paths = explode("\n", shell_exec("find {$path} | grep .php$"));
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
            $reflClass = new ReflectionClass($fqcn);
            $methodDataArr = [];
            foreach ($reflClass->getMethods() as $reflMethod) {
                $name = $reflMethod->getName();
                // ReflectionClass::getMethods() sorts the methods by class (lowest in the inheritance tree first)
                // so as soon as we find an inherited method not in the $contents, we can break
                if (!preg_match("#function {$name} ?\(#", $contents)) {
                    break;
                }
                $methodDataArr[] = $methodAnalyser->getMethodData($reflClass, $reflMethod);
            }
            // only include methods with dynamic params or a dynamic return type
            $methodDataArr = array_filter($methodDataArr, function (array $data) {
                if ($data['abstract']) {
                    return false;
                }
                $hasDynamicParams = !empty(array_filter($data['methodParamTypes'], fn(string $type) => $type == 'dynamic'));
                // TODO: return types
                // return $hasDynamicParams || $o['methodReturn'] == 'dynamic';
                return $hasDynamicParams;
            });
            /** @var array $m */
            $changed = false;
            foreach ($methodDataArr as $methodData) {
                $calls = [];
                $writeA = false;
                // $paramNum will start at 1 to match PHP error messages, e.g.
                // "[Warning] nl2br() expects parameter 1 to be string, array given"
                // where parameter 1 is the first paramter
                $paramNum = 0;
                $method = $methodData['method'];
                foreach ($methodData['methodParamTypes'] as $paramName => $methodParamType) {
                    $paramNum++;
                    $flags = $methodData['methodParamFlags'][$paramName];
                    if ($methodParamType != 'dynamic') {
                        continue;
                    }
                    // if (($methodData['methodParamFlags'][$paramName] & ETT_REFERENCE) == ETT_REFERENCE) {
                    //     continue;
                    // }
                    // variadic params are always an array, so no need to log
                    if (($flags & MethodAnalyser::ETT_VARIADIC) == MethodAnalyser::ETT_VARIADIC) {
                        continue;
                    }
                    // these methods are used by _c() so exclude as to not cause infinite loop
                    if ($class == 'ClassInfo' && in_array($method, ['ancestry', 'class_name'])) {
                        continue;
                    }
                    $docblockTypeStr = $methodData['docblockParams'][$paramName] ?? 'dynamic';
                    if (strpos($docblockTypeStr, 'mixed') !== false) {
                        continue;
                    }
                    if ($docblockTypeStr != 'dynamic') {
                        $docblockTypeStr = $methodAnalyser->cleanDocblockTypeStr($docblockTypeStr);
                        
                        $calls[] = "_c('{$docblockTypeStr}', {$paramName}, {$paramNum});";
                    }
                    $writeA = true;
                }
                if ($writeA) {
                    array_unshift($calls, '_a();');
                }
                if (empty($calls)) {
                    continue;
                }
                preg_match("#(?s)(function {$method} ?\(.+)#", $contents, $m);
                $fncontents = $m[1];
                $callsStr = implode("\n        ", $calls);
                $newfncontents = preg_replace('#{#', "{\n        $callsStr", $fncontents, 1);
                $contents = str_replace($fncontents, $newfncontents, $contents);
                $changed = true;
            }
            if (!$changed) {
                continue;
            }
            file_put_contents($path, $contents);
            $this->updatingCode = false;
        }
    }

    // TODO: something more proper
    // ! this is pretty horrid - will make it look like we have fresh changes to git commit in framework
    // 
    // need to update framework constants.php, which loads as part of the index.php during
    // require __DIR__ . '/../vendor/autoload.php';
    // i.e. before autoloader has loaded emteknetnz functions.php
    private function updateFrameworkConstants()
    {
        $path = str_replace('//', '/', BASE_PATH . '/vendor/silverstripe/framework/src/includes/constants.php');
        if (!file_exists($path)) {
            // Running CI on framework module
            $path = str_replace('//', '/', BASE_PATH . '/src/includes/constants.php');
        }
        $contents = file_get_contents($path);
        if (strpos($contents, 'vendor/emteknetnz/type-transitioner') !== false) {
            return;
        }
        $search = "require_once __DIR__ . '/functions.php';";
        $functionsPath = str_replace('//', '/', BASE_PATH . '/vendor/emteknetnz/type-transitioner/src/functions.php');
        $newLine = "require_once '{$functionsPath}';";
        file_put_contents($path, str_replace($search, "$newLine\n$search", $contents));
    }
}
