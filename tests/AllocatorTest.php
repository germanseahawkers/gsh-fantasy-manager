<?php

declare(strict_types=1);

use GSH\Fantasy\Allocator;

require dirname(__DIR__) . '/src/bootstrap.php';

$allocator = new Allocator();
$leagues = [
    ['id' => 10, 'capacity' => 4, 'admin_participant_id' => 1],
    ['id' => 20, 'capacity' => 4, 'admin_participant_id' => 2],
];
$participants = array_map(static fn (int $id): array => ['id' => $id], range(1, 8));
$result = $allocator->allocate($leagues, $participants, 42);

assert($result[1] === 10, 'Admin 1 muss in Liga 10 bleiben.');
assert($result[2] === 20, 'Admin 2 muss in Liga 20 bleiben.');
assert(count(array_filter($result, static fn (int $league): bool => $league === 10)) === 4);
assert(count(array_filter($result, static fn (int $league): bool => $league === 20)) === 4);

$duplicateRejected = false;
try {
    $allocator->allocate([
        ['id' => 10, 'capacity' => 4, 'admin_participant_id' => 1],
        ['id' => 20, 'capacity' => 4, 'admin_participant_id' => 1],
    ], $participants, 42);
} catch (RuntimeException) {
    $duplicateRejected = true;
}
assert($duplicateRejected, 'Doppelte Liga-Admins müssen abgelehnt werden.');

$preassignedParticipants = [
    ['id' => 1, 'league_id' => 10],
    ['id' => 2, 'league_id' => 20],
    ['id' => 3, 'league_id' => 20],
    ['id' => 4, 'league_id' => null],
    ['id' => 5, 'league_id' => null],
    ['id' => 6, 'league_id' => null],
];
$preassignedResult = $allocator->allocate($leagues, $preassignedParticipants, 42);
assert($preassignedResult[3] === 20, 'Eine manuelle Vorabzuordnung darf nicht überschrieben werden.');
assert(count(array_filter($preassignedResult, static fn (int $league): bool => $league === 10)) === 3);
assert(count(array_filter($preassignedResult, static fn (int $league): bool => $league === 20)) === 3);

$waitlistResult = $allocator->allocateUnassigned([
    ['id' => 10, 'capacity' => 4, 'current_count' => 3],
    ['id' => 20, 'capacity' => 4, 'current_count' => 2],
], [
    ['id' => 9],
    ['id' => 10],
    ['id' => 11],
], 42);
assert(count(array_filter($waitlistResult, static fn (int $league): bool => $league === 10)) === 1);
assert(count(array_filter($waitlistResult, static fn (int $league): bool => $league === 20)) === 2);

$fullLeagueResult = $allocator->allocateUnassigned([
    ['id' => 10, 'capacity' => 4, 'current_count' => 4],
], [['id' => 12, 'created_at' => '2026-07-01 10:00:00']], 42);
assert($fullLeagueResult === [], 'Nachrücker müssen bei voller Liga auf der Warteliste bleiben.');

$firstComeResult = $allocator->allocateUnassigned([
    ['id' => 10, 'capacity' => 4, 'current_count' => 3],
], [
    ['id' => 30, 'created_at' => '2026-07-03 10:00:00'],
    ['id' => 20, 'created_at' => '2026-07-01 10:00:00'],
    ['id' => 10, 'created_at' => '2026-07-02 10:00:00'],
], 42);
assert(array_keys($firstComeResult) === [20], 'Der früheste Nachrücker muss den ersten freien Platz erhalten.');

$initialOverflowResult = $allocator->allocate([
    ['id' => 10, 'capacity' => 3, 'admin_participant_id' => 1],
], [
    ['id' => 1, 'league_id' => 10, 'created_at' => '2026-07-04 10:00:00'],
    ['id' => 4, 'league_id' => null, 'created_at' => '2026-07-04 10:00:00'],
    ['id' => 3, 'league_id' => null, 'created_at' => '2026-07-03 10:00:00'],
    ['id' => 2, 'league_id' => null, 'created_at' => '2026-07-02 10:00:00'],
], 42);
assert(isset($initialOverflowResult[2], $initialOverflowResult[3]), 'Die frühesten Anmeldungen müssen berücksichtigt werden.');
assert(!isset($initialOverflowResult[4]), 'Eine spätere Anmeldung muss bei ausgeschöpfter Kapazität auf der Warteliste bleiben.');

fwrite(STDOUT, "AllocatorTest erfolgreich.\n");
