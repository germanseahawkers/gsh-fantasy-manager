#!/usr/bin/env php
<?php

declare(strict_types=1);

use GSH\Fantasy\Allocator;
use GSH\Fantasy\Database;
use GSH\Fantasy\SleeperClient;

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::connection();
$now = date('Y-m-d H:i:s');

$closing = $pdo->prepare("SELECT * FROM seasons WHERE status='open' AND registration_closes_at <= ?");
$closing->execute([$now]);
foreach ($closing->fetchAll() as $season) {
    $pdo->prepare("UPDATE seasons SET status='closed', updated_at=? WHERE id=?")->execute([$now, $season['id']]);
}

$closed = $pdo->query("SELECT * FROM seasons WHERE status='closed'")->fetchAll();
foreach ($closed as $season) {
    $leaguesStatement = $pdo->prepare('SELECT id, capacity, admin_participant_id FROM leagues WHERE season_id=? ORDER BY sort_order, id');
    $leaguesStatement->execute([$season['id']]);
    $participantsStatement = $pdo->prepare('SELECT id FROM participants WHERE season_id=?');
    $participantsStatement->execute([$season['id']]);
    $leagues = $leaguesStatement->fetchAll();
    $participants = $participantsStatement->fetchAll();
    if ($leagues === [] || $participants === []) {
        continue;
    }

    try {
        $assignments = (new Allocator())->allocate($leagues, $participants);
        $pdo->beginTransaction();
        $update = $pdo->prepare("UPDATE participants SET league_id=?, mail_status='pending', mail_sent_at=NULL, updated_at=? WHERE id=?");
        foreach ($assignments as $participantId => $leagueId) {
            $update->execute([$leagueId, $now, $participantId]);
        }
        $pdo->prepare("UPDATE seasons SET status='assignment_draft', updated_at=? WHERE id=?")->execute([$now, $season['id']]);
        $pdo->commit();
        fwrite(STDOUT, "Saison {$season['id']} automatisch verteilt.\n");
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "Saison {$season['id']}: {$exception->getMessage()}\n");
    }
}

$approved = $pdo->query("SELECT id FROM seasons WHERE status='approved'")->fetchAll();
foreach ($approved as $season) {
    $statement = $pdo->prepare('SELECT * FROM leagues WHERE season_id=? AND sleeper_league_id IS NOT NULL');
    $statement->execute([$season['id']]);
    foreach ($statement->fetchAll() as $league) {
        try {
            $userIds = array_column((new SleeperClient())->leagueUsers((string) $league['sleeper_league_id']), 'user_id');
            if ($userIds === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $update = $pdo->prepare("UPDATE participants SET joined_sleeper_at=?, updated_at=? WHERE league_id=? AND sleeper_user_id IN ({$placeholders})");
            $update->execute([$now, $now, $league['id'], ...$userIds]);
        } catch (Throwable $exception) {
            fwrite(STDERR, "Sleeper-Liga {$league['id']}: {$exception->getMessage()}\n");
        }
    }
}

