#!/usr/bin/env php
<?php

declare(strict_types=1);

use GSH\Fantasy\Database;
use GSH\Fantasy\SleeperClient;

require dirname(__DIR__) . '/src/bootstrap.php';

function usage(): void
{
    fwrite(STDOUT, <<<'TEXT'
Importiert eine aus Google Forms/Sheets exportierte CSV-Datei.

Aufruf:
  php bin/import-participants.php --season=ID|latest --file=/pfad/anmeldungen.csv --dry-run
  php bin/import-participants.php --season=ID|latest --file=/pfad/anmeldungen.csv

Vor dem echten Import sollte immer zuerst ein Dry-Run ausgeführt werden.

TEXT);
}

function normalizedHeader(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $value = mb_strtolower(trim($value));
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}

/** @return array<string, int> */
function mapHeaders(array $headers): array
{
    $mapped = [];

    foreach ($headers as $index => $header) {
        $header = normalizedHeader((string) $header);

        if ($header === 'zeitstempel') {
            $mapped['timestamp'] = $index;
        } elseif ($header === 'name') {
            $mapped['name'] = $index;
        } elseif (in_array($header, ['e-mail-adresse', 'email-adresse', 'e-mail', 'email'], true)) {
            $mapped['email'] = $index;
        } elseif (str_contains($header, 'gsh') && str_contains($header, 'mitgliedsnummer')) {
            $mapped['member_number'] = $index;
        } elseif (str_starts_with($header, 'sleeper name') || str_starts_with($header, 'sleeper-name')) {
            $mapped['sleeper_username'] = $index;
        } elseif (str_contains($header, 'liga') && str_contains($header, 'admin')) {
            $mapped['admin_volunteer'] = $index;
        }
    }

    $required = ['name', 'email', 'member_number', 'sleeper_username', 'admin_volunteer'];
    $missing = array_values(array_diff($required, array_keys($mapped)));
    if ($missing !== []) {
        throw new RuntimeException('Nicht erkannte Pflichtspalten: ' . implode(', ', $missing));
    }

    return $mapped;
}

/** @return resource */
function openCsv(string $path)
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('CSV-Datei konnte nicht geöffnet werden.');
    }
    return $handle;
}

function detectDelimiter(string $path): string
{
    $bestDelimiter = ',';
    $bestCount = 0;

    foreach ([',', ';', "\t"] as $delimiter) {
        $handle = openCsv($path);
        $fields = fgetcsv($handle, null, $delimiter, '"', '');
        fclose($handle);
        $count = is_array($fields) ? count($fields) : 0;
        if ($count > $bestCount) {
            $bestDelimiter = $delimiter;
            $bestCount = $count;
        }
    }

    return $bestDelimiter;
}

function adminVolunteer(string $value): ?int
{
    $value = mb_strtolower(trim($value));
    if ($value === '') {
        return null;
    }
    if (preg_match('/^(nein|no|0|false|falsch)(\b|$)/u', $value) || str_contains($value, 'nicht')) {
        return 0;
    }
    if (preg_match('/^(ja|yes|1|true|wahr)(\b|$)/u', $value)
        || str_contains($value, 'kann ich mir vorstellen')
        || str_contains($value, 'gerne')) {
        return 1;
    }
    return null;
}

function importedAt(string $value, string $fallback): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }

    if (preg_match('/^(.*)\s+(OESZ|OEZ|MESZ|MEZ)$/u', $value, $matches)) {
        $offsets = [
            'OESZ' => '+03:00',
            'OEZ' => '+02:00',
            'MESZ' => '+02:00',
            'MEZ' => '+01:00',
        ];
        $date = DateTimeImmutable::createFromFormat(
            '!Y/m/d g:i:s A P',
            $matches[1] . ' ' . $offsets[$matches[2]]
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        }
    }

    foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y, H:i:s', 'd.m.Y, H:i', 'Y-m-d H:i:s', 'Y/m/d H:i:s', 'Y/m/d g:i:s A', 'm/d/Y H:i:s', 'm/d/Y H:i'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    return $fallback;
}

