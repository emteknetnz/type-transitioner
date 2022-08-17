<?php

namespace emteknetnz\TypeTransitioner;

use PhpParser\Error;
use PhpParser\Lexer;
use PhpParser\ParserFactory;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;

function log($s)
{
    StrongTyper::getInstance()->log($s);
}

// This is used to strongly type methods based on trace results ett.txt
class StrongTyper extends Singleton
{
    private $classesAndInterfaces = null;

    public function codeWrite()
    {
        $combined = TraceResults::getInstance()->read();
        foreach (array_keys($combined) as $fqcn) {
            foreach (array_keys($combined[$fqcn]) as $methodName) {
                // log($fqcn . '::' . $methodName);
            }
            $reflClass = new \ReflectionClass($fqcn);
            $filename = $reflClass->getFileName();
            if (strpos($filename, 'vendor/silverstripe/framework/') === false) {
                continue;
            }
            if (strpos($filename, '/thirdparty/') !== false) {
                continue;
            }
            $code = file_get_contents($filename);
            $ast = $this->getAst($code);
            $classes = $this->getClasses($ast);
            if (empty($classes)) {
                $classes = ['nonclass'];
            }
            // reverse methods so 'updating from the bottom' so that character offests remain correct
            $classes = array_reverse($classes);
            foreach ($classes as $class) {
                if ($class == 'nonclass') {
                    continue;
                }
                //log($class->name);
                $methods = $this->getMethods($class);
                $methods = array_reverse($methods);
                /** @var ClassMethod $method */
                foreach ($methods as $method) {
                    $methodName = $method->name->name;
                    $res = $combined[$fqcn][$methodName];
                    if (!$res['trace']['traced']) {
                        continue;
                    }
                    // print_r($res);die;
                    $start = $method->getStartFilePos();
                    $end = $method->getEndFilePos();
                    $methodStr = substr($code, $start, $end);
                    preg_match('#(\(.+?{)#s', $methodStr, $m);
                    $methodSig = $m[1];
                    preg_match('#\((.*?)\)#s', $methodSig, $m);
                    $paramSig = $m[1];
                    preg_match('#(\).*?{)#s', $methodSig, $m);
                    $returnSig = $m[1];
                    foreach (preg_split('#, ?(?=[A-Za-z\$])#', $paramSig) as $paramStr) {
                        if (empty($paramStr)) {
                            // no params
                            continue;
                        }
                        preg_match('#(\$[a-zA-Z0-9_]+)#', $paramStr, $m);
                        if (!isset($m[1])) {
                            // something too hard, such as
                            // $searchableClasses = [SiteTree::class, File::class]
                            continue;
                        }
                        $paramName = $m[1];
                        $strongType = $res['methodParamTypes'][$paramName];
                        if ($strongType != 'DYNAMIC') {
                            continue;
                        }
                        $flags = $res['methodParamFlags'][$paramName];
                        if (!isset($res['trace']['results']['params'][$paramName]['argTypes'])) {
                            // no trace results
                            continue;
                        }
                        $argTypes = $res['trace']['results']['params'][$paramName]['argTypes'];
                        $isOptional = ($flags & MethodAnalyser::ETT_OPTIONAL) == MethodAnalyser::ETT_OPTIONAL;
                        if ($isOptional) {
                            // treat optional argtype as if it was also traced
                            preg_match('#=\s?(.+)$#', $paramStr, $m);
                            $var = $m[1] ?? '';
                            if (substr($var, 0, 1) !== '$') {
                                // too hard e.g. $relativeParent = self::BASE
                                continue;
                            }
                            $optionalVal = eval($var . ';');
                            $optionalType = MethodAnalyser::getInstance()->getArgType($optionalVal);
                            $argTypes[$optionalType] = true;
                        }
                        $isReference = ($flags & MethodAnalyser::ETT_REFERENCE) == MethodAnalyser::ETT_REFERENCE;
                        $isVariadic = ($flags & MethodAnalyser::ETT_VARIADIC) == MethodAnalyser::ETT_VARIADIC;
                        $hasNull = array_key_exists('null', $argTypes);
                        if ($hasNull) {
                            unset($argTypes['null']);
                        }
                        $argTypes = array_filter($argTypes, fn(string $argType) => $argType != 'null');
                        if (empty($argTypes)) {
                            // null was the only traced value
                            continue;
                        }
                        if (count($argTypes) == 0 && $hasNull) {
                            $argType = array_keys($argTypes)[0];
                            unset($argTypes[$argType]);
                            $argTypes['?' . $argType] = true;
                        }
                        $paramType = implode('|', array_keys($argTypes));
                        log($paramType);
                        // print_r($res);

                        //print_r($res);die;
                        // log($param);
                    }
                    // print_r([$methodSig, $paramSig, $returnSig]);die;
                    
                    //log($class->name . '::' . $method->name);
                }
            }
        }
        $this->printLog();
    }

