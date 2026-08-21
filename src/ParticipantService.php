<?php

declare(strict_types=1);

namespace GSH\Fantasy;

use PDO;
use RuntimeException;

final class ParticipantService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function move(int $participantId, ?int $leagueId): void
    {
        $participantStatement = $this->pdo->prepare('SELECT * FROM participants WHERE id=?');
        $participantStatement->execute([$participantId]);
        $participant = $participantStatement->fetch();
        if (!$participant) {
            throw new RuntimeException('Der Teilnehmer wurde nicht gefunden.');
        }

        $adminCheck = $this->pdo->prepare('SELECT id FROM leagues WHERE admin_participant_id=?');
        $adminCheck->execute([$participantId]);
        $adminLeague = $adminCheck->fetchColumn();
        if ($adminLeague !== false && (int) $adminLeague !== (int) ($leagueId ?? 0)) {
            throw new RuntimeException('Dieser Teilnehmer ist Liga-Admin und bleibt fest in seiner Liga. Ändere zuerst die Admin-Zuordnung.');
        }

        if ($leagueId === null) {
            $this->pdo->prepare("UPDATE participants SET league_id=NULL, joined_sleeper_at=NULL, mail_status='pending', updated_at=? WHERE id=?")
                ->execute([date('Y-m-d H:i:s'), $participantId]);
            return;
        }

        if ((int) ($participant['league_id'] ?? 0) === $leagueId) {
            return;
        }

        $capacityCheck = $this->pdo->prepare(
            'SELECT l.capacity, COUNT(p.id) AS current_count
             FROM leagues l
             LEFT JOIN participants p ON p.league_id=l.id
             WHERE l.id=? AND l.season_id=?
             GROUP BY l.id, l.capacity'
        );
        $capacityCheck->execute([$leagueId, $participant['season_id']]);
        $capacity = $capacityCheck->fetch();
        if (!$capacity) {
            throw new RuntimeException('Die Zielliga wurde nicht gefunden.');
        }
        if ((int) $capacity['current_count'] >= (int) $capacity['capacity']) {
            throw new RuntimeException('Diese Liga ist bereits voll.');
        }

        $this->pdo->prepare("UPDATE participants SET league_id=?, joined_sleeper_at=NULL, mail_status='pending', updated_at=? WHERE id=?")
            ->execute([$leagueId, date('Y-m-d H:i:s'), $participantId]);
    }

    public function delete(int $participantId): array
    {
        $participantStatement = $this->pdo->prepare('SELECT * FROM participants WHERE id=?');
        $participantStatement->execute([$participantId]);
        $participant = $participantStatement->fetch();
        if (!$participant) {
            throw new RuntimeException('Der Teilnehmer wurde nicht gefunden.');
        }

        $adminCheck = $this->pdo->prepare('SELECT id FROM leagues WHERE admin_participant_id=?');
        $adminCheck->execute([$participantId]);
        if ($adminCheck->fetchColumn() !== false) {
            throw new RuntimeException('Dieser Teilnehmer ist Liga-Admin und kann nicht gelöscht werden. Ändere zuerst die Admin-Zuordnung.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM mail_log WHERE participant_id=?')->execute([$participantId]);
            $this->pdo->prepare('DELETE FROM participants WHERE id=?')->execute([$participantId]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $participant;
    }
}
