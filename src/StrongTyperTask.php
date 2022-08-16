<?php

use emteknetnz\TypeTransitioner\StrongTyper;
use SilverStripe\Dev\BuildTask;

class StrongTyperTask extends BuildTask
{
    public function run($request)
    {
        StrongTyper::getInstance()->codeWrite();
    }
}
