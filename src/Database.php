<?php

declare(strict_types=1);

namespace GSH\Fantasy;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = Config::get('DB_DSN');
        if ($dsn === '') {
            throw new RuntimeException('DB_DSN ist nicht konfiguriert.');
        }

        self::$connection = new PDO(
            $dsn,
            Config::get('DB_USER'),
            Config::get('DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return self::$connection;
    }

    public static function migrate(): void
    {
        $pdo = self::connection();
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $bool = $sqlite ? 'INTEGER' : 'TINYINT(1)';
        $timestamp = $sqlite ? 'TEXT' : 'DATETIME';

        $statements = [
            "CREATE TABLE IF NOT EXISTS seasons (
                id {$id},
                name VARCHAR(120) NOT NULL,
                registration_opens_at {$timestamp} NOT NULL,
                registration_closes_at {$timestamp} NOT NULL,
                draft_at {$timestamp} NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'open',
                email_subject VARCHAR(255) NOT NULL DEFAULT 'Deine GSH Fantasy-Liga',
                email_intro TEXT NULL,
                created_at {$timestamp} NOT NULL,
                updated_at {$timestamp} NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS participants (
                id {$id},
                season_id BIGINT NOT NULL,
                name VARCHAR(160) NOT NULL,
                email VARCHAR(255) NOT NULL,
                member_number VARCHAR(80) NOT NULL,
                sleeper_username VARCHAR(100) NOT NULL,
                sleeper_user_id VARCHAR(40) NOT NULL,
                sleeper_display_name VARCHAR(120) NULL,
                admin_volunteer {$bool} NOT NULL DEFAULT 0,
                league_id BIGINT NULL,
                joined_sleeper_at {$timestamp} NULL,
                mail_status VARCHAR(32) NOT NULL DEFAULT 'pending',
                mail_sent_at {$timestamp} NULL,
                created_at {$timestamp} NOT NULL,
                updated_at {$timestamp} NOT NULL,
                UNIQUE (season_id, email),
                UNIQUE (season_id, sleeper_user_id)
            )",
            "CREATE TABLE IF NOT EXISTS leagues (
                id {$id},
                season_id BIGINT NOT NULL,
                name VARCHAR(120) NOT NULL,
                sleeper_league_id VARCHAR(40) NULL,
                invite_url VARCHAR(500) NULL,
                capacity INTEGER NOT NULL DEFAULT 12,
                admin_participant_id BIGINT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at {$timestamp} NOT NULL,
                updated_at {$timestamp} NOT NULL,
                UNIQUE (season_id, name),
                UNIQUE (admin_participant_id)
            )",
            "CREATE TABLE IF NOT EXISTS mail_log (
                id {$id},
                participant_id BIGINT NOT NULL,
                recipient VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                status VARCHAR(32) NOT NULL,
                error_message TEXT NULL,
                created_at {$timestamp} NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id {$id},
                ip_hash VARCHAR(64) NOT NULL,
                attempted_at {$timestamp} NOT NULL
            )",
        ];

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }
}