$options = getopt('', ['season:', 'file:', 'dry-run', 'help']);
if (isset($options['help'])) {
    usage();
    exit(0);
}

$seasonOption = trim((string) ($options['season'] ?? ''));
$fileOption = trim((string) ($options['file'] ?? ''));
$dryRun = array_key_exists('dry-run', $options);

if ($seasonOption === '' || $fileOption === '') {
    usage();
    fwrite(STDERR, "Fehler: --season und --file sind erforderlich.\n");
    exit(2);
}

$file = realpath($fileOption);
if ($file === false || !is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "Fehler: CSV-Datei wurde nicht gefunden oder ist nicht lesbar.\n");
    exit(2);
}

$pdo = Database::connection();
if ($seasonOption === 'latest') {
    $season = $pdo->query('SELECT * FROM seasons ORDER BY registration_closes_at DESC, id DESC LIMIT 1')->fetch();
} elseif (ctype_digit($seasonOption) && (int) $seasonOption > 0) {
    $statement = $pdo->prepare('SELECT * FROM seasons WHERE id=?');
    $statement->execute([(int) $seasonOption]);
    $season = $statement->fetch();
} else {
    fwrite(STDERR, "Fehler: --season muss eine positive ID oder latest sein.\n");
    exit(2);
}

if (!$season) {
    fwrite(STDERR, "Fehler: Saison wurde nicht gefunden.\n");
    exit(2);
}
if (!in_array($season['status'], ['open', 'closed'], true)) {
    fwrite(STDERR, "Fehler: In eine bereits zugeteilte oder freigegebene Saison kann nicht importiert werden.\n");
    exit(2);
}

$delimiter = detectDelimiter($file);
$handle = openCsv($file);
$headers = fgetcsv($handle, null, $delimiter, '"', '');
if (!is_array($headers)) {
    fclose($handle);
    fwrite(STDERR, "Fehler: Die CSV-Datei enthält keine Kopfzeile.\n");
    exit(2);
}

try {
    $columns = mapHeaders($headers);
} catch (RuntimeException $exception) {
    fclose($handle);
    fwrite(STDERR, 'Fehler: ' . $exception->getMessage() . "\n");
    exit(2);
}

$existingStatement = $pdo->prepare('SELECT email, sleeper_user_id FROM participants WHERE season_id=?');
$existingStatement->execute([$season['id']]);
$existingEmails = [];
$existingSleeperIds = [];
foreach ($existingStatement->fetchAll() as $participant) {
    $existingEmails[mb_strtolower((string) $participant['email'])] = true;
    $existingSleeperIds[(string) $participant['sleeper_user_id']] = true;
}

$client = new SleeperClient();
$now = date('Y-m-d H:i:s');
$imports = [];
$errors = [];
$skipped = [];
$warnings = [];
$seenEmails = [];
$seenSleeperIds = [];
$line = 1;

