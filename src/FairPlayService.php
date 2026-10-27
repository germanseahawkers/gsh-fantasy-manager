<?php

declare(strict_types=1);

namespace GSH\Fantasy;

use PDO;
use RuntimeException;

final class FairPlayService
{
    private const SNAPSHOT_INTERVAL_SECONDS = 1800;
    private const FLAGGED_INJURY_STATUSES = ['OUT', 'IR'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?SleeperClient $client = null,
    ) {
    }

    /** @return array{week:int,snapshotted:bool,leagues:int,flagged:int,suggestions:int} */
    public function run(int $seasonId, bool $force = false): array
    {
        $season = $this->season($seasonId);
        $client = $this->client ?? new SleeperClient();
        $state = $client->nflState();
        $week = (int) ($state['week'] ?? 0);
        if ($week < 1 || $week > 25) {
            throw new RuntimeException('Sleeper liefert derzeit keine gültige NFL-Spielwoche.');
        }

        $snapshotted = $force
            || empty($season['fair_play_snapshot_at'])
            || strtotime((string) $season['fair_play_snapshot_at']) <= time() - self::SNAPSHOT_INTERVAL_SECONDS;
        $snapshot = ['leagues' => 0, 'flagged' => 0];
        if ($snapshotted) {
            $snapshot = $this->snapshot($seasonId, $week, $client);
        }

        return [
            'week' => $week,
            'snapshotted' => $snapshotted,
            'leagues' => $snapshot['leagues'],
            'flagged' => $snapshot['flagged'],
            'suggestions' => $this->evaluateCompletedWeeks($seasonId, $week, $client),
        ];
    }

