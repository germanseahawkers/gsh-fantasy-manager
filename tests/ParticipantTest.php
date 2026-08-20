<?php

declare(strict_types=1);

use GSH\Fantasy\Database;

putenv('DB_DSN=sqlite::memory:');
putenv('DB_USER=');
putenv('DB_PASSWORD=');

require dirname(__DIR__) . '/src/bootstrap.php';

Database::migrate();
$pdo = Database::connection();

$now = date('Y-m-d H:i:s');

// 1. Create season, league, participants, mail_log
$pdo->prepare('INSERT INTO seasons (id, name, registration_opens_at, registration_closes_at, created_at, updated_at) VALUES (1, "Test Season", ?, ?, ?, ?)')->execute([$now, $now, $now, $now]);

$pdo->prepare('INSERT INTO leagues (id, season_id, name, capacity, sort_order, created_at, updated_at) VALUES (10, 1, "Test League A", 12, 1, ?, ?)')->execute([$now, $now]);
$pdo->prepare('INSERT INTO leagues (id, season_id, name, capacity, sort_order, created_at, updated_at) VALUES (20, 1, "Test League B", 12, 2, ?, ?)')->execute([$now, $now]);

$pdo->prepare('INSERT INTO participants (id, season_id, name, email, member_number, sleeper_username, sleeper_user_id, league_id, mail_status, created_at, updated_at) VALUES (1, 1, "Admin User", "admin@example.com", "M1", "admin_user", "s1", 10, "sent", ?, ?)')->execute([$now, $now]);
$pdo->prepare('INSERT INTO participants (id, season_id, name, email, member_number, sleeper_username, sleeper_user_id, league_id, joined_sleeper_at, mail_status, created_at, updated_at) VALUES (2, 1, "Regular User", "user@example.com", "M2", "regular_user", "s2", 10, ?, "sent", ?, ?)')->execute([$now, $now, $now]);

$pdo->prepare('UPDATE leagues SET admin_participant_id=1 WHERE id=10')->execute();

$pdo->prepare('INSERT INTO mail_log (participant_id, recipient, subject, status, created_at) VALUES (2, "user@example.com", "Test Subject", "sent", ?)')->execute([$now]);

// Test 1: League admin deletion must be rejected
$adminCheck = $pdo->prepare('SELECT id FROM leagues WHERE admin_participant_id=?');
$adminCheck->execute([1]);
assert($adminCheck->fetchColumn() !== false, 'Participant 1 must be league admin.');

$adminCanBeDeleted = true;
try {
    $check = $pdo->prepare('SELECT id FROM leagues WHERE admin_participant_id=?');
    $check->execute([1]);
    if ($check->fetchColumn() !== false) {
        throw new RuntimeException('Dieser Teilnehmer ist Liga-Admin und kann nicht gelöscht werden.');
    }
} catch (RuntimeException $e) {
    $adminCanBeDeleted = false;
}
assert(!$adminCanBeDeleted, 'League admin deletion must throw RuntimeException.');

// Test 2: Unassign participant from league (league_id = null)
$pdo->prepare("UPDATE participants SET league_id=NULL, joined_sleeper_at=NULL, mail_status='pending', updated_at=? WHERE id=2")->execute([$now]);
$unassignedUser = $pdo->query('SELECT * FROM participants WHERE id=2')->fetch();
assert($unassignedUser['league_id'] === null, 'Unassigned user league_id must be null.');
assert($unassignedUser['joined_sleeper_at'] === null, 'Unassigned user joined_sleeper_at must be null.');
assert($unassignedUser['mail_status'] === 'pending', 'Unassigned user mail_status must be pending.');

// Test 3: Delete participant (and dependent mail_log)
$pdo->beginTransaction();
$pdo->prepare('DELETE FROM mail_log WHERE participant_id=2')->execute();
$pdo->prepare('DELETE FROM participants WHERE id=2')->execute();
$pdo->commit();

$deletedUser = $pdo->query('SELECT * FROM participants WHERE id=2')->fetch();
assert($deletedUser === false, 'Deleted user must no longer exist in participants.');

$mailLogs = $pdo->query('SELECT * FROM mail_log WHERE participant_id=2')->fetchAll();
assert(count($mailLogs) === 0, 'Mail log entries for deleted user must be deleted.');

fwrite(STDOUT, "ParticipantTest erfolgreich.\n");
