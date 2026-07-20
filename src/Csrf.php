<?php

declare(strict_types=1);

namespace GSH\Fantasy;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . Http::e(self::token()) . '">';
    }

    public static function verify(): void
    {
        $submitted = (string) ($_POST['csrf_token'] ?? '');
        if ($submitted === '' || !hash_equals(self::token(), $submitted)) {
            http_response_code(419);
            exit('Die Sitzung ist abgelaufen. Bitte lade die Seite neu.');
        }
    }
}

