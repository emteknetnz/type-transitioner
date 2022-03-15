<?php

namespace emteknetnz\TypeTransitioner;

use SilverStripe\Core\Flushable;

class Logger extends Singleton implements Flushable
{
    private $lines = [];

    public static function flush(): void
    {
        self::getInstance()->initLogFile();
    }

    public function initLogFile(): void
    {
        $path = $this->getPath();
        $dir = dirname($path);
        if (!file_exists($dir)) {
            mkdir($dir);
        }
        $headers = implode(',', [
            'callingFile',
            'callingLine',
            'calledClass',
            'calledMethod',
            'paramName',
            'paramWhere',
            'paramType',
            'argType'
        ]) . "\n";
        file_put_contents($path, $headers);
    }

    public function writeLine(string $line): void
    {
        // only write unique lines
        $key = md5($line);
        if (array_key_exists($key, $this->lines)) {
            return;
        }
        $line = trim($line, "\n") . "\n";
        file_put_contents($this->getPath(), $line, FILE_APPEND);
        $this->lines[$key] = true;
    }

    private function getPath(): string
    {
        return str_replace('//', '/', BASE_PATH . '/artifacts/ett.txt');
    }
}
