<?php

declare(strict_types=1);

use GSH\Fantasy\Allocator;
use GSH\Fantasy\Auth;
use GSH\Fantasy\Csrf;
use GSH\Fantasy\Database;
use GSH\Fantasy\Http;
use GSH\Fantasy\Mailer;
use GSH\Fantasy\ParticipantService;
use GSH\Fantasy\SleeperAudit;
use GSH\Fantasy\SleeperClient;

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::connection();
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    Csrf::verify();
    if (Auth::attempt((string) ($_POST['password'] ?? ''))) {
        Http::redirect('/admin.php');
    }
    Http::flash('error', 'Passwort falsch oder vorübergehend zu viele Versuche.');
    Http::redirect('/admin.php');
}

if (!Auth::check()):
    $flash = Http::pullFlash();
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>GSH Fantasy – Admin</title><link rel="stylesheet" href="/assets/app.css"></head>
<body class="admin-login"><main class="login-shell">
    <div class="login-brand"><p class="eyebrow">German Sea Hawkers</p><h1>Fantasy Admin</h1></div>
    <?php if ($flash): ?><div class="alert alert--<?= Http::e($flash['type']) ?>"><?= Http::e($flash['message']) ?></div><?php endif; ?>
    <form method="post" class="card login-card">
        <?= Csrf::field() ?><input type="hidden" name="action" value="login">
        <label class="field"><span>Adminpasswort</span><input type="password" name="password" autocomplete="current-password" required autofocus></label>
        <button class="button button--primary button--large" type="submit">Anmelden</button>
    </form>
</main></body></html>
<?php
    exit;
