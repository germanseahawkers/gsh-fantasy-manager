<?php

declare(strict_types=1);

namespace GSH\Fantasy;

use PDO;
use RuntimeException;

final class SleeperAudit
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?SleeperClient $client = null,
    ) {
    }

    /** @return array{joined:int,mismatches:int,unknown:int,checked_at:string} */
    public function sync(int $seasonId): array
    {
        $leagueStatement = $this->pdo->prepare('SELECT * FROM leagues WHERE season_id=? ORDER BY sort_order, id');
        $leagueStatement->execute([$seasonId]);
        $leagues = $leagueStatement->fetchAll();
        if ($leagues === []) {
            throw new RuntimeException('Für diese Saison sind keine Ligen eingerichtet.');
        }

        $memberships = [];
        $displayNames = [];
        $client = $this->client ?? new SleeperClient();
        foreach ($leagues as $league) {
            $sleeperLeagueId = trim((string) ($league['sleeper_league_id'] ?? ''));
            if (!preg_match('/^[0-9]+$/', $sleeperLeagueId)) {
                throw new RuntimeException('Für die Liga „' . $league['name'] . '“ fehlt eine gültige Sleeper League-ID.');
            }

            foreach ($client->leagueUsers($sleeperLeagueId) as $user) {
                $userId = (string) ($user['user_id'] ?? '');
                if ($userId !== '') {
                    $displayNames[$userId] = (string) ($user['display_name'] ?? $user['username'] ?? $userId);
                }
            }
            foreach ($client->leagueRosterOwners($sleeperLeagueId) as $userId) {
                $memberships[(string) $userId][(int) $league['id']] = true;
                $displayNames[(string) $userId] ??= (string) $userId;
            }
        }

        return $this->persist($seasonId, $memberships, $displayNames);
    }

    /**
     * @param array<string, array<int, bool>> $memberships
     * @param array<string, string> $displayNames
     * @return array{joined:int,mismatches:int,unknown:int,checked_at:string}
     */
    public function persist(int $seasonId, array $memberships, array $displayNames = []): array
    {
        $participantStatement = $this->pdo->prepare('SELECT id, sleeper_user_id, league_id, joined_sleeper_at FROM participants WHERE season_id=?');
        $participantStatement->execute([$seasonId]);
        $participants = $participantStatement->fetchAll();
        $participantIds = array_fill_keys(array_map(
            static fn(array $participant): string => (string) $participant['sleeper_user_id'],
            $participants
        ), true);

        $checkedAt = date('Y-m-d H:i:s');
        $joined = 0;
        $mismatches = 0;
        $this->pdo->beginTransaction();
        try {
            $participantUpdate = $this->pdo->prepare('UPDATE participants SET joined_sleeper_at=?, updated_at=? WHERE id=?');
            foreach ($participants as $participant) {
                $actualLeagueIds = array_map('intval', array_keys($memberships[(string) $participant['sleeper_user_id']] ?? []));
                sort($actualLeagueIds);
                $expectedLeagueId = (int) ($participant['league_id'] ?? 0);
                $isJoined = $expectedLeagueId > 0 && in_array($expectedLeagueId, $actualLeagueIds, true);
                if ($isJoined) {
                    ++$joined;
                }
                if (self::isMismatch($expectedLeagueId ?: null, $actualLeagueIds)) {
                    ++$mismatches;
                }
                $joinedAt = $isJoined ? ($participant['joined_sleeper_at'] ?: $checkedAt) : null;
                $participantUpdate->execute([$joinedAt, $checkedAt, $participant['id']]);
            }

            $this->pdo->prepare('DELETE FROM sleeper_audit_memberships WHERE season_id=?')->execute([$seasonId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO sleeper_audit_memberships (season_id, sleeper_user_id, display_name, league_ids, checked_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($memberships as $userId => $leagueSet) {
                $leagueIds = array_map('intval', array_keys($leagueSet));
                sort($leagueIds);
                $insert->execute([$seasonId, $userId, $displayNames[$userId] ?? $userId, implode(',', $leagueIds), $checkedAt]);
            }
            $this->pdo->prepare('UPDATE seasons SET sleeper_checked_at=?, updated_at=? WHERE id=?')
                ->execute([$checkedAt, $checkedAt, $seasonId]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $unknown = count(array_filter(
            array_keys($memberships),
            static fn(string $userId): bool => !isset($participantIds[$userId])
        ));

        return ['joined' => $joined, 'mismatches' => $mismatches, 'unknown' => $unknown, 'checked_at' => $checkedAt];
    }

    /** @param int[] $actualLeagueIds */
    public static function isMismatch(?int $expectedLeagueId, array $actualLeagueIds): bool
    {
        $actualLeagueIds = array_values(array_unique(array_map('intval', $actualLeagueIds)));
        if ($actualLeagueIds === []) {
            return false;
        }
        return $expectedLeagueId === null
            || count($actualLeagueIds) !== 1
            || $actualLeagueIds[0] !== $expectedLeagueId;
    }
}
