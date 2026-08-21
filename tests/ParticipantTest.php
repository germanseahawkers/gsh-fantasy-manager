<?php

declare(strict_types=1);

use GSH\Fantasy\Database;
use GSH\Fantasy\ParticipantService;
use GSH\Fantasy\SleeperAudit;

putenv('DB_DSN=sqlite::memory:');
putenv('DB_USER=');
putenv('DB_PASSWORD=');

require dirname(__DIR__) . '/src/bootstrap.php';

Database::migrate();
$pdo = Database::connection();
$service = new ParticipantService($pdo);
$now = date('Y-m-d H:i:s');

$pdo->prepare('INSERT INTO seasons (id, name, registration_opens_at, registration_closes_at, created_at, updated_at) VALUES (1, "Test Season", ?, ?, ?, ?)')->execute([$now, $now, $now, $now]);
$pdo->prepare('INSERT INTO seasons (id, name, registration_opens_at, registration_closes_at, created_at, updated_at) VALUES (2, "Other Season", ?, ?, ?, ?)')->execute([$now, $now, $now, $now]);
$pdo->prepare('INSERT INTO leagues (id, season_id, name, capacity, sort_order, created_at, updated_at) VALUES (10, 1, "Test League A", 12, 1, ?, ?)')->execute([$now, $now]);
$pdo->prepare('INSERT INTO leagues (id, season_id, name, capacity, sort_order, created_at, updated_at) VALUES (20, 1, "Test League B", 1, 2, ?, ?)')->execute([$now, $now]);
$pdo->prepare('INSERT INTO leagues (id, season_id, name, capacity, sort_order, created_at, updated_at) VALUES (30, 2, "Other League", 12, 1, ?, ?)')->execute([$now, $now]);

$pdo->prepare('INSERT INTO participants (id, season_id, name, email, member_number, sleeper_username, sleeper_user_id, league_id, invitation_league_id, joined_sleeper_at, mail_status, created_at, updated_at) VALUES (1, 1, "Admin User", "admin@example.com", "M1", "admin_user", "s1", 10, 10, ?, "sent", ?, ?)')->execute([$now, $now, $now]);
$pdo->prepare('INSERT INTO participants (id, season_id, name, email, member_number, sleeper_username, sleeper_user_id, league_id, invitation_league_id, joined_sleeper_at, mail_status, created_at, updated_at) VALUES (2, 1, "Regular User", "user@example.com", "M2", "regular_user", "s2", 10, 10, ?, "sent", ?, ?)')->execute([$now, $now, $now]);
$pdo->prepare('INSERT INTO participants (id, season_id, name, email, member_number, sleeper_username, sleeper_user_id, league_id, mail_status, created_at, updated_at) VALUES (3, 1, "Unassigned User", "waiting@example.com", "M3", "waiting_user", "s3", NULL, "pending", ?, ?)')->execute([$now, $now]);
$pdo->prepare('INSERT INTO participants (id, season_id, name, email, member_number, sleeper_username, sleeper_user_id, league_id, mail_status, created_at, updated_at) VALUES (4, 1, "Full League User", "full@example.com", "M4", "full_user", "s4", 20, "sent", ?, ?)')->execute([$now, $now]);
$pdo->prepare('UPDATE leagues SET admin_participant_id=1 WHERE id=10')->execute();
$pdo->prepare('INSERT INTO mail_log (participant_id, recipient, subject, status, created_at) VALUES (2, "user@example.com", "Test Subject", "sent", ?)')->execute([$now]);

$adminRejected = false;
try {
    $service->move(1, null);
} catch (RuntimeException) {
    $adminRejected = true;
}
assert($adminRejected, 'Liga-Admins dürfen nicht auf die Nachrückerliste verschoben werden.');

$service->move(2, null);
$unassigned = $pdo->query('SELECT * FROM participants WHERE id=2')->fetch();
assert($unassigned['league_id'] === null, 'Beim Zurückziehen muss league_id echtes SQL-NULL sein.');
assert($unassigned['joined_sleeper_at'] === null, 'Beim Zurückziehen muss der Beitrittsstatus zurückgesetzt werden.');
assert($unassigned['mail_status'] === 'pending', 'Beim Zurückziehen muss der Mailstatus offen sein.');
assert((int) $unassigned['invitation_league_id'] === 10, 'Die Einladungshistorie muss erhalten bleiben.');

$fullLeagueRejected = false;
try {
    $service->move(2, 20);
} catch (RuntimeException) {
    $fullLeagueRejected = true;
}
assert($fullLeagueRejected, 'Eine volle Liga darf keine weitere Zuordnung akzeptieren.');

$otherSeasonRejected = false;
try {
    $service->move(2, 30);
} catch (RuntimeException) {
    $otherSeasonRejected = true;
}
assert($otherSeasonRejected, 'Eine Zuordnung in eine Liga einer anderen Saison muss abgelehnt werden.');

$audit = (new SleeperAudit($pdo))->persist(1, [
    's1' => [10 => true],
    's2' => [20 => true],
    's3' => [20 => true],
    's4' => [20 => true],
    'unknown' => [10 => true],
], ['unknown' => 'Unknown Sleeper User']);
assert($audit['joined'] === 2, 'Zwei Teilnehmer müssen ihrer erwarteten Liga korrekt beigetreten sein.');
assert($audit['mismatches'] === 2, 'Falsche und unzugeteilte Sleeper-Mitgliedschaften müssen erkannt werden.');
assert($audit['unknown'] === 1, 'Sleeper-Mitglieder ohne App-Anmeldung müssen erkannt werden.');
assert(SleeperAudit::isMismatch(10, [10, 20]), 'Mehrfachmitgliedschaften müssen als Abweichung gelten.');

$deleted = $service->delete(2);
assert($deleted['name'] === 'Regular User');
assert($pdo->query('SELECT * FROM participants WHERE id=2')->fetch() === false, 'Gelöschte Teilnehmer dürfen nicht bestehen bleiben.');
assert($pdo->query('SELECT * FROM mail_log WHERE participant_id=2')->fetchAll() === [], 'Mailprotokolle gelöschter Teilnehmer müssen entfernt werden.');

$adminDeleteRejected = false;
try {
    $service->delete(1);
} catch (RuntimeException) {
    $adminDeleteRejected = true;
}
assert($adminDeleteRejected, 'Liga-Admins dürfen nicht gelöscht werden.');

fwrite(STDOUT, "ParticipantTest erfolgreich.\n");
