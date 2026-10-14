<?php

declare(strict_types=1);

namespace ScanPPP;

/**
 * Сканер принтеров и прочих SNMP-устройств.
 */
final class PrinterScanner extends Scanner
{
    /** Признаки сетевого оборудования в имени/модели (не принтер). */
    private const NETWORK_KEYWORDS = ['switch', 'router', 'sw', 'rt', 'catalyst', 'cisco', 'huawei', 'mikrotik'];

    /** Значения sysObjectID/типа из MIB-II для «прочих» SNMP-устройств. */
    private const DEVICE_TYPES = [
        2 => 'Сетевое устройство',
        3 => 'Коммутатор/Маршрутизатор',
        4 => 'Сервер',
        6 => 'Рабочая станция',
        7 => 'Сетевое оборудование',
    ];

    public function scan(string $ip): ?array
    {
        return $this->printerInfo($ip);
    }

    /**
     * Быстрая проверка: доступен ли SNMP и является ли узел принтером.
     *
     * @return array{snmp: bool, printer: bool}
     */
    public function check(string $ip): array
    {
        if (!Network::isValidIp($ip)) {
            return ['snmp' => false, 'printer' => false];
        }

        $snmp = new SnmpClient($ip);
        if (!$snmp->isAvailable()) {
            return ['snmp' => false, 'printer' => false];
        }

        return ['snmp' => true, 'printer' => $this->looksLikePrinter($snmp)];
    }

    /**
     * Имя, модель (основная и альтернативная) и серийный номер из
     * стандартных MIB-II (1.3.6.1.2.1.1) и Printer-MIB (1.3.6.1.2.1.43).
     *
     * @return array<string, mixed>|null
     */
    public function printerInfo(string $ip): ?array
    {
        if (!Network::isValidIp($ip)) {
            return null;
        }

        $snmp = new SnmpClient($ip);
        if (!$snmp->isAvailable()) {
            return null;
        }

        $info = [
            'url' => 'http://' . $ip,
            'name' => $snmp->getClean('1.3.6.1.2.1.1.5.0') ?: '',
            'model_main' => $snmp->getClean('1.3.6.1.2.1.43.5.1.1.16.1') ?: '',
            'model_alt' => $snmp->getClean('1.3.6.1.2.1.25.3.2.1.3.1') ?: '',
            'serial' => $snmp->getClean('1.3.6.1.2.1.43.5.1.1.17.1') ?: '',
        ];

        if ($this->containsNetworkKeyword(implode(' ', $info))) {
            return null;
        }

        $info['cartridges'] = $this->cartridges($snmp);
        $info['model'] = $this->chooseModel($info['model_main'], $info['model_alt']);

        if ($info['model'] === '' && $info['cartridges'] === []) {
            return null;
        }

        return $info;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function deviceInfo(string $ip): ?array
    {
        if (!Network::isValidIp($ip)) {
            return null;
        }

        $snmp = new SnmpClient($ip);

        $info = [
            'url' => 'http://' . $ip,
            'name' => $snmp->getClean('1.3.6.1.2.1.1.5.0'),
            'description' => $snmp->getClean('1.3.6.1.2.1.1.1.0'),
            'type' => $snmp->getClean('1.3.6.1.2.1.1.7.0'),
        ];

        $info['type'] = self::DEVICE_TYPES[(int) $info['type']] ?? 'Другое';
        $info = array_filter($info, static fn ($value) => $value !== false && $value !== null && $value !== '');

        return $info === [] ? null : $info;
    }

    /**
     * Эвристика «это принтер»: узел отвечает хотя бы на один
     * принтерный OID (название/счётчик страниц) и в его модели или
     * имени нет признаков сетевого оборудования.
     */
    private function looksLikePrinter(SnmpClient $snmp): bool
    {
        $probeOids = [
            '1.3.6.1.2.1.43.11.1.1.6.1.1',
            '1.3.6.1.2.1.43.10.2.1.4.1.1',
        ];

        $isPrinter = false;
        foreach ($probeOids as $oid) {
            if ($snmp->get($oid) !== false) {
                $isPrinter = true;
                break;
            }
        }

        if (!$isPrinter) {
            return false;
        }

        $model = (string) $snmp->getClean('1.3.6.1.2.1.43.5.1.1.16.1');
        $name = (string) $snmp->getClean('1.3.6.1.2.1.1.5.0');

        return !$this->containsNetworkKeyword($model . ' ' . $name);
    }

    /**
     * Уровень картриджей: OID дают текущее и максимальное значения,
     * процент считается как их отношение и ограничивается 0..100.
     *
     * @return array<int, array{name: string, level: string, level_int: int|null}>
     */
    private function cartridges(SnmpClient $snmp): array
    {
        $cartridges = [];

        for ($i = 1; $i <= 4; $i++) {
            $name = $snmp->getClean("1.3.6.1.2.1.43.11.1.1.6.1.$i");
            if ($name === false) {
                continue;
            }

            $level = $snmp->getClean("1.3.6.1.2.1.43.11.1.1.9.1.$i");
            $maxLevel = $snmp->getClean("1.3.6.1.2.1.43.11.1.1.8.1.$i");

            $levelInt = $level !== false ? (int) $level : null;
            $maxInt = $maxLevel !== false ? (int) $maxLevel : null;

            if ($levelInt !== null && $maxInt !== null && $maxInt > 0) {
                $percentage = (int) round(($levelInt / $maxInt) * 100);
                $percentage = max(0, min(100, $percentage));
            } else {
                $percentage = null;
            }

            $cartridges[] = [
                'name' => trim((string) $name),
                'level' => $percentage !== null ? $percentage . '%' : 'нет данных',
                'level_int' => $percentage,
            ];
        }

        return $cartridges;
    }

    private function chooseModel(string $main, string $alt): string
    {
        if ($main === '' && $alt === '') {
            return 'не удалось определить';
        }
        if ($main !== '' && $alt === '') {
            return $main;
        }
        if ($main === '' && $alt !== '') {
            return $alt;
        }

        return strlen($main) >= strlen($alt) ? $main : $alt;
    }

    private function containsNetworkKeyword(string $text): bool
    {
        $text = strtolower($text);
        foreach (self::NETWORK_KEYWORDS as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
