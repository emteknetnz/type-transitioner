<?php

use emteknetnz\TypeTransitioner\CodeUpdater;
use SilverStripe\Dev\BuildTask;

class CodeUpdateTask extends BuildTask
{
    public function run($request)
    {
        CodeUpdater::getInstance()->updateCode();
    }
}
