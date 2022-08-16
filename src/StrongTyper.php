<?php

namespace emteknetnz\TypeTransitioner;

use PhpParser\Error;
use PhpParser\Lexer;
use PhpParser\Node\Stmt\Return_;
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
    public function codeWrite()
    {
        $combined = TraceResults::getInstance()->read();
        foreach (array_keys($combined) as $fqcn) {
            foreach (array_keys($combined[$fqcn]) as $methodName) {
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
                        $methods = ['nonclass'];
                    } else {
                        $methods = $this->getMethods($class);
                    }
                    $methods = array_reverse($methods);
                    /** @var ClassMethod $method */
                    foreach ($methods as $method) {
                        // $name = $method <<<<
                    }
                }

            }
        }
        $this->printLog();
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