<?php

declare(strict_types=1);

namespace ScanPPP;

use Throwable;

/**
 * JSON API приложения: маршрутизация запросов сканирования по типу устройства.
 *
 * Запрос: api.php?scanner=pc|phone|printer[&action=check|scan|device]&ip=<адрес>.
 * Ответ: JSON с данными об устройстве, null (узел не найден) либо объект
 * с ключом error при ошибке.
 */
final class Api
{
    /**
     * Обрабатывает текущий HTTP-запрос и выводит JSON-ответ.
     */
    public static function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $scanner = (string) ($_GET['scanner'] ?? '');
        $action = (string) ($_GET['action'] ?? 'scan');
        $ip = trim((string) ($_GET['ip'] ?? ''));

        try {
            $payload = self::dispatch($scanner, $action, $ip);
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Ошибка сканирования: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Выбирает нужный сканер и действие.
     *
     * @return array<string, mixed>|null
     */
    private static function dispatch(string $scanner, string $action, string $ip): ?array
    {
        switch ($scanner) {
            case 'pc':
                return (new PcScanner())->scan($ip);

            case 'phone':
                return (new PhoneScanner())->scan($ip);

            case 'printer':
                $printer = new PrinterScanner();

                switch ($action) {
                    case 'check':
                        return $printer->check($ip);
                    case 'device':
                        return $printer->deviceInfo($ip);
                    case 'scan':
                    default:
                        return $printer->printerInfo($ip);
                }

            default:
                http_response_code(400);

                return ['error' => 'Неизвестный сканер'];
        }
    }
}
