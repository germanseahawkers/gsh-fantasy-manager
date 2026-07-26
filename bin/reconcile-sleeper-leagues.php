#!/usr/bin/env php
<?php

declare(strict_types=1);

use GSH\Fantasy\Database;
use GSH\Fantasy\SleeperClient;

require dirname(__DIR__) . '/src/bootstrap.php';

function usage(): void
{
    fwrite(STDOUT, <<<'TEXT'
Gleicht die Liga-Zuordnungen der App mit den tatsächlichen Sleeper-Mitgliedschaften ab.

Aufruf:
  php bin/reconcile-sleeper-leagues.php --season=ID|latest --dry-run
  php bin/reconcile-sleeper-leagues.php --season=ID|latest --apply

Der Dry-Run verändert keine Daten. --apply übernimmt ausschließlich eindeutige
Sleeper-Mitgliedschaften. Teilnehmer in keiner oder mehreren GSH-Ligen werden
nicht automatisch verändert.

TEXT);
}

$options = getopt('', ['season:', 'dry-run', 'apply', 'help']);
if (isset($options['help'])) {
    usage();
    exit(0);
}

$seasonOption = trim((string) ($options['season'] ?? ''));
$apply = array_key_exists('apply', $options);
$dryRun = array_key_exists('dry-run', $options);
if ($seasonOption === '' || $apply === $dryRun) {
    usage();
    fwrite(STDERR, "Fehler: --season und genau einer der Parameter --dry-run oder --apply sind erforderlich.\n");
    exit(2);
}

$pdo = Database::connection();
try {
    $pdo->query('SELECT invitation_league_id FROM participants WHERE 1=0');
} catch (Throwable) {
    fwrite(STDERR, "Fehler: Bitte zuerst die Datenbankmigration mit „php bin/migrate.php“ ausführen.\n");
    exit(2);
}
if ($seasonOption === 'latest') {
    $season = $pdo->query('SELECT * FROM seasons ORDER BY registration_closes_at DESC, id DESC LIMIT 1')->fetch();
} elseif (ctype_digit($seasonOption) && (int) $seasonOption > 0) {
    $statement = $pdo->prepare('SELECT * FROM seasons WHERE id=?');
    $statement->execute([(int) $seasonOption]);
    $season = $statement->fetch();
} else {
    fwrite(STDERR, "Fehler: --season muss eine positive ID oder latest sein.\n");
    exit(2);
}

if (!$season) {
    fwrite(STDERR, "Fehler: Saison wurde nicht gefunden.\n");
    exit(2);
}

$leagueStatement = $pdo->prepare('SELECT * FROM leagues WHERE season_id=? ORDER BY sort_order, id');
$leagueStatement->execute([$season['id']]);
$leagues = $leagueStatement->fetchAll();
if ($leagues === []) {
    fwrite(STDERR, "Fehler: Für die Saison sind keine Ligen eingerichtet.\n");
    exit(2);
}

