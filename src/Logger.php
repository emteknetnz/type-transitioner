<?php

namespace emteknetnz\TypeTransitioner;

class Logger extends Singleton
{
    private $lines = [];

    private $logFileExists = false;

    private $fh = null;

    public function writeLine(string $line): void
    {
        $this->ensureLogFileExists();
        if (!$this->fh) {
            $this->fh = fopen($this->getPath(), 'a');
        }
        if (empty($this->lines)) {
            $existingLogFile = file_get_contents($this->getPath());
            if (empty($existingLogFile)) {
                // add header if missing
                $ln = $this->getHeaderLine();
                fwrite($this->fh, $ln . "\n");
                $this->lines[$ln] = true;
            } else {
                // hydrate $this->lines with existing contents of log file
                foreach (explode("\n", $existingLogFile) as $ln) {
                    $this->lines[$ln] = true;
                }
            }
        }
        // only write unique lines
        if (array_key_exists($line, $this->lines)) {
            return;
        }
        $line = trim($line, "\n");
        fwrite($this->fh, $line . "\n");
        $this->lines[$line] = true;
    }

    private function ensureLogFileExists(): void
    {
        if ($this->logFileExists) {
            return;
        }
        $path = $this->getPath();
        if (file_exists($path)) {
            $this->logFileExists = true;
            return;
        }
        $dir = dirname($path);
        if (!file_exists($dir)) {
            mkdir($dir);
        }
        $this->logFileExists = true;
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
