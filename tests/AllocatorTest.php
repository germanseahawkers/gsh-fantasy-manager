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

$waitlistOverflowRejected = false;
try {
    $allocator->allocateUnassigned([
        ['id' => 10, 'capacity' => 4, 'current_count' => 4],
    ], [['id' => 12]], 42);
} catch (RuntimeException) {
    $waitlistOverflowRejected = true;
}
assert($waitlistOverflowRejected, 'Nachrücker dürfen nicht über die Liga-Kapazität hinaus verteilt werden.');

fwrite(STDOUT, "AllocatorTest erfolgreich.\n");
