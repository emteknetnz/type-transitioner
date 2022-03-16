<?php

namespace emteknetnz\TypeTransitioner;

class Logger extends Singleton
{
    private $lines = [];

    public function writeLine(string $line): void
    {
        $this->ensureLogFileExists();
        // add header if missing
        if (empty($this->lines)) {
            $line = $this->getHeaderLine();
            $key = md5($line);
            file_put_contents($this->getPath(), $line . "\n");
            $this->lines[$key] = true;
        }
        // only write unique lines
        $key = md5($line);
        if (array_key_exists($key, $this->lines)) {
            return;
        }
        $line = trim($line, "\n") . "\n";
        file_put_contents($this->getPath(), $line, FILE_APPEND);
        $this->lines[$key] = true;
    }

    public function clearLogFile(): void
    {
        $this->ensureLogFileExists();
        file_put_contents($this->getPath(), '');
    }

    private function ensureLogFileExists(): void
    {
        $path = $this->getPath();
        if (file_exists($path)) {
            return;
        }
        $dir = dirname($path);
        if (!file_exists($dir)) {
            mkdir($dir);
        }
        file_put_contents($path, '');
    }

    private function getHeaderLine(): string
    {
        return implode(',', [
            'callingFile',
            'callingLine',
            'calledClass',
            'calledMethod',
            'paramName',
            'paramWhere',
            'paramType',
            'argType'
        ]);
    }

    private function getPath(): string
    {
        return str_replace('//', '/', BASE_PATH . '/artifacts/ett.txt');
    }
}