    /** @return array{weeks:int,leagues:int,created:int} */
    public function backfillCompletedWeeks(int $seasonId): array
    {
        $this->season($seasonId);
        $client = $this->client ?? new SleeperClient();
        $state = $client->nflState();
        $currentWeek = (int) ($state['week'] ?? 0);
        if ($currentWeek < 1 || $currentWeek > 25) {
            throw new RuntimeException('Sleeper liefert derzeit keine gültige NFL-Spielwoche.');
        }
        if ($currentWeek === 1) {
            return ['weeks' => 0, 'leagues' => 0, 'created' => 0];
        }

        $leagueStatement = $this->pdo->prepare(
            "SELECT * FROM leagues WHERE season_id=? AND sleeper_league_id IS NOT NULL AND sleeper_league_id<>'' ORDER BY sort_order, id"
        );
        $leagueStatement->execute([$seasonId]);
        $leagues = $leagueStatement->fetchAll();
        if ($leagues === []) {
            throw new RuntimeException('Für die Fair-Play-Prüfung ist keine Liga mit Sleeper League-ID eingerichtet.');
        }

        $participantStatement = $this->pdo->prepare(
            'SELECT id, league_id, name, sleeper_user_id, sleeper_username FROM participants WHERE season_id=?'
        );
        $participantStatement->execute([$seasonId]);
        $participantsByLeagueAndOwner = [];
        foreach ($participantStatement->fetchAll() as $participant) {
            $participantsByLeagueAndOwner[(int) ($participant['league_id'] ?? 0) . ':' . $participant['sleeper_user_id']] = $participant;
        }

        $payloads = [];
        $playerIds = [];
        $rostersByLeague = [];
        $starterCountsByLeague = [];
        foreach ($leagues as $league) {
            $sleeperLeagueId = (string) $league['sleeper_league_id'];
            if (!preg_match('/^[0-9]+$/', $sleeperLeagueId)) {
                throw new RuntimeException('Die Sleeper League-ID für „' . $league['name'] . '“ ist ungültig.');
            }
            $rostersByLeague[(int) $league['id']] = $client->leagueRosters($sleeperLeagueId);
            $leagueInfo = $client->leagueInfo($sleeperLeagueId);
            $starterCountsByLeague[(int) $league['id']] = count(array_filter(
                $leagueInfo['roster_positions'] ?? [],
                static fn(mixed $position): bool => !in_array((string) $position, ['BN', 'IR', 'TAXI'], true),
            ));
        }
        for ($week = 1; $week < $currentWeek; ++$week) {
            foreach ($leagues as $league) {
                $sleeperLeagueId = (string) $league['sleeper_league_id'];
                $matchups = $client->leagueMatchups($sleeperLeagueId, $week);
                if ($matchups === []) {
                    continue;
                }
                foreach ($matchups as $matchup) {
                    foreach (($matchup['starters'] ?? []) as $playerId) {
                        $playerId = (string) $playerId;
                        if ($playerId !== '' && $playerId !== '0') {
                            $playerIds[$playerId] = true;
                        }
                    }
                }
                $payloads[] = ['week' => $week, 'league' => $league, 'rosters' => $rostersByLeague[(int) $league['id']], 'matchups' => $matchups];
            }
        }

        $players = $this->playersForIds(
            array_values(array_filter(array_keys($playerIds), static fn(string $playerId): bool => preg_match('/^[0-9]+$/', $playerId) === 1)),
            $client,
        );
        $suggestions = [];
        foreach ($payloads as $payload) {
            $league = $payload['league'];
            $ownersByRoster = [];
            foreach ($payload['rosters'] as $roster) {
                $ownersByRoster[(int) ($roster['roster_id'] ?? 0)] = (string) ($roster['owner_id'] ?? '');
            }
            foreach ($payload['matchups'] as $matchup) {
                $rosterId = (int) ($matchup['roster_id'] ?? 0);
                $ownerId = $ownersByRoster[$rosterId] ?? '';
                $participant = $participantsByLeagueAndOwner[(int) $league['id'] . ':' . $ownerId] ?? null;
                if (!$participant) {
                    continue;
                }

                $evidence = [];
                $nonEmptyStarters = 0;
                foreach (($matchup['starters'] ?? []) as $playerId) {
                    $playerId = (string) $playerId;
                    if ($playerId === '' || $playerId === '0') {
                        continue;
                    }
                    ++$nonEmptyStarters;
                    $points = $this->playerPoints($matchup, $playerId);
                    if ($points === null || abs($points) > 0.00001) {
                        continue;
                    }
                    $evidence[] = [
                        'origin' => 'retrospective',
                        'reason' => 'zero_points',
                        'player_id' => $playerId,
                        'player_name' => (string) ($players[$playerId]['full_name'] ?? $playerId),
                        'injury_status' => null,
                        'points' => $points,
                        'observed_at' => null,
                        'league_name' => (string) $league['name'],
                    ];
                }
                $expectedStarters = $starterCountsByLeague[(int) $league['id']] ?? 0;
                $explicitEmptySlots = count(array_filter(
                    $matchup['starters'] ?? [],
                    static fn(mixed $playerId): bool => (string) $playerId === '' || (string) $playerId === '0',
                ));
                $emptySlots = $expectedStarters > 0
                    ? max(0, $expectedStarters - $nonEmptyStarters)
                    : $explicitEmptySlots;
                for ($emptySlot = 1; $emptySlot <= $emptySlots; ++$emptySlot) {
                    $evidence[] = [
                        'origin' => 'retrospective',
                        'reason' => 'empty_slot',
                        'player_id' => '',
                        'player_name' => 'Leerer Starter-Slot ' . $emptySlot,
                        'injury_status' => null,
                        'points' => 0.0,
                        'observed_at' => null,
                        'league_name' => (string) $league['name'],
                    ];
                }
                if ($evidence === []) {
                    continue;
                }
                $suggestions[$participant['id'] . ':' . $payload['week']] = [
                    'identity' => $participant + ['league_id' => $league['id']],
                    'week' => $payload['week'],
                    'evidence' => $evidence,
                ];
            }
        }

        $created = 0;
        $find = $this->pdo->prepare('SELECT id FROM fair_play_strikes WHERE season_id=? AND participant_id=? AND week=?');
        $insert = $this->pdo->prepare(
            "INSERT INTO fair_play_strikes (season_id, league_id, participant_id, participant_name, sleeper_user_id, sleeper_username, week, origin, status, evidence_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'retrospective', 'suggested', ?, ?)"
        );
        $now = date('Y-m-d H:i:s');
        foreach ($suggestions as $suggestion) {
            $participant = $suggestion['identity'];
            $find->execute([$seasonId, $participant['id'], $suggestion['week']]);
            if ($find->fetchColumn() !== false) {
                continue;
            }
            $insert->execute([
                $seasonId, $participant['league_id'], $participant['id'], $participant['name'],
                $participant['sleeper_user_id'], $participant['sleeper_username'], $suggestion['week'],
                json_encode($suggestion['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now,
            ]);
            ++$created;
        }

        return ['weeks' => $currentWeek - 1, 'leagues' => count($leagues), 'created' => $created];
    }

    public function review(int $strikeId, string $status): void
    {
        if (!in_array($status, ['suggested', 'confirmed', 'dismissed'], true)) {
            throw new RuntimeException('Ungültige Strike-Entscheidung.');
        }
        $statement = $this->pdo->prepare('SELECT id FROM fair_play_strikes WHERE id=?');
        $statement->execute([$strikeId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Der Strike-Vorschlag wurde nicht gefunden.');
        }

        $reviewedAt = $status === 'suggested' ? null : date('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE fair_play_strikes SET status=?, reviewed_at=? WHERE id=?')
            ->execute([$status, $reviewedAt, $strikeId]);
    }

    /** @return array{leagues:int,flagged:int} */
    private function snapshot(int $seasonId, int $week, SleeperClient $client): array
    {
        $leagueStatement = $this->pdo->prepare(
            "SELECT * FROM leagues WHERE season_id=? AND sleeper_league_id IS NOT NULL AND sleeper_league_id<>'' ORDER BY sort_order, id"
        );
        $leagueStatement->execute([$seasonId]);
        $leagues = $leagueStatement->fetchAll();
        if ($leagues === []) {
            throw new RuntimeException('Für die Fair-Play-Prüfung ist keine Liga mit Sleeper League-ID eingerichtet.');
        }

        $participantStatement = $this->pdo->prepare(
            'SELECT id, league_id, name, sleeper_user_id, sleeper_username FROM participants WHERE season_id=?'
        );
        $participantStatement->execute([$seasonId]);
        $participantsByLeagueAndOwner = [];
        foreach ($participantStatement->fetchAll() as $participant) {
            $key = (int) ($participant['league_id'] ?? 0) . ':' . (string) $participant['sleeper_user_id'];
            $participantsByLeagueAndOwner[$key] = $participant;
        }

        $leaguePayloads = [];
        $starterIds = [];
        foreach ($leagues as $league) {
            $sleeperLeagueId = (string) $league['sleeper_league_id'];
            if (!preg_match('/^[0-9]+$/', $sleeperLeagueId)) {
                throw new RuntimeException('Die Sleeper League-ID für „' . $league['name'] . '“ ist ungültig.');
            }
            $rosters = $client->leagueRosters($sleeperLeagueId);
            $matchups = $client->leagueMatchups($sleeperLeagueId, $week);
            foreach ($matchups as $matchup) {
                foreach (($matchup['starters'] ?? []) as $playerId) {
                    $playerId = (string) $playerId;
                    if (preg_match('/^[0-9]+$/', $playerId)) {
                        $starterIds[$playerId] = true;
                    }
                }
            }
            $leaguePayloads[] = ['league' => $league, 'rosters' => $rosters, 'matchups' => $matchups];
        }

        $players = $this->playersForIds(array_keys($starterIds), $client);
        $now = date('Y-m-d H:i:s');
        $flagged = 0;
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE fair_play_lineup_evidence SET currently_flagged=0, last_seen_at=? WHERE season_id=? AND week=?'
            )->execute([$now, $seasonId, $week]);
            $findEvidence = $this->pdo->prepare(
                'SELECT id FROM fair_play_lineup_evidence WHERE league_id=? AND week=? AND roster_id=? AND player_id=?'
            );
            $updateEvidence = $this->pdo->prepare(
                'UPDATE fair_play_lineup_evidence SET participant_id=?, participant_name=?, sleeper_user_id=?, sleeper_username=?, player_name=?, injury_status=?, currently_flagged=1, last_seen_at=? WHERE id=?'
            );
            $insertEvidence = $this->pdo->prepare(
                'INSERT INTO fair_play_lineup_evidence (season_id, league_id, participant_id, participant_name, sleeper_user_id, sleeper_username, week, roster_id, player_id, player_name, injury_status, currently_flagged, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );

            foreach ($leaguePayloads as $payload) {
                $league = $payload['league'];
                $ownersByRoster = [];
                foreach ($payload['rosters'] as $roster) {
                    $ownersByRoster[(int) ($roster['roster_id'] ?? 0)] = (string) ($roster['owner_id'] ?? '');
                }
                foreach ($payload['matchups'] as $matchup) {
                    $rosterId = (int) ($matchup['roster_id'] ?? 0);
                    $ownerId = $ownersByRoster[$rosterId] ?? '';
                    $participant = $participantsByLeagueAndOwner[(int) $league['id'] . ':' . $ownerId] ?? null;
                    if (!$participant) {
                        continue;
                    }

                    foreach (($matchup['starters'] ?? []) as $playerId) {
                        $playerId = (string) $playerId;
                        $player = $players[$playerId] ?? null;
                        $injuryStatus = strtoupper(trim((string) ($player['injury_status'] ?? '')));
                        if (!$player || !in_array($injuryStatus, self::FLAGGED_INJURY_STATUSES, true)) {
                            continue;
                        }

                        ++$flagged;
                        $findEvidence->execute([$league['id'], $week, $rosterId, $playerId]);
                        $evidenceId = $findEvidence->fetchColumn();
                        $values = [
                            $participant['id'], $participant['name'], $participant['sleeper_user_id'],
                            $participant['sleeper_username'], $player['full_name'], $injuryStatus, $now,
                        ];
                        if ($evidenceId !== false) {
                            $updateEvidence->execute([...$values, $evidenceId]);
                        } else {
                            $insertEvidence->execute([
                                $seasonId, $league['id'], $participant['id'], $participant['name'],
                                $participant['sleeper_user_id'], $participant['sleeper_username'], $week,
                                $rosterId, $playerId, $player['full_name'], $injuryStatus, $now, $now,
                            ]);
                        }
                    }
                }
            }

            $this->pdo->prepare('UPDATE seasons SET fair_play_snapshot_at=?, updated_at=? WHERE id=?')
                ->execute([$now, $now, $seasonId]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['leagues' => count($leaguePayloads), 'flagged' => $flagged];
    }

    private function evaluateCompletedWeeks(int $seasonId, int $currentWeek, SleeperClient $client): int
    {
        $statement = $this->pdo->prepare(
            'SELECT e.*, l.name AS league_name, l.sleeper_league_id
             FROM fair_play_lineup_evidence e
             JOIN leagues l ON l.id=e.league_id
             WHERE e.season_id=? AND e.currently_flagged=1 AND e.week<?
             ORDER BY e.week, e.league_id, e.roster_id, e.player_name'
        );
        $statement->execute([$seasonId, $currentWeek]);
        $evidenceRows = $statement->fetchAll();

        $byLeagueAndWeek = [];
        foreach ($evidenceRows as $evidence) {
            $byLeagueAndWeek[$evidence['league_id'] . ':' . $evidence['week']][] = $evidence;
        }

        $suggestions = [];
        foreach ($byLeagueAndWeek as $rows) {
            $first = $rows[0];
            $matchups = $client->leagueMatchups((string) $first['sleeper_league_id'], (int) $first['week']);
            $matchupsByRoster = [];
            foreach ($matchups as $matchup) {
                $matchupsByRoster[(int) ($matchup['roster_id'] ?? 0)] = $matchup;
            }

            foreach ($rows as $evidence) {
                $matchup = $matchupsByRoster[(int) $evidence['roster_id']] ?? null;
                if (!$matchup || !in_array((string) $evidence['player_id'], array_map('strval', $matchup['starters'] ?? []), true)) {
                    continue;
                }
                $points = $this->playerPoints($matchup, (string) $evidence['player_id']);
                if ($points === null || abs($points) > 0.00001) {
                    continue;
                }

                $key = $evidence['participant_id'] . ':' . $evidence['week'];
                $suggestions[$key]['identity'] = $evidence;
                $suggestions[$key]['evidence'][] = [
                    'player_id' => (string) $evidence['player_id'],
                    'player_name' => (string) $evidence['player_name'],
                    'injury_status' => (string) $evidence['injury_status'],
                    'points' => $points,
                    'observed_at' => (string) $evidence['last_seen_at'],
                    'league_name' => (string) $evidence['league_name'],
                ];
            }
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM fair_play_strikes WHERE season_id=? AND week<? AND status='suggested' AND origin='live'")
                ->execute([$seasonId, $currentWeek]);
            $find = $this->pdo->prepare('SELECT id FROM fair_play_strikes WHERE season_id=? AND participant_id=? AND week=?');
            $insert = $this->pdo->prepare(
                "INSERT INTO fair_play_strikes (season_id, league_id, participant_id, participant_name, sleeper_user_id, sleeper_username, week, status, evidence_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'suggested', ?, ?)"
            );
            $now = date('Y-m-d H:i:s');
            foreach ($suggestions as $suggestion) {
                $identity = $suggestion['identity'];
                $find->execute([$seasonId, $identity['participant_id'], $identity['week']]);
                if ($find->fetchColumn() !== false) {
                    continue;
                }
                $insert->execute([
                    $seasonId, $identity['league_id'], $identity['participant_id'], $identity['participant_name'],
                    $identity['sleeper_user_id'], $identity['sleeper_username'], $identity['week'],
                    json_encode($suggestion['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return count($suggestions);
    }

    /** @param list<string> $playerIds @return array<string, array{full_name:string,injury_status:?string,player_status:?string}> */
    private function playersForIds(array $playerIds, SleeperClient $client): array
    {
        if ($playerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
        $statement = $this->pdo->prepare("SELECT * FROM sleeper_player_cache WHERE player_id IN ({$placeholders})");
        $statement->execute($playerIds);
        $cached = [];
        foreach ($statement->fetchAll() as $player) {
            $cached[(string) $player['player_id']] = $player;
        }

        $today = date('Y-m-d 00:00:00');
        $refresh = count($cached) !== count($playerIds);
        foreach ($cached as $player) {
            // Sleeper bittet darum, die große Spielerliste höchstens einmal täglich abzurufen.
            // 16 Uhr deutscher Zeit ist für die üblichen NFL-Spieltermine aussagekräftiger als Mitternacht.
            if ((string) $player['fetched_at'] < $today && (int) date('G') >= 16) {
                $refresh = true;
                break;
            }
        }
        if (!$refresh) {
            return $cached;
        }

        $allPlayers = $client->nflPlayers();
        $now = date('Y-m-d H:i:s');
        $find = $this->pdo->prepare('SELECT player_id FROM sleeper_player_cache WHERE player_id=?');
        $update = $this->pdo->prepare('UPDATE sleeper_player_cache SET full_name=?, injury_status=?, player_status=?, fetched_at=? WHERE player_id=?');
        $insert = $this->pdo->prepare('INSERT INTO sleeper_player_cache (player_id, full_name, injury_status, player_status, fetched_at) VALUES (?, ?, ?, ?, ?)');
        foreach ($playerIds as $playerId) {
            $source = $allPlayers[$playerId] ?? [];
            $fullName = trim((string) ($source['full_name'] ?? ''));
            if ($fullName === '') {
                $fullName = trim((string) (($source['first_name'] ?? '') . ' ' . ($source['last_name'] ?? '')));
            }
            $player = [
                'full_name' => $fullName !== '' ? $fullName : $playerId,
                'injury_status' => isset($source['injury_status']) ? (string) $source['injury_status'] : null,
                'player_status' => isset($source['status']) ? (string) $source['status'] : null,
            ];
            $find->execute([$playerId]);
            if ($find->fetchColumn() !== false) {
                $update->execute([$player['full_name'], $player['injury_status'], $player['player_status'], $now, $playerId]);
            } else {
                $insert->execute([$playerId, $player['full_name'], $player['injury_status'], $player['player_status'], $now]);
            }
            $cached[$playerId] = $player + ['player_id' => $playerId, 'fetched_at' => $now];
        }
        return $cached;
    }

    private function playerPoints(array $matchup, string $playerId): ?float
    {
        if (isset($matchup['players_points']) && array_key_exists($playerId, $matchup['players_points'])) {
            return (float) $matchup['players_points'][$playerId];
        }
        $starters = array_map('strval', $matchup['starters'] ?? []);
        $index = array_search($playerId, $starters, true);
        if ($index !== false && isset($matchup['starters_points'][$index])) {
            return (float) $matchup['starters_points'][$index];
        }
        return null;
    }

    private function season(int $seasonId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM seasons WHERE id=?');
        $statement->execute([$seasonId]);
        $season = $statement->fetch();
        if (!$season) {
            throw new RuntimeException('Die Saison wurde nicht gefunden.');
        }
        return $season;
    }
}
