<?php

declare(strict_types=1);

namespace GSH\Fantasy;

use RuntimeException;

final class Allocator
{
    /**
     * @param array<int, array{id:int, capacity:int, admin_participant_id:?int}> $leagues
     * @param array<int, array{id:int}> $participants
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

        $pool = array_values(array_filter(
            $participants,
            static fn (array $participant): bool => !isset($adminIds[(int) $participant['id']])
        ));

        if ($seed !== null) {
            mt_srand($seed);
        }
        shuffle($pool);

        foreach ($pool as $participant) {
            $available = array_filter(
                array_keys($counts),
                static fn (int $leagueId): bool => $counts[$leagueId] < $capacities[$leagueId]
            );
            if ($available === []) {
                throw new RuntimeException('Die eingestellten Liga-Kapazitäten reichen nicht für alle Teilnehmer.');
            }

            usort($available, static function (int $left, int $right) use ($counts): int {
                return $counts[$left] <=> $counts[$right] ?: $left <=> $right;
            });
            $leagueId = $available[0];
            $assignments[(int) $participant['id']] = $leagueId;
            $counts[$leagueId]++;
        }

        return $assignments;
    }
}
