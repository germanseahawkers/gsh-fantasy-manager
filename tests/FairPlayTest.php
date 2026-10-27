<?php

declare(strict_types=1);

use GSH\Fantasy\Database;
use GSH\Fantasy\FairPlayService;
use GSH\Fantasy\SleeperClient;

putenv('DB_DSN=sqlite::memory:');
putenv('DB_USER=');
putenv('DB_PASSWORD=');

require dirname(__DIR__) . '/src/bootstrap.php';

final class FakeFairPlaySleeperClient extends SleeperClient
{
    public int $week = 5;
    public string $injuryStatus = 'Out';

    public function nflState(): array
    {
        return ['week' => $this->week, 'season' => '2026', 'season_type' => 'regular'];
    }

    public function leagueRosters(string $leagueId): array
    {
        return [['roster_id' => 3, 'owner_id' => 'sleeper-owner']];
    }

    public function leagueInfo(string $leagueId): array
    {
        return ['roster_positions' => ['QB', 'BN', 'IR']];
    }

    public function leagueMatchups(string $leagueId, int $week): array
    {
        if ($week === 3) {
            return [[
                'roster_id' => 3,
                'starters' => ['0'],
                'starters_points' => [0.0],
                'players_points' => [],
            ]];
        }
        if ($week === 4) {
            return [[
                'roster_id' => 3,
                'starters' => ['1002'],
                'starters_points' => [0.0],
                'players_points' => ['1002' => 0.0],
            ]];
        }
        if (!in_array($week, [5, 6], true)) {
            return [];
        }
        return [[
            'roster_id' => 3,
            'starters' => ['1001'],
            'starters_points' => [0.0],
            'players_points' => ['1001' => 0.0],
        ]];
    }

    public function nflPlayers(): array
    {
        return [
            '1001' => ['full_name' => 'Test Player', 'injury_status' => $this->injuryStatus, 'status' => 'Active'],
            '1002' => ['full_name' => 'Historical Player', 'injury_status' => null, 'status' => 'Active'],
        ];
    }
}

Database::migrate();
$pdo = Database::connection();
$now = date('Y-m-d H:i:s');
$pdo->prepare('INSERT INTO seasons (id, name, registration_opens_at, registration_closes_at, status, fair_play_mode, created_at, updated_at) VALUES (1, "Test Season", ?, ?, "approved", "test", ?, ?)')
    ->execute([$now, $now, $now, $now]);
$pdo->prepare('INSERT INTO leagues (id, season_id, name, sleeper_league_id, capacity, sort_order, created_at, updated_at) VALUES (10, 1, "Test League", "12345", 12, 1, ?, ?)')
    ->execute([$now, $now]);
$pdo->prepare('INSERT INTO participants (id, season_id, name, email, member_number, sleeper_username, sleeper_user_id, league_id, mail_status, created_at, updated_at) VALUES (20, 1, "Test Manager", "manager@example.com", "M1", "manager", "sleeper-owner", 10, "sent", ?, ?)')
    ->execute([$now, $now]);

$client = new FakeFairPlaySleeperClient();
$service = new FairPlayService($pdo, $client);
$first = $service->run(1, true);
assert($first['week'] === 5);
assert($first['flagged'] === 1, 'Ein aufgestellter OUT-Spieler muss als Momentaufnahme gespeichert werden.');
assert($first['suggestions'] === 0, 'Für die laufende Woche darf noch kein Vorschlag entstehen.');

$client->week = 6;
$second = $service->run(1, true);
assert($second['suggestions'] === 1, 'Nach Abschluss der Woche muss bei null Punkten ein Vorschlag entstehen.');
$strike = $pdo->query('SELECT * FROM fair_play_strikes')->fetch();
assert($strike !== false && $strike['status'] === 'suggested');
$evidence = json_decode((string) $strike['evidence_json'], true);
assert(count($evidence) === 1 && $evidence[0]['player_name'] === 'Test Player');

$service->review((int) $strike['id'], 'confirmed');
assert($pdo->query('SELECT status FROM fair_play_strikes')->fetchColumn() === 'confirmed');

$service->review((int) $strike['id'], 'dismissed');
assert($pdo->query('SELECT status FROM fair_play_strikes')->fetchColumn() === 'dismissed');

$backfill = $service->backfillCompletedWeeks(1);
assert($backfill['weeks'] === 5 && $backfill['created'] === 2, 'Die Rückprüfung muss für die noch nicht bewerteten Wochen Hinweise anlegen.');
$retrospectiveRows = $pdo->query("SELECT * FROM fair_play_strikes WHERE origin='retrospective' ORDER BY week")->fetchAll();
assert(count($retrospectiveRows) === 2 && (int) $retrospectiveRows[0]['week'] === 3 && (int) $retrospectiveRows[1]['week'] === 4);
$emptySlotEvidence = json_decode((string) $retrospectiveRows[0]['evidence_json'], true);
assert($emptySlotEvidence[0]['reason'] === 'empty_slot', 'Leere Starter-Slots müssen rückwirkend erkannt werden.');
$retrospectiveEvidence = json_decode((string) $retrospectiveRows[1]['evidence_json'], true);
assert($retrospectiveEvidence[0]['player_name'] === 'Historical Player');
assert($retrospectiveEvidence[0]['injury_status'] === null, 'Die Rückprüfung darf keinen historischen Verletzungsstatus erfinden.');

$backfillAgain = $service->backfillCompletedWeeks(1);
assert($backfillAgain['created'] === 0, 'Die Rückprüfung muss wiederholbar sein, ohne doppelte Hinweise anzulegen.');

fwrite(STDOUT, "FairPlayTest erfolgreich.\n");
