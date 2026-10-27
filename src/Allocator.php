<?php

declare(strict_types=1);

namespace GSH\Fantasy;

use RuntimeException;

final class Allocator
{
    /**
     * @param array<int, array{id:int, capacity:int, admin_participant_id:?int}> $leagues
     * @param array<int, array{id:int, league_id?:?int, created_at?:string}> $participants
     * @return array<int, int> participant id => league id
     */
    public function allocate(array $leagues, array $participants, ?int $seed = null): array
    {
        if ($leagues === []) {
            throw new RuntimeException('Lege zuerst mindestens eine Liga an.');
        }

        $assignments = [];
        $counts = [];
        $capacities = [];
        $adminIds = [];

        foreach ($leagues as $league) {
            $leagueId = (int) $league['id'];
            $counts[$leagueId] = 0;
            $capacities[$leagueId] = (int) $league['capacity'];
            if (empty($league['admin_participant_id'])) {
                throw new RuntimeException('Vor der Verteilung muss für jede Liga genau ein Liga-Admin festgelegt werden.');
            }

            $participantId = (int) $league['admin_participant_id'];
            if (isset($adminIds[$participantId])) {
                throw new RuntimeException('Ein Liga-Admin darf nur einer Liga zugeordnet sein.');
            }
            $adminIds[$participantId] = true;
            $assignments[$participantId] = $leagueId;
            $counts[$leagueId]++;
        }

        foreach ($participants as $participant) {
            $participantId = (int) $participant['id'];
            $leagueId = (int) ($participant['league_id'] ?? 0);
            if ($leagueId === 0) {
                continue;
            }
            if (!isset($counts[$leagueId])) {
                throw new RuntimeException('Eine bestehende Teilnehmer-Zuordnung verweist auf eine unbekannte Liga.');
            }
            if (isset($assignments[$participantId])) {
                if ($assignments[$participantId] !== $leagueId) {
                    throw new RuntimeException('Ein Liga-Admin ist bereits einer anderen Liga zugeordnet.');
                }
                continue;
            }
            $assignments[$participantId] = $leagueId;
            $counts[$leagueId]++;
        }

        foreach ($counts as $leagueId => $count) {
            if ($count > $capacities[$leagueId]) {
                throw new RuntimeException('Mindestens eine Liga enthält bereits mehr zugeordnete Teilnehmer als verfügbare Plätze.');
            }
        }

        $pool = array_values(array_filter(
            $participants,
            static fn (array $participant): bool => !isset($assignments[(int) $participant['id']])
        ));
        self::sortByRegistration($pool);

        $freePlaces = array_sum(array_map(
            static fn (int $leagueId): int => max(0, $capacities[$leagueId] - $counts[$leagueId]),
            array_keys($counts)
        ));
        $pool = array_slice($pool, 0, $freePlaces);

        if ($seed !== null) {
            mt_srand($seed);
        }
        shuffle($pool);

        foreach ($pool as $participant) {
            $available = array_filter(
                array_keys($counts),
                static fn (int $leagueId): bool => $counts[$leagueId] < $capacities[$leagueId]
            );
            usort($available, static function (int $left, int $right) use ($counts): int {
                return $counts[$left] <=> $counts[$right] ?: $left <=> $right;
            });
            $leagueId = $available[0];
            $assignments[(int) $participant['id']] = $leagueId;
            $counts[$leagueId]++;
        }

        return $assignments;
    }

    /**
     * @param array<int, array{id:int, capacity:int, current_count:int}> $leagues
     * @param array<int, array{id:int, created_at?:string}> $participants
     * @return array<int, int> participant id => league id
     */
    public function allocateUnassigned(array $leagues, array $participants, ?int $seed = null): array
    {
        if ($participants === []) {
            return [];
        }
        if ($leagues === []) {
            throw new RuntimeException('Lege zuerst mindestens eine Liga an.');
        }

        $counts = [];
        $capacities = [];
        foreach ($leagues as $league) {
            $leagueId = (int) $league['id'];
            $counts[$leagueId] = (int) $league['current_count'];
            $capacities[$leagueId] = (int) $league['capacity'];
        }

        $pool = array_values($participants);
        self::sortByRegistration($pool);
        $freePlaces = array_sum(array_map(
            static fn (int $leagueId): int => max(0, $capacities[$leagueId] - $counts[$leagueId]),
            array_keys($counts)
        ));
        $pool = array_slice($pool, 0, $freePlaces);
        if ($seed !== null) {
            mt_srand($seed);
        }
        shuffle($pool);

        $assignments = [];
        foreach ($pool as $participant) {
            $available = array_filter(
                array_keys($counts),
                static fn (int $leagueId): bool => $counts[$leagueId] < $capacities[$leagueId]
            );
            usort($available, static function (int $left, int $right) use ($counts): int {
                return $counts[$left] <=> $counts[$right] ?: $left <=> $right;
            });
            $leagueId = $available[0];
            $assignments[(int) $participant['id']] = $leagueId;
            $counts[$leagueId]++;
        }

        return $assignments;
    }

    /** @param array<int, array{id:int, created_at?:string}> $participants */
    private static function sortByRegistration(array &$participants): void
    {
        usort($participants, static function (array $left, array $right): int {
            $leftCreatedAt = (string) ($left['created_at'] ?? '');
            $rightCreatedAt = (string) ($right['created_at'] ?? '');
            if ($leftCreatedAt !== '' && $rightCreatedAt !== '') {
                $comparison = strcmp($leftCreatedAt, $rightCreatedAt);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return (int) $left['id'] <=> (int) $right['id'];
        });
    }
}
