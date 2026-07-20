<?php

declare(strict_types=1);

use GSH\Fantasy\Csrf;
use GSH\Fantasy\Database;
use GSH\Fantasy\Http;
use GSH\Fantasy\SleeperClient;

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::connection();
$season = $pdo->query("SELECT * FROM seasons ORDER BY registration_closes_at DESC LIMIT 1")->fetch() ?: null;
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();

    if (!empty($_POST['website'])) {
        Http::redirect('/?registered=1');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $memberNumber = trim((string) ($_POST['member_number'] ?? ''));
    $sleeperUsername = trim((string) ($_POST['sleeper_username'] ?? ''));
    $adminVolunteer = ($_POST['admin_volunteer'] ?? '') === '1' ? 1 : 0;

    if (!$season || $season['status'] !== 'open' || time() < strtotime($season['registration_opens_at']) || time() > strtotime($season['registration_closes_at'])) {
        $errors[] = 'Das Anmeldefenster ist derzeit geschlossen.';
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
        $errors[] = 'Bitte gib deinen vollständigen Namen ein.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    }
    if ($memberNumber === '' || mb_strlen($memberNumber) > 80) {
        $errors[] = 'Bitte gib deine GSH-Mitgliedsnummer ein.';
    }
    if (empty($_POST['privacy_consent'])) {
        $errors[] = 'Bitte bestätige die Datenschutzhinweise.';
    }

    $sleeperUser = null;
    if ($errors === []) {
        try {
            $sleeperUser = (new SleeperClient())->user($sleeperUsername);
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    if ($errors === [] && $sleeperUser) {
        try {
            $now = date('Y-m-d H:i:s');
            $statement = $pdo->prepare(
                'INSERT INTO participants
                (season_id, name, email, member_number, sleeper_username, sleeper_user_id, sleeper_display_name, admin_volunteer, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $season['id'], $name, $email, $memberNumber,
                $sleeperUser['username'] ?? $sleeperUsername,
                (string) $sleeperUser['user_id'],
                $sleeperUser['display_name'] ?? null,
                $adminVolunteer, $now, $now,
            ]);
            Http::redirect('/?registered=1');
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'UNIQUE') || str_contains($exception->getMessage(), 'Duplicate entry')) {
                $errors[] = 'Diese E-Mail-Adresse oder dieser Sleeper-Account ist bereits angemeldet.';
            } else {
                throw $exception;
            }
        }
    }
}

$success = ($_GET['registered'] ?? '') === '1';
$isOpen = $season
    && $season['status'] === 'open'
    && time() >= strtotime($season['registration_opens_at'])
    && time() <= strtotime($season['registration_closes_at']);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GSH Fantasy Football</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="public-page">
<main class="shell shell--narrow">
    <header class="hero">
        <p class="eyebrow">German Sea Hawkers</p>
        <h1>Fantasy Football</h1>
        <?php if ($season): ?>
            <p class="hero__season"><?= Http::e($season['name']) ?></p>
        <?php endif; ?>
    </header>

    <?php if ($success): ?>
        <section class="card success-card">
            <span class="status-icon">✓</span>
            <h2>Du bist dabei!</h2>
            <p>Deine Anmeldung wurde gespeichert. Nach der Liga-Zuteilung erhältst du alle weiteren Informationen per E-Mail.</p>
            <p><strong>GO HAWKS!</strong></p>
        </section>
    <?php elseif (!$isOpen): ?>
        <section class="card closed-card">
            <h2>Die Anmeldung ist geschlossen</h2>
            <p><?= $season ? 'Das Anmeldefenster endete am ' . Http::e(date('d.m.Y \u\m H:i \U\h\r', strtotime($season['registration_closes_at']))) . '.' : 'Aktuell ist keine Fantasy-Saison zur Anmeldung freigeschaltet.' ?></p>
        </section>
    <?php else: ?>
        <section class="card intro-card">
            <h2>Anmeldung</h2>
            <p>Melde dich bis <strong><?= Http::e(date('d.m.Y, H:i \U\h\r', strtotime($season['registration_closes_at']))) ?></strong> für unsere gemeinsamen GSH Fantasy Football Ligen an.</p>
            <?php if (!empty($season['draft_at'])): ?>
                <p>Der Draft findet am <strong><?= Http::e(date('d.m.Y \u\m H:i \U\h\r', strtotime($season['draft_at']))) ?></strong> statt.</p>
            <?php endif; ?>
            <p>Bitte erstelle vor der Anmeldung einen Sleeper-Account. Wir prüfen deinen Benutzernamen direkt bei Sleeper.</p>
        </section>

        <?php if ($errors): ?>
            <div class="alert alert--error" role="alert">
                <strong>Bitte prüfe deine Angaben:</strong>
                <ul><?php foreach ($errors as $error): ?><li><?= Http::e($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="post" class="card form-card" novalidate>
            <?= Csrf::field() ?>
            <div class="honeypot" aria-hidden="true">
                <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <label class="field">
                <span>Name <b>*</b></span>
                <input name="name" maxlength="160" autocomplete="name" required value="<?= Http::e($_POST['name'] ?? '') ?>">
            </label>
            <label class="field">
                <span>E-Mail-Adresse <b>*</b></span>
                <input type="email" name="email" maxlength="255" autocomplete="email" required value="<?= Http::e($_POST['email'] ?? '') ?>">
            </label>
            <label class="field">
                <span>GSH-Mitgliedsnummer <b>*</b></span>
                <input name="member_number" maxlength="80" required value="<?= Http::e($_POST['member_number'] ?? '') ?>">
            </label>
            <label class="field">
                <span>Sleeper-Name <b>*</b></span>
                <input name="sleeper_username" maxlength="100" required value="<?= Http::e($_POST['sleeper_username'] ?? '') ?>">
                <small>Gemeint ist dein allgemeiner Sleeper-Benutzername, nicht dein Teamname.</small>
            </label>

            <fieldset class="field choice-field">
                <legend>Kannst du dir vorstellen, eine Liga als Admin zu betreuen?</legend>
                <label><input type="radio" name="admin_volunteer" value="1" <?= ($_POST['admin_volunteer'] ?? '') === '1' ? 'checked' : '' ?> required> Ja, ich helfe gerne</label>
                <label><input type="radio" name="admin_volunteer" value="0" <?= ($_POST['admin_volunteer'] ?? '0') === '0' ? 'checked' : '' ?>> Nein, leider nicht</label>
            </fieldset>

            <label class="checkbox-field">
                <input type="checkbox" name="privacy_consent" value="1" required <?= !empty($_POST['privacy_consent']) ? 'checked' : '' ?>>
                <span>Ich stimme der Verarbeitung meiner Angaben für die Organisation der GSH Fantasy-Ligen zu. <b>*</b></span>
            </label>

            <button class="button button--primary button--large" type="submit">Verbindlich anmelden</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
