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

fwrite(STDOUT, "AllocatorTest erfolgreich.\n");

