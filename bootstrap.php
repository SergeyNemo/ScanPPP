<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('default_socket_timeout', '1');

define('SCANPPP_ROOT', __DIR__);

// Git не хранит пустые папки, поэтому при клонировании репозитория каталог
// cache/ отсутствует. Без него запись кэша локального адреса падала бы
// молча, и адрес определялся бы заново на каждом запросе.
if (!is_dir(SCANPPP_ROOT . '/cache')) {
    @mkdir(SCANPPP_ROOT . '/cache', 0775, true);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'ScanPPP\\';
    $length = strlen($prefix);

    if (strncmp($class, $prefix, $length) !== 0) {
        return;
    }

    $relative = substr($class, $length);
    $file = SCANPPP_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

\ScanPPP\Config::load(require SCANPPP_ROOT . '/config.php');
