<?php

declare(strict_types=1);

namespace ScanPPP;

/**
 * Базовый класс сканера одного устройства в сети.
 *
 * Каждый конкретный сканер (компьютеры, телефоны, принтеры) реализует
 * метод scan(), возвращая данные об устройстве либо null, если узел
 * не относится к его типу.
 */
abstract class Scanner
{
    /**
     * Сканирует один IP-адрес. Возвращает данные об устройстве либо null.
     *
     * @return array<string, mixed>|null
     */
    abstract public function scan(string $ip): ?array;
}
