<?php

declare(strict_types=1);

namespace ScanPPP;

/**
 * Обёртка над расширением SNMP (SNMPv1).
 */
final class SnmpClient
{
    private string $ip;
    private string $community;
    private int $timeout;
    private int $retries;

    public function __construct(string $ip, ?string $community = null, ?int $timeout = null, ?int $retries = null)
    {
        $this->ip = $ip;
        $this->community = $community ?? (string) Config::get('snmp.community', 'public');
        $this->timeout = $timeout ?? (int) Config::get('snmp.timeout', 500000);
        $this->retries = $retries ?? (int) Config::get('snmp.retries', 1);
    }

    public function isAvailable(): bool
    {
        return $this->get('1.3.6.1.2.1.1.1.0') !== false;
    }

    /**
     * @return string|false
     */
    public function get(string $oid)
    {
        if (!function_exists('snmpget')) {
            return false;
        }

        // Библиотека net-snmp не потокобезопасна: одновременные вызовы из разных
        // потоков Apache (ZTS) повреждают кучу и валят httpd.exe. Поэтому все
        // SNMP-вызовы сериализуются межпроцессным файловым мьютексом.
        $lock = $this->acquireLock();

        try {
            snmp_set_quick_print(true);
            snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
            snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);

            $result = @snmpget($this->ip, $this->community, $oid, $this->timeout, $this->retries);
        } catch (\Throwable $e) {
            $result = false;
        } finally {
            $this->releaseLock($lock);
        }

        return $result === false ? false : (string) $result;
    }

    /**
     * @return resource|null
     */
    private function acquireLock()
    {
        $path = (string) (Config::get('snmp.lock') ?? '');
        if ($path === '') {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scanppp-snmp.lock';
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }

        @flock($handle, LOCK_EX);

        return $handle;
    }

    /**
     * @param resource|null $handle
     */
    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Возвращает значение OID, очищенное от типа данных и Hex-обёрток.
     *
     * @return string|false
     */
    public function getClean(string $oid)
    {
        $result = $this->get($oid);
        if ($result === false) {
            return false;
        }

        if (preg_match('/Hex-STRING:\s+((?:[0-9A-Fa-f]{2}\s*)+)/', $result, $matches)) {
            $binary = @hex2bin(preg_replace('/\s+/', '', $matches[1]));
            if ($binary !== false) {
                $utf8 = @mb_convert_encoding($binary, 'UTF-8', 'UTF-8');
                if ($utf8 !== false && mb_check_encoding($utf8, 'UTF-8')) {
                    return trim($utf8);
                }
            }
        }

        if (preg_match('/OctetString: 0x([0-9a-fA-F]+)/', $result, $matches)) {
            $binary = @hex2bin($matches[1]);
            if ($binary !== false) {
                $utf8 = @mb_convert_encoding($binary, 'UTF-8', 'UTF-8');
                if ($utf8 !== false && mb_check_encoding($utf8, 'UTF-8')) {
                    return trim($utf8);
                }
            }
        }

        return trim(preg_replace('/^[^:]+: /', '', $result));
    }
}