$leagueById = [];
$adminLeagueByParticipant = [];
$memberships = [];
$sleeperMembers = [];
$client = new SleeperClient();
try {
    foreach ($leagues as $league) {
        $leagueId = (int) $league['id'];
        $leagueById[$leagueId] = $league;
        if (!empty($league['admin_participant_id'])) {
            $adminLeagueByParticipant[(int) $league['admin_participant_id']] = $leagueId;
        }
        $sleeperLeagueId = trim((string) ($league['sleeper_league_id'] ?? ''));
        if (!preg_match('/^[0-9]+$/', $sleeperLeagueId)) {
            throw new RuntimeException('Für die Liga „' . $league['name'] . '“ fehlt eine gültige Sleeper League-ID.');
        }

        $leagueUsers = [];
        foreach ($client->leagueUsers($sleeperLeagueId) as $user) {
            $userId = (string) ($user['user_id'] ?? '');
            if ($userId === '') {
                continue;
            }
            $leagueUsers[$userId] = (string) ($user['display_name'] ?? $user['username'] ?? $userId);
        }

        foreach ($client->leagueRosterOwners($sleeperLeagueId) as $userId) {
            $memberships[$userId][$leagueId] = true;
            $sleeperMembers[$userId] = $leagueUsers[$userId] ?? $userId;
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Sleeper-Abgleich abgebrochen: ' . $exception->getMessage() . "\n");
    exit(1);
}

$participantStatement = $pdo->prepare('SELECT * FROM participants WHERE season_id=? ORDER BY name');
$participantStatement->execute([$season['id']]);
$participants = $participantStatement->fetchAll();
$participantSleeperIds = array_fill_keys(array_map(
    static fn (array $participant): string => (string) $participant['sleeper_user_id'],
    $participants
), true);

$correct = 0;
$changes = [];
$joinedOnly = [];
$notJoined = [];
$conflicts = [];
foreach ($participants as $participant) {
    $participantMemberships = array_keys($memberships[(string) $participant['sleeper_user_id']] ?? []);
    $currentLeagueId = (int) ($participant['league_id'] ?? 0);
    $currentLeagueName = $currentLeagueId > 0
        ? (string) ($leagueById[$currentLeagueId]['name'] ?? 'Unbekannte Liga')
        : 'nicht zugeteilt';

    if ($participantMemberships === []) {
        $notJoined[] = '@' . $participant['sleeper_username'] . ' · App: ' . $currentLeagueName;
        continue;
    }
    if (count($participantMemberships) > 1) {
        $names = array_map(
            static fn (int $leagueId): string => (string) $leagueById[$leagueId]['name'],
            $participantMemberships
        );
        $conflicts[] = '@' . $participant['sleeper_username'] . ' · Sleeper: ' . implode(', ', $names);
        continue;
    }

    $actualLeagueId = (int) $participantMemberships[0];
    $adminLeagueId = $adminLeagueByParticipant[(int) $participant['id']] ?? null;
    if ($adminLeagueId !== null && $adminLeagueId !== $actualLeagueId) {
        $conflicts[] = '@' . $participant['sleeper_username']
            . ' · als Admin für ' . $leagueById[$adminLeagueId]['name']
            . ' festgelegt, aber in Sleeper Mitglied von ' . $leagueById[$actualLeagueId]['name'];
        continue;
    }
    if ($actualLeagueId !== $currentLeagueId) {
        $changes[] = [
            'participant' => $participant,
            'league_id' => $actualLeagueId,
            'message' => '@' . $participant['sleeper_username'] . ' · App: ' . $currentLeagueName . ' → Sleeper: ' . $leagueById[$actualLeagueId]['name'],
        ];
    } elseif (empty($participant['joined_sleeper_at']) || (int) ($participant['invitation_league_id'] ?? 0) !== $actualLeagueId) {
        $joinedOnly[] = [
            'participant' => $participant,
            'league_id' => $actualLeagueId,
            'message' => '@' . $participant['sleeper_username'] . ' · ' . $leagueById[$actualLeagueId]['name'],
        ];
    } else {
        ++$correct;
    }
}

$unknownMembers = [];
foreach ($sleeperMembers as $userId => $displayName) {
    if (!isset($participantSleeperIds[$userId])) {
        $leagueNames = array_map(
            static fn (int $leagueId): string => (string) $leagueById[$leagueId]['name'],
            array_keys($memberships[$userId])
        );
        $unknownMembers[] = $displayName . ' · ' . implode(', ', $leagueNames);
    }
}

fwrite(STDOUT, "Saison: {$season['name']} (ID {$season['id']})\n");
fwrite(STDOUT, 'Sleeper-Ligen geprüft: ' . count($leagues) . "\n");
fwrite(STDOUT, 'App-Zuordnung bereits korrekt: ' . $correct . "\n");
fwrite(STDOUT, 'Zuordnung zu korrigieren: ' . count($changes) . "\n");
fwrite(STDOUT, 'Nur Beitrittsstatus nachzutragen: ' . count($joinedOnly) . "\n");
fwrite(STDOUT, 'In keiner eingerichteten Sleeper-Liga gefunden: ' . count($notJoined) . "\n");
fwrite(STDOUT, 'Mehrfachmitgliedschaften, manuell prüfen: ' . count($conflicts) . "\n");
fwrite(STDOUT, 'Sleeper-Mitglieder ohne App-Anmeldung: ' . count($unknownMembers) . "\n");

foreach ($changes as $change) {
    fwrite(STDOUT, "KORREKTUR: {$change['message']}\n");
}
foreach ($joinedOnly as $entry) {
    fwrite(STDOUT, "STATUS: {$entry['message']}\n");
}
foreach ($notJoined as $message) {
    fwrite(STDOUT, "NICHT GEFUNDEN: {$message}\n");
}
foreach ($conflicts as $message) {
    fwrite(STDOUT, "KONFLIKT: {$message}\n");
}
foreach ($unknownMembers as $message) {
    fwrite(STDOUT, "OHNE APP-ZUORDNUNG: {$message}\n");
}

if ($dryRun) {
    fwrite(STDOUT, "Dry-Run abgeschlossen; es wurden keine Daten gespeichert.\n");
    exit(0);
}

$now = date('Y-m-d H:i:s');
$update = $pdo->prepare(
    "UPDATE participants
     SET league_id=?, invitation_league_id=?, joined_sleeper_at=?,
         mail_status='sent', mail_sent_at=COALESCE(mail_sent_at, ?), updated_at=?
     WHERE id=?"
);

try {
    $pdo->beginTransaction();
    foreach ([...$changes, ...$joinedOnly] as $entry) {
        $update->execute([
            $entry['league_id'],
            $entry['league_id'],
            $now,
            $now,
            $now,
            $entry['participant']['id'],
        ]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Korrektur fehlgeschlagen; es wurden keine Daten gespeichert: ' . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, count($changes) . ' Zuordnungen korrigiert und ' . count($joinedOnly) . " Beitrittsstatus nachgetragen.\n");
