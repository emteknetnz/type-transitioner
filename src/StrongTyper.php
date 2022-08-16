<?php

namespace emteknetnz\TypeTransitioner;

// This is used to strongly type methods based on trace results ett.txt
class StrongTyper extends Singleton
{
    public function codeWrite()
    {
        $combined = TraceResults::getInstance()->read();
        foreach (array_keys($combined) as $fqcn) {
            foreach (array_keys($combined[$fqcn]) as $methodName) {
                
            }
        }
    }
}