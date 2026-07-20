<?php

declare(strict_types=1);

namespace GSH\Fantasy;

use PDO;

final class Auth
{
    public static function check(): bool
    {
        return ($_SESSION['admin_authenticated'] ?? false) === true;
    }

    public static function requireAdmin(): void
    {
        if (!self::check()) {
            Http::redirect('/admin.php');
        }
    }

    public static function attempt(string $password): bool
    {
        $pdo = Database::connection();
        self::clearOldAttempts($pdo);
        $ipHash = self::ipHash();

        $statement = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ?');
        $statement->execute([$ipHash]);
        if ((int) $statement->fetchColumn() >= 5) {
            return false;
        }

        $hash = Config::get('ADMIN_PASSWORD_HASH');
        if ($hash !== '' && password_verify($password, $hash)) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            $pdo->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([$ipHash]);
            return true;
        }

        $pdo->prepare('INSERT INTO login_attempts (ip_hash, attempted_at) VALUES (?, ?)')
            ->execute([$ipHash, date('Y-m-d H:i:s')]);
        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], '', (bool) $params['secure'], true);
        }
        session_destroy();
    }

    private static function clearOldAttempts(PDO $pdo): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - 900);
        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$cutoff]);
    }

    private static function ipHash(): string
    {
        return hash_hmac('sha256', Http::clientIp(), Config::get('APP_KEY', 'gsh-fantasy'));
    }
}

