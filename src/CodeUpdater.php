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

    private function getNamespace(string $contents): string
    {
        if (preg_match('#\nnamespace (.+?);#', $contents, $m)) {
            return $m[1];
        }
        return '';
    }
    // use InvalidArgumentException;
    // use SilverStripe\Core\Config\Config;
    // use SilverStripe\Core\Convert;
    private function getImports(string $contents): array
    {
        preg_match_all("#\nuse (.+);\n#", $contents, $m);
        return $m[1];
    }

    private function getClass(string $contents): string
    {
        if (preg_match('#(\nabstract |\n)class (.+?)[ \n]#', $contents, $m)) {
            return $m[2];
        }
        return '';
    }

    private function getAbsPathForClass(string $class): string
    {
        $a = explode('\\', $class);
        $shortClassName = array_pop($a);
        $namespace = implode('\\', $a);
        $path = $this->absPath('vendor/silverstripe');
        $res = shell_exec("find {$path} | grep /{$shortClassName}.php");
        $arr = explode("\n", $res);
        $arr = array_filter($arr);
        if (count($arr) == 0) {
            return '';
        }
        if (count($arr) == 1) {
            return $arr[0];
        }
        foreach ($arr as $path) {
            $contents = file_get_contents($path);
            if (strpos($contents, "namespace $namespace;") !== false) {
                return $path;
            }
        }
        return '';
    }

    private function absPath(string $relPath)
    {
        return str_replace(['///', '//'], '/', BASE_PATH . '/' . $relPath);
    }

    public function updateDocblock(
        string $calledClass,
        string $calledMethod,
        string $paramName,
        string $paramType, // aka docblockStr
        array $argTypes
    ) {
        $path = $this->getAbsPathForClass($calledClass);
        if (!$path) {
            echo "Did not find path for $calledClass";die;
        }
        $oldParamType = $paramType;
        $oldParamTypes = explode('|', $oldParamType);
        $newParamType = implode('|', $argTypes);
        $newImports = [];
        $types = [];
        foreach (explode('|', $newParamType) as $type) {
            if (strpos($type, '\\') === false) {
                $types[] = $type;
            } else {
                // change fqcn to short class name and import namespace;
                preg_match('#^(.+)\\\\([^\\\\]+)$#', $type, $m);
                $newImports[] = $type;
                $types[] = $m[2];
            }
        }
        // predefined interfaces
        // some docblocks have SomeClass|ArrayAccess type of docblock type
        // this module won't detect a interface as an argument type, so add the, into the mix
        $interfaces = [
            'Traversable',
            'Iterator',
            'IteratorAggregate',
            'Throwable',
            'ArrayAccess',
            'Serializable',
            'Stringable',
            'UnitEnum',
            'BackedEnum',
        ];
        foreach ($interfaces as $interface) {
            if (in_array($interface, $oldParamTypes)) {
                if (!in_array($interface, $types)) {
                    $types[] = $interface;
                }
            }
        }
        // consolodate types
        $newTypes = [];
        $childTypes = [];
        foreach ($types as $t1) {
            foreach ($types as $t2) {
                if ($t1 == $t2) {
                    continue;
                }
                if (is_subclass_of($t1, $t2)) {
                    $childTypes[] = $t1;
                }
                if (is_subclass_of($t2, $t1)) {
                    $childTypes[] = $t2;
                }
            }
        }
        foreach ($types as $type) {
            if (!in_array($type, $childTypes)) {
                $newTypes[] = $type;
            }
        }
        // move null to the end of types
        usort($types, fn($a, $b) => $a == 'null' ? 1 : ($b == 'null' ? -1 : 0));

        $newParamType = implode('|', $types);

        $contents = file_get_contents($path);
        preg_match("#(?s)/\*\*.+?\*/\n[^\n]+function $calledMethod#", $contents, $m);
        $oldBlock = $m[0];
        $newBlock = preg_replace(
            sprintf(
                "#\*[ \t]+@param[ \t]+%s[ \t]+%s#",
                str_replace(['|', '$'], ['\\|', '\\$'], $oldParamType),
                str_replace('$', '\\$', $paramName)
            ),
            "* @param {$newParamType} {$paramName}",
            $oldBlock
        );
        $contents = str_replace($oldBlock, $newBlock, $contents);

        // update use statements
        $namespace = $this->getNamespace($contents);
        $imports = $this->getImports($contents);
        $addImports = [];
        foreach ($newImports as $newImport) {
            // is new import already in the namespace
            if (preg_match("#^{$namespace}\\[a-zA-Z0-9_]+$#", $newImport)) {
                continue;
            }
            // is the new import already imported?
            if (in_array($newImport, $imports)) {
                continue;
            }
            $importStr = 'use ' . $newImport . ';';
            if (strpos($contents, $importStr) === false) {
                $addImports[] = $importStr;
            }
        }
        if (!empty($addImports)) {
            $match = $namespace ? "namespace $namespace;" : '<?php';
            $contents = str_replace("$match\n\n", "$match\n\n" . implode("\n", $addImports) . "\n", $contents);
        }

        file_put_contents($path, $contents);
        echo "Update docblock params for $calledClass::$calledMethod\n";
    }

    public function updateCode()
    {
        $config = Config::getInstance();
        if (!$config->get(Config::CODE_UPDATE_A) && !$config->get(Config::CODE_UPDATE_C)) {
            return;
        }
        $methodAnalyser = MethodAnalyser::getInstance();
        $this->updatingCode = true;
        $this->updateFrameworkConstants();
        if ($config->get(Config::CODE_UPDATE_A)) {
            // writing _a() is dev only, so is behat
            $this->updateBehatTimeout();
        }

        $path = $this->absPath('vendor/silverstripe/framework');
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
            $namespace = $this->getNamespace($contents);
            $class = $this->getClass($contents);
            if (!$class) {
                // echo "Could not find class for path $path\n";
                continue;
            }
            // don't update this, as apply nasty hack to this for behat
            if ($class == 'TestSessionEnvironment') {
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
                    // if ($class == 'ClassInfo' && in_array($method, ['ancestry', 'class_name'])) {
                    //     continue;
                    // }
                    $docblockTypeStr = $methodData['docblockParams'][$paramName] ?? 'dynamic';
                    if (strpos($docblockTypeStr, 'mixed') !== false) {
                        continue;
                    }
                    if ($docblockTypeStr != 'dynamic') {
                        $docblockTypeStr = $methodAnalyser->cleanDocblockTypeStr($docblockTypeStr);

                        if ($config->get(Config::CODE_UPDATE_C)) {
                            $calls[] = "_c('{$docblockTypeStr}', {$paramName}, {$paramNum});";
                        }
                    }
                    $writeA = true;
                }
                if ($config->get(Config::CODE_UPDATE_A) && $writeA) {
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
        $path = $this->absPath('vendor/silverstripe/framework/src/includes/constants.php');
        if (!file_exists($path)) {
            // Running CI on framework module
            $path = $this->absPath('src/includes/constants.php');
        }
        $contents = file_get_contents($path);
        if (strpos($contents, 'vendor/emteknetnz/type-transitioner') !== false) {
            return;
        }
        $search = "require_once __DIR__ . '/functions.php';";
        $functionsPath = $this->absPath('vendor/emteknetnz/type-transitioner/src/functions.php');
        $newLine = "require_once '{$functionsPath}';";
        file_put_contents($path, str_replace($search, "$newLine\n$search", $contents));
    }

    /**
     * Extremely nasty hack to increase the behat curl timeout from 30 seconds to 10 minutes
     * Because _a() while running behat is EXTREMELY slow.
     * Often times out after 30 seconds when running in Github Actions CI
     *
     * There's no easy way to update this option so resorting to this instead
     */
    private function updateBehatTimeout()
    {
        $path = $this->absPath('vendor/php-webdriver/webdriver/lib/Remote/HttpCommandExecutor.php');
        if (file_exists($path)) {
            $str = file_get_contents($path);
            $str = str_replace('30000', '600000', $str);
            file_put_contents($path, $str);
        }
        $path = $this->absPath('vendor/silverstripe/testsession/src/TestSessionEnvironment.php');
        if (file_exists($path)) {
            $str = file_get_contents($path);
            $str = str_replace('$timeout = 10000', '$timeout = 600000', $str);
            file_put_contents($path, $str);
        }
    }
}
