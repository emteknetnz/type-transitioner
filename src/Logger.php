<?php

namespace emteknetnz\TypeTransitioner;

class Logger extends Singleton
{
    private $lines = [];

    private $logFileExists = false;

    public function writeLine(string $line): void
    {
        $this->ensureLogFileExists();
        if (empty($this->lines)) {
            $existingLogFile = file_get_contents($this->getPath());
            if (empty($existingLogFile)) {
                // add header if missing
                $line = $this->getHeaderLine();
                file_put_contents($this->getPath(), $line . "\n");
                $this->lines[$line] = true;
            } else {
                // hydrate $this->lines with existing contents of log file
                foreach (explode("\n", $existingLogFile) as $line) {
                    $this->lines[$line] = true;
                }
            }
        }
        // only write unique lines
        if (array_key_exists($line, $this->lines)) {
            return;
        }
        $line = trim($line, "\n") . "\n";
        file_put_contents($this->getPath(), $line, FILE_APPEND);
        $this->lines[$line] = true;
    }

    private function ensureLogFileExists(): void
    {
        if ($this->logFileExists) {
            return;
        }
        $path = $this->getPath();
        if (file_exists($path)) {
            return;
        }
        $dir = dirname($path);
        if (!file_exists($dir)) {
            mkdir($dir);
        }
        file_put_contents($path, '');
        $this->logFileExists = true;
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
