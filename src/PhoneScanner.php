<?php

declare(strict_types=1);

namespace ScanPPP;

/**
 * Сканер IP-телефонов Yealink.
 */
final class PhoneScanner extends Scanner
{
    /** Пауза между попытками опроса одного телефона, микросекунды. */
    private const RETRY_DELAY_US = 400000;

    /**
     * Известные страницы веб-интерфейса Yealink. Открытый на них HTML
     * содержит строку «Yealink», по которой телефон и распознаётся.
     */
    private const PATHS = [
        '/servlet?m=mod_listener&p=login&q=loginForm&jumpto=status',
        '/servlet?m=mod_listener&p=login&q=loginForm',
        '/servlet?m=mod_listener&p=login',
    ];

    public function scan(string $ip): ?array
    {
        if (!Network::isValidIp($ip) || !Network::portOpen($ip, 80, 0.5)) {
            return null;
        }

        $timeout = (int) Config::get('http.timeout', 3);
        $attempts = max(1, (int) Config::get('http.attempts', 2));

        foreach (self::PATHS as $path) {
            $url = "http://{$ip}{$path}";

            // Yealink прекращает отвечать на веб-интерфейс после нескольких
            // запросов подряд, поэтому пробуем каждый путь несколько раз.
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $body = $this->request($url, $timeout, $headers);

                if ($body === null) {
                    if ($attempt < $attempts) {
                        usleep(self::RETRY_DELAY_US);
                    }
                    continue;
                }

                $title = $this->extractTitle($body);

                if (strpos($body, 'Yealink') === false
                    && ($title === null || strpos($title, 'Yealink') === false)) {
                    // Страница открылась, но это не Yealink: остальные пути
                    // на том же устройстве смысла проверять не имеют.
                    return null;
                }

                return [
                    'ip' => $ip,
                    'status' => 'SUCCESS',
                    'title' => $title,
                    'server' => $this->headerValue($headers, 'Server'),
                    'url' => $url,
                    'content_type' => $this->headerValue($headers, 'Content-Type'),
                    'response_code' => $this->statusCode($headers),
                    'model' => $this->detectModel($body),
                    'sip_available' => Network::portOpen($ip, 5060, 0.5),
                    'mac' => Network::mac($ip),
                ];
            }
        }

        return null;
    }

    /**
     * Выполняет один HTTP-запрос. Тело возвращается как результат, а
     * заголовки ответа — через ссылочный параметр $headers.
     *
     * Заголовки берутся из служебной переменной $http_response_header,
     * поэтому второго обращения к устройству не требуется: Yealink
     * прекращает отвечать после нескольких запросов подряд.
     */
    private function request(string $url, int $timeout, ?array &$headers): ?string
    {
        $headers = null;

        /** @var array<int|string, string|string[]>|null $http_response_header */
        $response = @file_get_contents($url, false, $this->httpContext($timeout));

        if ($response === false) {
            return null;
        }

        $headers = $http_response_header ?? null;

        return $response;
    }

    /**
     * Извлекает HTTP-код из первой строки ответа («HTTP/1.1 200 OK»).
     *
     * @param array<int|string, string|string[]>|null $headers
     */
    private function statusCode(?array $headers): ?string
    {
        if ($headers === null || !isset($headers[0])) {
            return null;
        }

        return preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $headers[0], $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * @return resource
     */
    private function httpContext(int $timeout)
    {
        return stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
            'http' => [
                'header' => [
                    'User-Agent: Mozilla/5.0',
                    'Accept: text/html,application/xhtml+xml,application/xml',
                ],
                'timeout' => $timeout,
                'follow_location' => 0,
            ],
        ]);
    }

    private function extractTitle(string $html): ?string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $titles = $dom->getElementsByTagName('title');

        return $titles->length > 0 ? $titles->item(0)->nodeValue : null;
    }

    private function detectModel(string $html): string
    {
        if (preg_match('/SIP-T[0-9]+/', $html, $matches)) {
            return 'Yealink ' . $matches[0];
        }

        return 'Yealink (модель не определена)';
    }

    /**
     * Ищет значение заголовка по имени. Принимает как список сырых строк
     * («Content-Type: text/html»), так и готовый ассоциативный массив.
     *
     * @param array<int|string, string|string[]> $headers
     */
    private function headerValue(array $headers, string $name): string
    {
        if (isset($headers[$name])) {
            $value = $headers[$name];

            return (string) (is_array($value) ? reset($value) : $value);
        }

        $prefix = strtolower($name) . ':';

        foreach ($headers as $line) {
            $line = (string) (is_array($line) ? reset($line) : $line);

            if (stripos($line, $prefix) === 0) {
                return trim(substr($line, strlen($prefix)));
            }
        }

        return 'Unknown';
    }
}
