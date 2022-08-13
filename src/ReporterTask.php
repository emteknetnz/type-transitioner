<?php

use emteknetnz\TypeTransitioner\Reporter;
use SilverStripe\Dev\BuildTask;

class ReporterTask extends BuildTask
{
    public function run($request)
    {
        $reporter = new Reporter();
        $reporter->report();
    }
}
