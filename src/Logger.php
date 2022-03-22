<?php

namespace emteknetnz\TypeTransitioner;

class Logger extends Singleton
{
    private $lines = [];

    public function writeLine(string $line): void
    {
        $this->ensureLogFileExists();
        if (empty($this->lines)) {
            $existingLogFile = file_get_contents($this->getPath());
            if (empty($existingLogFile)) {
                // add header if missing
                $line = $this->getHeaderLine();
                $key = md5($line);
                file_put_contents($this->getPath(), $line . "\n");
                $this->lines[$key] = true;
            } else {
                // hydrate $this->lines with existing contents of log file
                foreach (explode("\n", $existingLogFile) as $line) {
                    $key = md5($line);
                    $this->lines[$key] = true;
                }
            }
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

    public function clearLogFile(): void
    {
        $this->ensureLogFileExists();
        file_put_contents($this->getPath(), '');
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
