<?php

declare(strict_types=1);

namespace ScanPPP;

/**
 * Сетевые утилиты: проверка IP-адресов, MAC-адреса, открытые порты
 * и определение локального адреса машины.
 */
final class Network
{
    public static function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Возвращает MAC-адрес хоста из ARP-таблицы (кросс-платформенно).
     */
    public static function mac(string $ip): ?string
    {
        if (!self::isValidIp($ip)) {
            return null;
        }

        $command = self::isWindows()
            ? 'arp -a ' . escapeshellarg($ip)
            : 'arp -n ' . escapeshellarg($ip);

        $output = @shell_exec($command);
        if (!is_string($output)) {
            return null;
        }

        if (preg_match('/([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}/', $output, $matches)) {
            return strtoupper(str_replace('-', ':', $matches[0]));
        }

        return null;
    }

    public static function portOpen(string $ip, int $port, float $timeout = 0.5): bool
    {
        $connection = @fsockopen($ip, $port, $errno, $errstr, $timeout);

        if (is_resource($connection)) {
            fclose($connection);

            return true;
        }

        return false;
    }

    /**
     * Основной IPv4-адрес машины (интерфейс с шлюзом по умолчанию).
     * Результат кэшируется в файле на час, чтобы не дёргать PowerShell каждый запрос.
     */
    public static function primaryIp(): string
    {
        $cacheFile = SCANPPP_ROOT . '/cache/primary_ip.txt';

        if (is_file($cacheFile)) {
            $data = @file_get_contents($cacheFile);
            if (is_string($data)) {
                $parts = explode('|', $data, 2);
                if (count($parts) === 2 && $parts[1] !== '' && (time() - (int) $parts[0]) < 3600) {
                    return $parts[1];
                }
            }
        }

        $ip = self::detectPrimaryIp();

        @file_put_contents($cacheFile, time() . '|' . $ip);

        return $ip;
    }

    private static function detectPrimaryIp(): string
    {
        $candidates = [];

        if (function_exists('shell_exec')) {
            $command = 'powershell -NoProfile -Command "(Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway } | Select-Object -First 1).IPv4Address.IPAddress"';
            $output = @shell_exec($command);
            if (is_string($output)) {
                foreach (preg_split('/\r?\n/', $output) as $line) {
                    $line = trim($line);
                    if (filter_var($line, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $candidates[] = $line;
                    }
                }
            }
        }

        $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
        if (filter_var($serverAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($serverAddr, '127.') !== 0) {
            $candidates[] = $serverAddr;
        }

        $host = gethostbyname(gethostname());
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($host, '127.') !== 0) {
            $candidates[] = $host;
        }

        foreach ($candidates as $candidate) {
            if (strpos($candidate, '192.168.') === 0 || strpos($candidate, '10.') === 0) {
                return $candidate;
            }
        }

        return $candidates[0] ?? '';
    }

    private static function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }
}
