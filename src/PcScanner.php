<?php

declare(strict_types=1);

namespace ScanPPP;

/**
 * Сканер компьютеров. Определение выполняет внешний Python-хелпер
 * (scripts/scanpc.py), который проверяет Windows-порты и возвращает JSON.
 */
final class PcScanner extends Scanner
{
    public function scan(string $ip): ?array
    {
        if (!Network::isValidIp($ip)) {
            return null;
        }

        $output = $this->runHelper($ip);
        if ($output === null) {
            return null;
        }

        $device = json_decode($output, true);

        return is_array($device) ? $device : null;
    }

    /**
     * Запускает Python-хелпер и возвращает его stdout (JSON) либо null.
     * Процесс запускается массивом аргументов (без shell), поэтому IP не
     * требует экранирования, а stderr не попадает в результат.
     */
    private function runHelper(string $ip): ?string
    {
        $binary = (string) Config::get('python.binary', 'python');
        $script = (string) Config::get('python.script', SCANPPP_ROOT . '/scripts/scanpc.py');

        if (!is_file($script)) {
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open([$binary, $script, $ip], $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_string($stdout) || trim($stdout) === '') {
            return null;
        }

        return trim($stdout);
    }
}