while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
    ++$line;
    if (count($row) === 1 && trim((string) ($row[0] ?? '')) === '') {
        continue;
    }

    $value = static fn (string $column): string => trim((string) ($row[$columns[$column]] ?? ''));
    $name = $value('name');
    $email = mb_strtolower($value('email'));
    $memberNumber = $value('member_number');
    $sleeperUsername = $value('sleeper_username');
    $admin = adminVolunteer($value('admin_volunteer'));
    $rowErrors = [];

    if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
        $rowErrors[] = 'Name fehlt oder ist ungültig';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $rowErrors[] = 'E-Mail-Adresse ist ungültig';
    }
    if ($memberNumber === '' || mb_strlen($memberNumber) > 80) {
        $rowErrors[] = 'Mitgliedsnummer fehlt oder ist zu lang';
    }
    if ($sleeperUsername === '' || mb_strlen($sleeperUsername) > 100) {
        $rowErrors[] = 'Sleeper-Name fehlt oder ist zu lang';
    }
    if ($admin === null) {
        $rowErrors[] = 'Admin-Antwort ist weder Ja noch Nein';
    }
    if ($rowErrors !== []) {
        $errors[] = "Zeile {$line}: " . implode('; ', $rowErrors);
        continue;
    }

    if (isset($existingEmails[$email])) {
        $skipped[] = "Zeile {$line}: E-Mail-Adresse ist in dieser Saison bereits vorhanden";
        continue;
    }
    if (isset($seenEmails[$email])) {
        $errors[] = "Zeile {$line}: E-Mail-Adresse kommt mehrfach in der CSV-Datei vor";
        continue;
    }

    try {
        $sleeperUser = $client->user($sleeperUsername);
    } catch (Throwable $exception) {
        $errors[] = "Zeile {$line}: Sleeper-Prüfung fehlgeschlagen: {$exception->getMessage()}";
        continue;
    }

    $sleeperUserId = (string) ($sleeperUser['user_id'] ?? '');
    if (isset($existingSleeperIds[$sleeperUserId])) {
        $skipped[] = "Zeile {$line}: Sleeper-Account ist in dieser Saison bereits vorhanden";
        continue;
    }
    if (isset($seenSleeperIds[$sleeperUserId])) {
        $errors[] = "Zeile {$line}: Sleeper-Account kommt mehrfach in der CSV-Datei vor";
        continue;
    }

    $createdAt = $now;
    if (isset($columns['timestamp'])) {
        $timestamp = trim((string) ($row[$columns['timestamp']] ?? ''));
        $createdAt = importedAt($timestamp, $now);
        if ($timestamp !== '' && $createdAt === $now && $timestamp !== $now) {
            $warnings[] = "Zeile {$line}: Zeitstempel nicht erkannt, Importzeit wird verwendet";
        }
    }

    $seenEmails[$email] = true;
    $seenSleeperIds[$sleeperUserId] = true;
    $imports[] = [
        'name' => $name,
        'email' => $email,
        'member_number' => $memberNumber,
        'sleeper_username' => (string) ($sleeperUser['username'] ?? $sleeperUsername),
        'sleeper_user_id' => $sleeperUserId,
        'sleeper_display_name' => $sleeperUser['display_name'] ?? null,
        'admin_volunteer' => $admin,
        'created_at' => $createdAt,
    ];
}
fclose($handle);

fwrite(STDOUT, "Saison: {$season['name']} (ID {$season['id']})\n");
fwrite(STDOUT, 'Geprüft: ' . (count($imports) + count($errors) + count($skipped)) . " Datensätze\n");
fwrite(STDOUT, 'Importbereit: ' . count($imports) . "\n");
fwrite(STDOUT, 'Übersprungen: ' . count($skipped) . "\n");
fwrite(STDOUT, 'Fehler: ' . count($errors) . "\n");

foreach ($warnings as $warning) {
    fwrite(STDOUT, "WARNUNG: {$warning}\n");
}
foreach ($skipped as $message) {
    fwrite(STDOUT, "ÜBERSPRUNGEN: {$message}\n");
}
foreach ($errors as $error) {
    fwrite(STDERR, "FEHLER: {$error}\n");
}

if ($dryRun) {
    fwrite(STDOUT, "Dry-Run abgeschlossen; es wurden keine Daten gespeichert.\n");
    exit($errors === [] ? 0 : 1);
}
if ($errors !== []) {
    fwrite(STDERR, "Import abgebrochen. Bitte zuerst alle Fehler beheben und erneut einen Dry-Run ausführen.\n");
    exit(1);
}
if ($imports === []) {
    fwrite(STDOUT, "Keine neuen Anmeldungen zu importieren.\n");
    exit(0);
}

$insert = $pdo->prepare(
    'INSERT INTO participants
    (season_id, name, email, member_number, sleeper_username, sleeper_user_id, sleeper_display_name, admin_volunteer, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

try {
    $pdo->beginTransaction();
    foreach ($imports as $participant) {
        $insert->execute([
            $season['id'],
            $participant['name'],
            $participant['email'],
            $participant['member_number'],
            $participant['sleeper_username'],
            $participant['sleeper_user_id'],
            $participant['sleeper_display_name'],
            $participant['admin_volunteer'],
            $participant['created_at'],
            $now,
        ]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Import fehlgeschlagen; es wurden keine Daten gespeichert: {$exception->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, count($imports) . " Anmeldungen wurden erfolgreich importiert.\n");
