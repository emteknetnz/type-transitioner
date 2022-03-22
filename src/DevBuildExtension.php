<?php

namespace emteknetnz\TypeTransitioner;

use emteknetnz\TypeTransitioner\CodeUpdater;
use SilverStripe\ORM\DataExtension;

/*
Trying this instead of doing code writing CodeUpdate implements Flushable,
which seemed to create weird errors in CI, though only when running direcltly on framework module e.g.

There were 34 errors:

1) SilverStripe\Control\Tests\ControllerTest::testDefaultAction
Error: Class 'League\Container\ServiceProvider\AbstractServiceProvider' not found in /home/runner/work/silverstripe-framework/silverstripe-framework/vendor/intervention/image/src/Intervention/Image/ImageServiceProviderLeague.php:7
Stack trace:
#0 /home/runner/work/silverstripe-framework/silverstripe-framework/vendor/composer/ClassLoader.php(571): include()
#1 /home/runner/work/silverstripe-framework/silverstripe-framework/vendor/composer/ClassLoader.php(428): Composer\Autoload\includeFile()
#2 [internal function]: Composer\Autoload\ClassLoader->loadClass()
#3 [internal function]: spl_autoload_call()
#4 /home/runner/work/silverstripe-framework/silverstripe-framework/vendor/emteknetnz/type-transitioner/src/CodeUpdater.php(80): ReflectionClass->__construct()
#5 /home/runner/work/silverstripe-framework/silverstripe-framework/vendor/emteknetnz/type-transitioner/src/CodeUpdater.php(14): emteknetnz\TypeTransitioner\CodeUpdater->updateCode()
#6 /home/runner/work/silverstripe-framework/silverstripe-framework/src/Dev/State/FlushableTestState.php(43): emteknetnz\TypeTransitioner\CodeUpdater::flush()
*/

class DevBuildExtension extends DataExtension
{
    public function onAfterBuild()
    {
        CodeUpdater::getInstance()->updateCode();
    }
}