endif;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
    Csrf::verify();
    Auth::logout();
    Http::redirect('/admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();

    try {
        if ($action === 'save_season') {
            $id = (int) ($_POST['season_id'] ?? 0);
            $values = [
                trim((string) $_POST['name']),
                str_replace('T', ' ', (string) $_POST['registration_opens_at']) . ':00',
                str_replace('T', ' ', (string) $_POST['registration_closes_at']) . ':00',
                !empty($_POST['draft_at']) ? str_replace('T', ' ', (string) $_POST['draft_at']) . ':00' : null,
                trim((string) $_POST['email_subject']),
                trim((string) $_POST['email_intro']),
            ];
            if ($values[0] === '' || strtotime($values[1]) >= strtotime($values[2])) {
                throw new RuntimeException('Bitte prüfe Saisonname und Anmeldezeitraum.');
            }
            $now = date('Y-m-d H:i:s');
            if ($id > 0) {
                $statement = $pdo->prepare('UPDATE seasons SET name=?, registration_opens_at=?, registration_closes_at=?, draft_at=?, email_subject=?, email_intro=?, updated_at=? WHERE id=?');
                $statement->execute([...$values, $now, $id]);
            } else {
                $statement = $pdo->prepare('INSERT INTO seasons (name, registration_opens_at, registration_closes_at, draft_at, email_subject, email_intro, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $statement->execute([...$values, $now, $now]);
            }
            Http::flash('success', 'Saisondaten gespeichert.');
        } elseif ($action === 'set_status') {
            $allowed = ['open', 'closed', 'assignment_draft', 'approved'];
            $status = (string) ($_POST['status'] ?? '');
            if (!in_array($status, $allowed, true)) {
                throw new RuntimeException('Ungültiger Status.');
            }
            $pdo->prepare('UPDATE seasons SET status=?, updated_at=? WHERE id=?')->execute([$status, date('Y-m-d H:i:s'), (int) $_POST['season_id']]);
            Http::flash('success', 'Saisonstatus aktualisiert.');
        } elseif ($action === 'update_sleeper') {
            $participantId = (int) ($_POST['participant_id'] ?? 0);
            $username = trim((string) ($_POST['sleeper_username'] ?? ''));
            $participantStatement = $pdo->prepare('SELECT * FROM participants WHERE id=?');
            $participantStatement->execute([$participantId]);
            $participant = $participantStatement->fetch();
            if (!$participant) {
                throw new RuntimeException('Der Teilnehmer wurde nicht gefunden.');
            }

            $sleeperUser = (new SleeperClient())->user($username);
            $sleeperUserId = (string) $sleeperUser['user_id'];
            $duplicateStatement = $pdo->prepare('SELECT name FROM participants WHERE season_id=? AND sleeper_user_id=? AND id<>?');
            $duplicateStatement->execute([$participant['season_id'], $sleeperUserId, $participantId]);
            $duplicateName = $duplicateStatement->fetchColumn();
            if ($duplicateName !== false) {
                throw new RuntimeException('Dieser Sleeper-Account ist bereits „' . $duplicateName . '“ zugeordnet.');
            }

            $identityChanged = (string) $participant['sleeper_user_id'] !== $sleeperUserId;
            $canonicalUsername = (string) ($sleeperUser['username'] ?? $username);
            $pdo->prepare(
                'UPDATE participants
                 SET sleeper_username=?, sleeper_user_id=?, sleeper_display_name=?,
                     joined_sleeper_at=?, updated_at=?
                 WHERE id=?'
            )->execute([
                $canonicalUsername,
                $sleeperUserId,
                $sleeperUser['display_name'] ?? null,
                $identityChanged ? null : $participant['joined_sleeper_at'],
                date('Y-m-d H:i:s'),
                $participantId,
            ]);
            Http::flash('success', 'Sleeper-Account für „' . $participant['name'] . '“ wurde auf @' . $canonicalUsername . ' aktualisiert.');
        } elseif ($action === 'add_league') {
            $now = date('Y-m-d H:i:s');
            $statement = $pdo->prepare('INSERT INTO leagues (season_id, name, capacity, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            $statement->execute([(int) $_POST['season_id'], trim((string) $_POST['name']), max(1, (int) $_POST['capacity']), (int) $_POST['sort_order'], $now, $now]);
            Http::flash('success', 'Liga angelegt.');
        } elseif ($action === 'save_league') {
            $statement = $pdo->prepare('UPDATE leagues SET name=?, sleeper_league_id=?, invite_url=?, capacity=?, sort_order=?, updated_at=? WHERE id=?');
            $statement->execute([
                trim((string) $_POST['name']), trim((string) $_POST['sleeper_league_id']) ?: null,
                trim((string) $_POST['invite_url']) ?: null, max(1, (int) $_POST['capacity']),
                (int) $_POST['sort_order'], date('Y-m-d H:i:s'), (int) $_POST['league_id'],
            ]);
            Http::flash('success', 'Liga gespeichert.');
        } elseif ($action === 'delete_league') {
            $leagueId = (int) $_POST['league_id'];
            $check = $pdo->prepare('SELECT COUNT(*) FROM participants WHERE league_id=?');
            $check->execute([$leagueId]);
            if ((int) $check->fetchColumn() > 0) {
                throw new RuntimeException('Eine Liga mit zugeteilten Teilnehmern kann nicht gelöscht werden.');
            }
            $pdo->prepare('DELETE FROM leagues WHERE id=?')->execute([$leagueId]);
            Http::flash('success', 'Liga gelöscht.');
        } elseif ($action === 'set_league_admin') {
            $leagueId = (int) $_POST['league_id'];
            $participantId = (int) ($_POST['participant_id'] ?? 0);
            $pdo->beginTransaction();
            if ($participantId > 0) {
                $pdo->prepare('UPDATE leagues SET admin_participant_id=NULL, updated_at=? WHERE admin_participant_id=?')->execute([date('Y-m-d H:i:s'), $participantId]);
                $pdo->prepare('UPDATE leagues SET admin_participant_id=?, updated_at=? WHERE id=?')->execute([$participantId, date('Y-m-d H:i:s'), $leagueId]);
                $pdo->prepare('UPDATE participants SET league_id=?, updated_at=? WHERE id=?')->execute([$leagueId, date('Y-m-d H:i:s'), $participantId]);
            } else {
                $pdo->prepare('UPDATE leagues SET admin_participant_id=NULL, updated_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), $leagueId]);
            }
            $pdo->commit();
            Http::flash('success', 'Liga-Admin aktualisiert. Jeder Admin ist höchstens einer Liga zugeordnet.');
        } elseif ($action === 'allocate') {
            $seasonId = (int) $_POST['season_id'];
            $seasonCheck = $pdo->prepare('SELECT status FROM seasons WHERE id=?');
            $seasonCheck->execute([$seasonId]);
            $seasonStatus = $seasonCheck->fetchColumn();
            if ($seasonStatus === false) {
                throw new RuntimeException('Die Saison wurde nicht gefunden.');
            }
            $isWaitlistAllocation = in_array($seasonStatus, ['assignment_draft', 'approved'], true);
            if ($isWaitlistAllocation) {
                $leagueStatement = $pdo->prepare('SELECT l.id, l.capacity, COUNT(p.id) AS current_count FROM leagues l LEFT JOIN participants p ON p.league_id=l.id WHERE l.season_id=? GROUP BY l.id, l.capacity, l.sort_order ORDER BY l.sort_order, l.id');
                $leagueStatement->execute([$seasonId]);
                $participantStatement = $pdo->prepare('SELECT id FROM participants WHERE season_id=? AND league_id IS NULL ORDER BY id');
                $participantStatement->execute([$seasonId]);
                $assignments = (new Allocator())->allocateUnassigned($leagueStatement->fetchAll(), $participantStatement->fetchAll());
            } else {
                $leagueStatement = $pdo->prepare('SELECT id, capacity, admin_participant_id FROM leagues WHERE season_id=? ORDER BY sort_order, id');
                $leagueStatement->execute([$seasonId]);
                $participantStatement = $pdo->prepare('SELECT id, league_id FROM participants WHERE season_id=?');
                $participantStatement->execute([$seasonId]);
                $assignments = (new Allocator())->allocate($leagueStatement->fetchAll(), $participantStatement->fetchAll());
            }
            if ($assignments === []) {
                Http::flash('success', 'Es sind aktuell keine unzugeteilten Nachrücker vorhanden.');
            } else {
                $pdo->beginTransaction();
                $update = $pdo->prepare('UPDATE participants SET league_id=?, mail_status=?, updated_at=? WHERE id=?');
                foreach ($assignments as $participantId => $leagueId) {
                    $update->execute([$leagueId, 'pending', date('Y-m-d H:i:s'), $participantId]);
                }
                if (!$isWaitlistAllocation) {
                    $pdo->prepare("UPDATE seasons SET status='assignment_draft', updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $seasonId]);
                }
                $pdo->commit();
                Http::flash('success', $isWaitlistAllocation
                    ? count($assignments) . ' Nachrücker wurden auf freie Ligaplätze verteilt.'
                    : count($assignments) . ' Teilnehmer wurden gleichmäßig verteilt.');
            }
        } elseif ($action === 'delete_participant') {
            $participantId = (int) ($_POST['participant_id'] ?? 0);
            $participant = (new ParticipantService($pdo))->delete($participantId);
            Http::flash('success', 'Teilnehmer „' . $participant['name'] . '“ wurde gelöscht.');
        } elseif ($action === 'move_participant') {
            header('Content-Type: application/json; charset=UTF-8');
            $leagueId = (int) ($_POST['league_id'] ?? 0);
            (new ParticipantService($pdo))->move(
                (int) ($_POST['participant_id'] ?? 0),
                $leagueId > 0 ? $leagueId : null,
            );
            echo json_encode(['ok' => true]);
            exit;
        } elseif ($action === 'test_mail') {
            $recipient = mb_strtolower(trim((string) ($_POST['test_email'] ?? '')));
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Bitte gib eine gültige Empfängeradresse für die Testmail ein.');
            }
            $result = (new Mailer())->sendTest($recipient);
            if (!$result['sent']) {
                throw new RuntimeException('Testmail fehlgeschlagen: ' . $result['error']);
            }
            Http::flash('success', "Testmail wurde an {$recipient} gesendet.");
        } elseif ($action === 'send_mails') {
            $seasonId = (int) $_POST['season_id'];
            $seasonStatement = $pdo->prepare('SELECT * FROM seasons WHERE id=?');
            $seasonStatement->execute([$seasonId]);
            $mailSeason = $seasonStatement->fetch();
            $queue = $pdo->prepare('SELECT p.*, l.name AS league_name, l.invite_url FROM participants p JOIN leagues l ON l.id=p.league_id WHERE p.season_id=? AND (p.invitation_league_id IS NULL OR p.invitation_league_id<>p.league_id) ORDER BY p.id');
            $queue->execute([$seasonId]);
            $sent = 0;
            $failed = 0;
            $mailer = new Mailer();
            foreach ($queue->fetchAll() as $participant) {
                $claim = $pdo->prepare("UPDATE participants SET mail_status='sending', updated_at=? WHERE id=? AND mail_status<>'sending' AND (invitation_league_id IS NULL OR invitation_league_id<>league_id)");
                $claim->execute([date('Y-m-d H:i:s'), $participant['id']]);
                if ($claim->rowCount() !== 1) {
                    continue;
                }
                $result = $mailer->sendAssignment($participant, ['name' => $participant['league_name'], 'invite_url' => $participant['invite_url']], $mailSeason);
                $status = $result['sent'] ? 'sent' : 'failed';
                $sentAt = $result['sent'] ? date('Y-m-d H:i:s') : $participant['mail_sent_at'];
                $invitationLeagueId = $result['sent'] ? $participant['league_id'] : $participant['invitation_league_id'];
                $pdo->prepare('UPDATE participants SET mail_status=?, mail_sent_at=?, invitation_league_id=?, updated_at=? WHERE id=?')->execute([$status, $sentAt, $invitationLeagueId, date('Y-m-d H:i:s'), $participant['id']]);
                $pdo->prepare('INSERT INTO mail_log (participant_id, recipient, subject, status, error_message, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$participant['id'], $participant['email'], $mailSeason['email_subject'], $status, $result['error'], date('Y-m-d H:i:s')]);
                $result['sent'] ? $sent++ : $failed++;
            }
            $pdo->prepare("UPDATE seasons SET status='approved', updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $seasonId]);
            Http::flash($failed ? 'error' : 'success', "Versand abgeschlossen: {$sent} gesendet, {$failed} fehlgeschlagen.");
        } elseif ($action === 'send_reminders') {
            $seasonId = (int) $_POST['season_id'];
            $seasonStatement = $pdo->prepare('SELECT * FROM seasons WHERE id=?');
            $seasonStatement->execute([$seasonId]);
            $mailSeason = $seasonStatement->fetch();
            if (!$mailSeason) {
                throw new RuntimeException('Die Saison wurde nicht gefunden.');
            }

            (new SleeperAudit($pdo))->sync($seasonId);

            $queue = $pdo->prepare('SELECT p.*, l.name AS league_name, l.invite_url FROM participants p JOIN leagues l ON l.id=p.league_id WHERE p.season_id=? AND p.invitation_league_id=p.league_id AND p.joined_sleeper_at IS NULL ORDER BY p.id');
            $queue->execute([$seasonId]);
            $recipients = $queue->fetchAll();
            if ($recipients === []) {
                Http::flash('success', 'Alle eingeladenen Teilnehmer sind bereits beigetreten. Es wurden keine Erinnerungen versendet.');
            } else {
                $sent = 0;
                $failed = 0;
                $subject = 'Erinnerung: ' . $mailSeason['email_subject'];
                $mailer = new Mailer();
                foreach ($recipients as $participant) {
                    $result = $mailer->sendReminder($participant, ['name' => $participant['league_name'], 'invite_url' => $participant['invite_url']], $mailSeason);
                    $status = $result['sent'] ? 'sent' : 'failed';
                    $pdo->prepare('INSERT INTO mail_log (participant_id, recipient, subject, status, error_message, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$participant['id'], $participant['email'], $subject, $status, $result['error'], date('Y-m-d H:i:s')]);
                    $result['sent'] ? $sent++ : $failed++;
                }
                Http::flash($failed ? 'error' : 'success', "Reminder-Versand abgeschlossen: {$sent} gesendet, {$failed} fehlgeschlagen.");
            }
        } elseif ($action === 'sync_sleeper') {
            $seasonId = (int) $_POST['season_id'];
            $result = (new SleeperAudit($pdo))->sync($seasonId);
            Http::flash(
                ($result['mismatches'] || $result['unknown']) ? 'error' : 'success',
                "Sleeper-Abgleich abgeschlossen: {$result['joined']} korrekt beigetreten, {$result['mismatches']} abweichende Zuordnungen, {$result['unknown']} Sleeper-Mitglieder ohne App-Anmeldung."
            );
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($action === 'move_participant') {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
            exit;
        }
        Http::flash('error', $exception->getMessage());
    }

    Http::redirect('/admin.php');
}

$season = $pdo->query('SELECT * FROM seasons ORDER BY registration_closes_at DESC LIMIT 1')->fetch() ?: null;
$leagues = [];
$participants = [];
$unknownSleeperMembers = [];
$sleeperAuditCheckedAt = null;
if ($season) {
    $statement = $pdo->prepare('SELECT * FROM leagues WHERE season_id=? ORDER BY sort_order, id');
    $statement->execute([$season['id']]);
    $leagues = $statement->fetchAll();
    $statement = $pdo->prepare('SELECT p.*, CASE WHEN p.invitation_league_id IS NOT NULL THEN 1 ELSE 0 END AS has_received_invitation, CASE WHEN p.invitation_league_id=p.league_id THEN 1 ELSE 0 END AS invitation_sent FROM participants p WHERE p.season_id=? ORDER BY p.name');
    $statement->execute([$season['id']]);
    $participants = $statement->fetchAll();

    $leagueNamesById = [];
    foreach ($leagues as $league) {
        $leagueNamesById[(int) $league['id']] = (string) $league['name'];
    }
    $auditStatement = $pdo->prepare('SELECT * FROM sleeper_audit_memberships WHERE season_id=? ORDER BY display_name');
    $auditStatement->execute([$season['id']]);
    $auditBySleeperId = [];
    foreach ($auditStatement->fetchAll() as $auditEntry) {
        $leagueIds = array_values(array_filter(array_map('intval', explode(',', (string) $auditEntry['league_ids']))));
        $auditEntry['league_ids_parsed'] = $leagueIds;
        $auditEntry['league_names'] = array_map(
            static fn(int $leagueId): string => $leagueNamesById[$leagueId] ?? 'Unbekannte Liga',
            $leagueIds
        );
        $auditBySleeperId[(string) $auditEntry['sleeper_user_id']] = $auditEntry;
    }
    $sleeperAuditCheckedAt = $season['sleeper_checked_at'] ?? null;
    $knownSleeperIds = [];
    foreach ($participants as &$participant) {
        $sleeperId = (string) $participant['sleeper_user_id'];
        $knownSleeperIds[$sleeperId] = true;
        $auditEntry = $auditBySleeperId[$sleeperId] ?? null;
        $actualLeagueIds = $auditEntry['league_ids_parsed'] ?? [];
        $participant['sleeper_actual_league_names'] = $auditEntry['league_names'] ?? [];
        $participant['sleeper_mismatch'] = SleeperAudit::isMismatch(
            !empty($participant['league_id']) ? (int) $participant['league_id'] : null,
            $actualLeagueIds,
        );
    }
    unset($participant);
    foreach ($auditBySleeperId as $sleeperId => $auditEntry) {
        if (!isset($knownSleeperIds[$sleeperId])) {
            $unknownSleeperMembers[] = $auditEntry;
        }
    }
}
$participantsByLeague = [];
foreach ($participants as $participant) {
    $participantsByLeague[(int) ($participant['league_id'] ?? 0)][] = $participant;
}
if (!empty($participantsByLeague[0])) {
    usort($participantsByLeague[0], static function (array $left, array $right): int {
        return strcmp((string) $left['created_at'], (string) $right['created_at'])
            ?: ((int) $left['id'] <=> (int) $right['id']);
    });
}
$adminParticipantIds = array_filter(array_column($leagues, 'admin_participant_id'));
$sleeperMismatchCount = count(array_filter($participants, fn(array $participant): bool => (bool) ($participant['sleeper_mismatch'] ?? false)));
$reminderCandidateCount = count(array_filter($participants, fn($participant) => (bool) $participant['invitation_sent'] && empty($participant['joined_sleeper_at'])));
$allocationCompleted = $season && in_array($season['status'], ['assignment_draft', 'approved'], true);
$flash = Http::pullFlash();
$statusLabels = ['open' => 'Reguläre Anmeldung offen', 'closed' => 'Reguläre Frist beendet', 'assignment_draft' => 'Zuteilung in Bearbeitung', 'approved' => 'Freigegeben'];
?>
<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>GSH Fantasy – Admin</title><link rel="stylesheet" href="/assets/app.css"></head>
<body class="admin-page" data-csrf="<?= Http::e(Csrf::token()) ?>">
<header class="admin-header"><div><p class="eyebrow">German Sea Hawkers</p><h1>Fantasy Manager</h1></div><nav><a href="/" target="_blank">Anmeldung ansehen</a><form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="logout"><button class="link-button" type="submit">Abmelden</button></form></nav></header>
<main class="admin-main">
    <?php if ($flash): ?><div class="alert alert--<?= Http::e($flash['type']) ?>"><?= Http::e($flash['message']) ?></div><?php endif; ?>

    <section class="admin-section">
        <div class="section-heading"><div><p class="eyebrow">Konfiguration</p><h2><?= $season ? Http::e($season['name']) : 'Neue Saison' ?></h2></div><?php if ($season): ?><span class="status-badge status-badge--<?= Http::e($season['status']) ?>"><?= Http::e($statusLabels[$season['status']] ?? $season['status']) ?></span><?php endif; ?></div>
        <details class="card settings-card" <?= $season ? '' : 'open' ?>><summary>Saisondaten und Mailtext</summary>
            <form method="post" class="settings-grid">
                <?= Csrf::field() ?><input type="hidden" name="action" value="save_season"><input type="hidden" name="season_id" value="<?= (int) ($season['id'] ?? 0) ?>">
                <label class="field"><span>Saisonname</span><input name="name" required value="<?= Http::e($season['name'] ?? 'GSH Fantasy Football 2026') ?>"></label>
                <label class="field"><span>Anmeldung öffnet</span><input type="datetime-local" name="registration_opens_at" required value="<?= Http::e(isset($season['registration_opens_at']) ? date('Y-m-d\TH:i', strtotime($season['registration_opens_at'])) : date('Y-m-d\TH:i')) ?>"></label>
                <label class="field"><span>Anmeldung schließt</span><input type="datetime-local" name="registration_closes_at" required value="<?= Http::e(isset($season['registration_closes_at']) ? date('Y-m-d\TH:i', strtotime($season['registration_closes_at'])) : '2026-07-24T23:59') ?>"></label>
                <label class="field"><span>Draft</span><input type="datetime-local" name="draft_at" value="<?= Http::e(!empty($season['draft_at']) ? date('Y-m-d\TH:i', strtotime($season['draft_at'])) : '2026-09-06T20:00') ?>"></label>
                <label class="field field--wide"><span>Mail-Betreff</span><input name="email_subject" required value="<?= Http::e($season['email_subject'] ?? 'Deine GSH Fantasy-Liga') ?>"></label>
                <label class="field field--wide"><span>Einleitung der Zuteilungsmail</span><textarea name="email_intro" rows="3"><?= Http::e($season['email_intro'] ?? 'Deine Zuteilung für die GSH Fantasy Football Saison steht fest.') ?></textarea></label>
                <button class="button button--primary" type="submit">Saisondaten speichern</button>
            </form>
        </details>
    </section>

    <?php if ($season): ?>
    <section class="stats-grid">
        <div class="stat-card"><strong><?= count($participants) ?></strong><span>Anmeldungen</span></div>
        <div class="stat-card"><strong><?= count(array_filter($participants, fn($p) => (bool) $p['admin_volunteer'])) ?></strong><span>Admin-Freiwillige</span></div>
        <div class="stat-card"><strong><?= count($leagues) ?></strong><span>Ligen</span></div>
        <div class="stat-card"><strong><?= count(array_filter($participants, fn($p) => (bool) $p['joined_sleeper_at'])) ?></strong><span>Sleeper beigetreten</span></div>
    </section>

    <section class="admin-section">
        <div class="section-heading"><div><p class="eyebrow">Schritt 1</p><h2>Ligen einrichten</h2></div></div>
        <div class="league-settings-grid">
            <?php foreach ($leagues as $league): ?>
            <form method="post" class="card league-settings-card">
                <?= Csrf::field() ?><input type="hidden" name="action" value="save_league"><input type="hidden" name="league_id" value="<?= (int) $league['id'] ?>">
                <label class="field"><span>Name</span><input name="name" required value="<?= Http::e($league['name']) ?>"></label>
                <div class="inline-fields"><label class="field"><span>Plätze</span><input type="number" name="capacity" min="1" max="32" value="<?= (int) $league['capacity'] ?>"></label><label class="field"><span>Reihenfolge</span><input type="number" name="sort_order" value="<?= (int) $league['sort_order'] ?>"></label></div>
                <label class="field"><span>Sleeper League-ID</span><input name="sleeper_league_id" inputmode="numeric" value="<?= Http::e($league['sleeper_league_id']) ?>"></label>
                <label class="field"><span>Sleeper-Einladungslink</span><input type="url" name="invite_url" value="<?= Http::e($league['invite_url']) ?>"></label>
                <button class="button button--secondary" type="submit">Liga speichern</button>
            </form>
            <?php endforeach; ?>
            <form method="post" class="card league-settings-card league-settings-card--new">
                <?= Csrf::field() ?><input type="hidden" name="action" value="add_league"><input type="hidden" name="season_id" value="<?= (int) $season['id'] ?>">
                <h3>Weitere Liga</h3><label class="field"><span>Name</span><input name="name" placeholder="z. B. NFC West" required></label><div class="inline-fields"><label class="field"><span>Plätze</span><input type="number" name="capacity" min="1" max="32" value="12"></label><label class="field"><span>Reihenfolge</span><input type="number" name="sort_order" value="<?= count($leagues) + 1 ?>"></label></div><button class="button button--primary" type="submit">Liga hinzufügen</button>
            </form>
        </div>
    </section>

    <section class="admin-section">
        <div class="section-heading"><div><p class="eyebrow">Schritt 2</p><h2>Liga-Admins festlegen</h2><p>Ein bestätigter Admin wird genau einer Liga zugeordnet und bei der Verteilung nicht verschoben.</p></div></div>
        <div class="admin-assignment-grid">
        <?php foreach ($leagues as $league): ?>
            <form method="post" class="card admin-assignment-card">
                <?= Csrf::field() ?><input type="hidden" name="action" value="set_league_admin"><input type="hidden" name="league_id" value="<?= (int) $league['id'] ?>">
                <strong><?= Http::e($league['name']) ?></strong>
                <select name="participant_id">
                    <option value="0">Noch nicht festgelegt</option>
                    <?php foreach ($participants as $participant): ?>
                        <option value="<?= (int) $participant['id'] ?>" <?= (int) $league['admin_participant_id'] === (int) $participant['id'] ? 'selected' : '' ?>><?= Http::e($participant['name']) ?><?= $participant['admin_volunteer'] ? ' · freiwillig' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button button--secondary" type="submit">Admin speichern</button>
            </form>
        <?php endforeach; ?>
        </div>
    </section>

    <section class="admin-section">
        <div class="section-heading"><div><p class="eyebrow">Schritt 3</p><h2>Teilnehmer verteilen</h2><p><?= $allocationCompleted ? 'Die bestehende Verteilung bleibt unverändert. Nur unzugeteilte Nachrücker werden gleichmäßig auf freie Plätze verteilt.' : 'Du kannst einzelne Spieler zuerst per Drag-and-drop fest zuordnen. Die automatische Verteilung behält diese Zuordnungen bei und randomisiert nur den verbleibenden Rest.' ?></p></div><form method="post"<?= $allocationCompleted ? ' data-confirm="Jetzt nur die unzugeteilten Nachrücker auf freie Ligaplätze verteilen?"' : '' ?>><?= Csrf::field() ?><input type="hidden" name="action" value="allocate"><input type="hidden" name="season_id" value="<?= (int) $season['id'] ?>"><button class="button button--primary" type="submit"><?= $allocationCompleted ? 'Nachrücker automatisch verteilen' : 'Rest automatisch verteilen' ?></button></form></div>

        <?php if ($sleeperAuditCheckedAt): ?>
        <div class="sleeper-audit-summary <?= ($sleeperMismatchCount || $unknownSleeperMembers) ? 'sleeper-audit-summary--warning' : 'sleeper-audit-summary--ok' ?>">
            <strong>Sleeper-Abgleich vom <?= Http::e(date('d.m.Y, H:i', strtotime((string) $sleeperAuditCheckedAt))) ?></strong>
            <span><?= $sleeperMismatchCount ?> abweichende Zuordnung<?= $sleeperMismatchCount === 1 ? '' : 'en' ?> · <?= count($unknownSleeperMembers) ?> Mitglied<?= count($unknownSleeperMembers) === 1 ? '' : 'er' ?> ohne App-Anmeldung</span>
            <?php if ($unknownSleeperMembers): ?>
            <ul>
                <?php foreach ($unknownSleeperMembers as $member): ?>
                <li><?= Http::e($member['display_name']) ?> · Sleeper: <?= Http::e(implode(', ', $member['league_names'])) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="sleeper-audit-summary"><span>Noch kein vollständiger Sleeper-Abgleich gespeichert. Nutze „Sleeper-Beitritte &amp; Ligen prüfen“, um falsche Liga-Zuordnungen sichtbar zu machen.</span></div>
        <?php endif; ?>

        <div class="unassigned card" data-league-id="0">
            <div><h3>Nachrücker / noch nicht zugeteilt</h3><p>Ziehe eine Person auf eine Liga mit freiem Platz oder hierher, um sie aus einer Liga zu entfernen.</p></div>
            <div class="unassigned-list" data-dropzone>
                <?php if (!empty($participantsByLeague[0])): ?>
                    <?php foreach ($participantsByLeague[0] as $participant): $isWaitlist = strtotime($participant['created_at']) > strtotime($season['registration_closes_at']); $mailDisplayStatus = $participant['invitation_sent'] ? 'sent' : $participant['mail_status']; ?>
                    <article class="participant-card <?= $participant['sleeper_mismatch'] ? 'participant-card--mismatch' : '' ?>" draggable="true" data-participant-id="<?= (int) $participant['id'] ?>" data-invitation-sent="<?= $participant['has_received_invitation'] ? 'true' : 'false' ?>">
                        <div><strong><?= Http::e($participant['name']) ?></strong><span>@<?= Http::e($participant['sleeper_username']) ?></span><?php if ($participant['sleeper_mismatch']): ?><span class="mismatch-detail">Sleeper: <?= Http::e(implode(', ', $participant['sleeper_actual_league_names'])) ?></span><?php endif; ?></div>
                        <div class="card-tags"><?php if ($participant['sleeper_mismatch']): ?><span class="tag tag--mismatch">Falsche Liga</span><?php endif; ?><?php if ($isWaitlist): ?><span class="tag tag--waitlist">Nachrücker</span><?php endif; ?><span class="mail-dot mail-dot--<?= Http::e($mailDisplayStatus) ?>" title="Einladung: <?= Http::e($mailDisplayStatus) ?>"></span></div>
                    </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="empty-unassigned-notice">Keine unzugeteilten Nachrücker.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="league-board">
            <?php foreach ($leagues as $league):
                $leagueParticipants = $participantsByLeague[(int) $league['id']] ?? [];
                $joinedCount = count(array_filter($leagueParticipants, fn(array $participant): bool => !empty($participant['joined_sleeper_at'])));
                $invitedCount = count(array_filter($leagueParticipants, fn(array $participant): bool => (bool) $participant['invitation_sent']));
                $visibleParticipants = array_filter($leagueParticipants, fn(array $participant): bool => empty($participant['joined_sleeper_at']) || (bool) $participant['sleeper_mismatch'] || (int) $league['admin_participant_id'] === (int) $participant['id']);
                $hiddenJoinedParticipants = array_filter($leagueParticipants, fn(array $participant): bool => !empty($participant['joined_sleeper_at']) && !(bool) $participant['sleeper_mismatch'] && (int) $league['admin_participant_id'] !== (int) $participant['id']);
            ?>
            <div class="league-column" data-league-id="<?= (int) $league['id'] ?>">
                <header>
                    <h3><?= Http::e($league['name']) ?></h3>
                    <div class="league-occupancy" aria-label="<?= $joinedCount ?> beigetreten, <?= $invitedCount ?> eingeladen, <?= (int) $league['capacity'] ?> Plätze">
                        <strong><?= $joinedCount ?></strong>
                        <i>/</i>
                        <strong><?= $invitedCount ?></strong>
                        <i>/</i>
                        <strong><?= (int) $league['capacity'] ?></strong>
                    </div>
                </header>
                <div class="participant-list" data-dropzone>
                    <?php foreach ($visibleParticipants as $participant): $isAdmin = (int) $league['admin_participant_id'] === (int) $participant['id']; $isWaitlist = strtotime($participant['created_at']) > strtotime($season['registration_closes_at']); $mailDisplayStatus = $participant['invitation_sent'] ? 'sent' : $participant['mail_status']; ?>
                    <article class="participant-card <?= $isAdmin ? 'participant-card--admin' : '' ?> <?= $participant['sleeper_mismatch'] ? 'participant-card--mismatch' : '' ?>" draggable="<?= $isAdmin ? 'false' : 'true' ?>" data-participant-id="<?= (int) $participant['id'] ?>" data-invitation-sent="<?= $participant['has_received_invitation'] ? 'true' : 'false' ?>">
                        <div><strong><?= Http::e($participant['name']) ?></strong><span>@<?= Http::e($participant['sleeper_username']) ?></span><?php if ($participant['sleeper_mismatch']): ?><span class="mismatch-detail">Sleeper: <?= Http::e(implode(', ', $participant['sleeper_actual_league_names'])) ?></span><?php endif; ?></div>
                        <div class="card-tags"><?php if ($participant['sleeper_mismatch']): ?><span class="tag tag--mismatch">Falsche Liga</span><?php endif; ?><?php if ($isAdmin): ?><span class="tag tag--admin">Liga-Admin</span><?php endif; ?><?php if ($isWaitlist): ?><span class="tag tag--waitlist">Nachrücker</span><?php endif; ?><?php if ($participant['joined_sleeper_at']): ?><span class="tag tag--joined">Beigetreten</span><?php endif; ?><span class="mail-dot mail-dot--<?= Http::e($mailDisplayStatus) ?>" title="Einladung: <?= Http::e($mailDisplayStatus) ?>"></span></div>
                    </article>
                    <?php endforeach; ?>
                    <?php if ($hiddenJoinedParticipants): ?>
                    <details class="joined-participants">
                        <summary>Bereits Beigetretene anzeigen (<?= count($hiddenJoinedParticipants) ?>)</summary>
                        <div class="joined-participants-list">
                            <?php foreach ($hiddenJoinedParticipants as $participant): $isWaitlist = strtotime($participant['created_at']) > strtotime($season['registration_closes_at']); $mailDisplayStatus = $participant['invitation_sent'] ? 'sent' : $participant['mail_status']; ?>
                            <article class="participant-card" draggable="true" data-participant-id="<?= (int) $participant['id'] ?>" data-invitation-sent="<?= $participant['has_received_invitation'] ? 'true' : 'false' ?>">
                                <div><strong><?= Http::e($participant['name']) ?></strong><span>@<?= Http::e($participant['sleeper_username']) ?></span></div>
                                <div class="card-tags"><?php if ($isWaitlist): ?><span class="tag tag--waitlist">Nachrücker</span><?php endif; ?><span class="tag tag--joined">Beigetreten</span><span class="mail-dot mail-dot--<?= Http::e($mailDisplayStatus) ?>" title="Einladung: <?= Http::e($mailDisplayStatus) ?>"></span></div>
                            </article>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="admin-section action-panel card">
        <div><p class="eyebrow">Schritt 4</p><h2>Freigeben und versenden</h2><p>Zuteilungsmails gehen nur an neu zugeteilte Nachrücker und an Teilnehmer, deren Liga seit ihrer letzten Einladung geändert wurde. Vor Erinnerungen wird der Sleeper-Beitritt automatisch erneut geprüft.</p></div>
        <div class="action-buttons">
            <form method="post" class="test-mail-form"><?= Csrf::field() ?><input type="hidden" name="action" value="test_mail"><label class="field"><span>Testmail an</span><input type="email" name="test_email" placeholder="name@example.com" required></label><button class="button button--secondary" type="submit">Testmail senden</button></form>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="sync_sleeper"><input type="hidden" name="season_id" value="<?= (int) $season['id'] ?>"><button class="button button--secondary" type="submit">Sleeper-Beitritte &amp; Ligen prüfen</button></form>
            <form method="post" data-confirm="Sleeper-Beitritte jetzt prüfen und danach nur noch nicht beigetretene Teilnehmer erinnern?"><?= Csrf::field() ?><input type="hidden" name="action" value="send_reminders"><input type="hidden" name="season_id" value="<?= (int) $season['id'] ?>"><button class="button button--secondary" type="submit">Nicht Beigetretene erinnern (<?= $reminderCandidateCount ?>)</button></form>
            <form method="post" data-confirm="Jetzt alle offenen Zuteilungsmails versenden?"><?= Csrf::field() ?><input type="hidden" name="action" value="send_mails"><input type="hidden" name="season_id" value="<?= (int) $season['id'] ?>"><button class="button button--primary" type="submit">Freigeben & Mails versenden</button></form>
        </div>
    </section>

    <section class="admin-section">
        <details class="card participant-table-card">
            <summary>Alle Anmeldungen anzeigen</summary>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Name</th><th>E-Mail</th><th>Mitglied</th><th>Sleeper</th><th>Admin-Interesse</th><th>Einladung</th><th>Aktionen</th></tr></thead>
                    <tbody>
                    <?php foreach ($participants as $participant): ?>
                        <tr class="<?= $participant['sleeper_mismatch'] ? 'participant-row--mismatch' : '' ?>">
                            <td><?= Http::e($participant['name']) ?></td>
                            <td><?= Http::e($participant['email']) ?></td>
                            <td><?= Http::e($participant['member_number']) ?></td>
                            <td>
                                <form method="post" class="sleeper-edit-form" data-confirm="Sleeper-Account für <?= Http::e($participant['name']) ?> wirklich aktualisieren?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="update_sleeper">
                                    <input type="hidden" name="participant_id" value="<?= (int) $participant['id'] ?>">
                                    <span>@</span>
                                    <input name="sleeper_username" maxlength="100" required aria-label="Sleeper-Name für <?= Http::e($participant['name']) ?>" value="<?= Http::e($participant['sleeper_username']) ?>">
                                    <button class="button button--secondary button--compact" type="submit">Speichern</button>
                                </form>
                            </td>
                            <td><?= $participant['admin_volunteer'] ? 'Ja' : 'Nein' ?></td>
                            <td><?= $participant['invitation_sent'] ? (!empty($participant['mail_sent_at']) ? Http::e(date('d.m.Y, H:i', strtotime($participant['mail_sent_at']))) : 'Gesendet') : ($participant['has_received_invitation'] ? ($participant['mail_status'] === 'failed' ? 'Neue Einladung fehlgeschlagen' : 'Neue Einladung offen') : Http::e($participant['mail_status'])) ?></td>
                            <td>
                                <?php if (in_array((int) $participant['id'], array_map('intval', $adminParticipantIds), true)): ?>
                                <span class="muted-action">Erst Admin-Zuordnung ändern</span>
                                <?php else: ?>
                                <form method="post" class="inline-form" data-confirm="Teilnehmer „<?= Http::e($participant['name']) ?>“ wirklich unwiderruflich aus dem Fantasy Manager löschen? Eine bestehende Mitgliedschaft in Sleeper wird dadurch nicht entfernt und muss dort separat gelöscht werden.">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete_participant">
                                    <input type="hidden" name="participant_id" value="<?= (int) $participant['id'] ?>">
                                    <button class="button button--danger button--compact" type="submit">Löschen</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    </section>
    <?php endif; ?>
</main>
<script src="/assets/admin.js" defer></script>
</body></html>