    private function reduceToCommonAncestors(array $argTypes): array
    {
        $scalarArgTypes = array_filter($argTypes, fn(string $argType) => preg_match('#^[a-z#', $argType));
        $objectArgTypes = array_filter($argTypes, fn(string $argType) => preg_match('#^[A-Z#', $argType));
        if (empty($objectArgTypes) || count($objectArgTypes) == 1) {
            return $argTypes;
        }
        $reducedObjectArgTypes = [];
        for ($i = 0; $i < count($objectArgTypes); $i++) {
            $argTypeI = $objectArgTypes[$i];
            for ($j = 0; $j < count($objectArgTypes); $j++) {
                if ($i == $j) {
                    continue;
                }
                $argTypeJ = $objectArgTypes[$j];
            }
        }
        return array_merge($scalarArgTypes, $reducedObjectArgTypes);
    }

    private function getClassLineage(object $object){
        $classes = array_values(class_parents($object));
        $interfaces = array_values(class_implements($object));
        return array_merge([get_class($object)], $classes, $interfaces);
    }

    private function getClassesAndInterfaces(): array
    {
        if (!is_null($this->classesAndInterfaces)) {
            return $this->classesAndInterfaces;
        }
        $this->classesAndInterfaces = [];
        foreach(get_declared_classes() as $class) {
            $this->classesAndInterfaces[] = $class;
        }
        foreach(get_declared_interfaces() as $interface) {
            $this->classesAndInterfaces[] = $interface;
        }
        return $this->classesAndInterfaces;
    }

    // ===

    private function getAst(string $code): array
    {
        $lexer = new Lexer([
            'usedAttributes' => [
                'comments',
                'startLine',
                'endLine',
                //'startTokenPos',
                //'endTokenPos',
                'startFilePos',
                'endFilePos'
            ]
        ]);
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7, $lexer);
        try {
            $ast = $parser->parse($code);
        } catch (Error $error) {
            echo "Parse error: {$error->getMessage()}\n";
            die;
        }
        return $ast;
    }

    private function getClasses(array $ast): array
    {
        $ret = [];
        $a = ($ast[0] ?? null) instanceof Namespace_ ? $ast[0]->stmts : $ast;
        $ret = array_merge($ret, array_filter($a, fn($v) => $v instanceof Class_));
        // SapphireTest and other file with dual classes
        $i = array_filter($a, fn($v) => $v instanceof If_);
        foreach ($i as $if) {
            foreach ($if->stmts ?? [] as $v) {
                if ($v instanceof Class_) {
                    $ret[] = $v;
                }
            }
        }
        return $ret;
    }

    private function getMethods(Class_ $class): array
    {
        return array_filter($class->stmts, fn($v) => $v instanceof ClassMethod);
    }

    // ===

    private $log = [];

    public function log($s)
    {
        $this->log[] = $s;
    }

    private function printLog()
    {
        $log = array_unique($this->log);
        sort($log);
        foreach ($log as $r) {
            echo "$r\n";
        }
        die;
    }
}