<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'GSH\\Fantasy\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

GSH\Fantasy\Config::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(GSH\Fantasy\Config::get('APP_TIMEZONE', 'Europe/Berlin'));

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('gsh_fantasy_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

