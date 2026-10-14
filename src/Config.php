<?php

declare(strict_types=1);

namespace ScanPPP;

/**
 * Простое хранилище настроек с доступом по точечной нотации.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    /**
     * @param array<string, mixed> $items
     */
    public static function load(array $items): void
    {
        self::$items = $items;
    }

    /**
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
