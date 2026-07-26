# GSH Fantasy Manager

[![Tests](https://github.com/germanseahawkers/gsh-fantasy-manager/actions/workflows/tests.yml/badge.svg)](https://github.com/germanseahawkers/gsh-fantasy-manager/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Eigenständige WebApp für Anmeldung, automatische Liga-Zuteilung, Drag-and-drop-Korrekturen, Zuteilungsmails und Sleeper-Abgleich. Die App benötigt kein WordPress und keine Teilnehmerkonten.

## Funktionen

- öffentliches Anmeldeformular mit Validierung des Sleeper-Benutzernamens
- gemeinsames, gehashtes Adminpasswort ohne Teilnehmerkonten
- frei konfigurierbare Ligen, Kapazitäten und Sleeper-Einladungslinks
- genau ein fest zugeordneter Liga-Admin pro Liga
- automatische gleichmäßige Verteilung nach Anmeldeschluss
- Drag-and-drop-Korrekturen vor der Freigabe
- Zuteilungsmails mit Versandprotokoll
- Abgleich des Beitrittsstatus über die offizielle Sleeper-API

## Voraussetzungen

- PHP 8.1 oder neuer
- PHP-Erweiterungen `pdo_mysql`, `curl`, `mbstring` und `openssl`
- MySQL oder MariaDB
- eine funktionierende ausgehende Mail-Konfiguration des Plesk-Servers
- HTTPS für `fantasy.germanseahawkers.com`

## Plesk-Installation

1. In Plesk die Subdomain `fantasy.germanseahawkers.com` anlegen.
2. Den Inhalt dieses Ordners auf den Server laden.
3. Den Dokumentenstamm der Subdomain auf den Unterordner `public` setzen. Dadurch bleiben `.env`, Quellcode und Kommandozeilen-Skripte von außen unerreichbar.
4. Eine MySQL-Datenbank und einen eigenen Datenbankbenutzer in Plesk anlegen.
5. `.env.example` als `.env` kopieren und Datenbank, URL, Absender und Zeitzone eintragen.
6. Einen Passwort-Hash erzeugen:

   ```sh
   php bin/password.php 'EIN-LANGES-ZUFÄLLIGES-PASSWORT'
   ```

   Die Ausgabe in `.env` als `ADMIN_PASSWORD_HASH` eintragen. Das Klartextpasswort wird nirgends gespeichert.
7. Tabellen anlegen:

   ```sh
   php bin/migrate.php
   ```

8. In Plesk einen Cronjob im Abstand von fünf Minuten einrichten:

   ```sh
   /opt/plesk/php/8.3/bin/php /VOLLSTAENDIGER/PFAD/gsh-fantasy/bin/cron.php
   ```

   Der konkrete PHP-Pfad wird in Plesk bei den PHP-Einstellungen angezeigt.

9. Für die Subdomain ein Let's-Encrypt-Zertifikat aktivieren und HTTP dauerhaft auf HTTPS umleiten.

## Anmeldungen aus Google Forms importieren

Antworten aus Google Forms können über das verknüpfte Google Sheet als CSV-Datei exportiert und anschließend serverseitig importiert werden. Unterstützt werden die Spalten `Zeitstempel`, `Name`, `E-Mail-Adresse`, `GSH Mitgliedsnummer`, die ausführliche mit `Sleeper Name` beginnende Spalte und die Frage nach der Bereitschaft als Liga-Admin.

Die CSV-Datei außerhalb des Dokumentenstamms auf den Server laden und zuerst einen Testlauf ausführen:

```sh
php bin/import-participants.php --season=latest --file=/vollstaendiger/pfad/anmeldungen.csv --dry-run
```

Der Testlauf prüft Pflichtfelder, Dubletten und jeden Sleeper-Namen über die Sleeper-API, speichert aber noch nichts. Wenn keine Fehler gemeldet werden, erfolgt der Import ohne `--dry-run`:

```sh
php bin/import-participants.php --season=latest --file=/vollstaendiger/pfad/anmeldungen.csv
```

Statt `latest` kann die konkrete Saison-ID angegeben werden. Bereits vorhandene Anmeldungen werden übersprungen. Zeilen mit Fehlern verhindern den gesamten Schreibvorgang, sodass kein unbemerkter Teilimport entsteht. Der Import ist nur möglich, solange die Saison noch offen oder geschlossen, aber noch nicht zugeteilt beziehungsweise freigegeben ist. Die CSV-Datei enthält personenbezogene Daten und sollte nach erfolgreichem Import sicher vom Server entfernt werden.

## Bedienung

- Öffentliche Anmeldung: `https://fantasy.germanseahawkers.com/`
- Administration: `https://fantasy.germanseahawkers.com/admin.php`

Zuerst wird eine Saison angelegt, danach die gewünschte Anzahl an Ligen. Jede Liga erhält Kapazität, Sleeper League-ID und den in Sleeper kopierten Einladungslink.

Teilnehmer, die im Formular ihre Admin-Bereitschaft erklären, werden im Backend hervorgehoben. Für jede Liga wird anschließend genau ein tatsächlicher Liga-Admin ausgewählt. Derselbe Teilnehmer kann technisch nicht Admin zweier Ligen sein und bleibt bei der automatischen Verteilung in seiner Liga. Vor der ersten Randomisierung können außerdem beliebige Spieler per Drag-and-drop fest einer Liga zugeordnet werden; die automatische Verteilung behält diese Zuordnungen bei und verteilt nur die noch offenen Teilnehmer.

Im ausklappbaren Teilnehmerverzeichnis kann ein fehlerhaft angegebener Sleeper-Name nachträglich korrigiert werden. Die App validiert den neuen Namen direkt bei Sleeper, übernimmt dessen feste User-ID und verhindert, dass derselbe Sleeper-Account zwei Teilnehmern zugeordnet wird. Anschließend sollte der Sleeper-Wiederherstellungs-Dry-Run erneut ausgeführt werden.

Nach dem regulären Anmeldeschluss setzt der Cronjob den internen Saisonstatus auf geschlossen und erzeugt automatisch einen gleichmäßigen Zuteilungsentwurf. Das öffentliche Formular bleibt anschließend als Nachrückverfahren erreichbar. Nach bereits erfolgter Zuteilung eingehende Anmeldungen erhalten keine automatische Platzzusage und erscheinen zunächst als nicht zugeteilt, damit sie gezielt auf freie Plätze oder als Ersatz verschoben werden können. Erst der Button **Freigeben & Mails versenden** verschickt die Einladungen. So bleibt Zeit für Drag-and-drop-Korrekturen.

## E-Mail-Zustellung

Für den produktiven Versand nutzt die App Google Workspace SMTP Relay über eine in Google freigeschaltete feste Server-IP. Die `.env` wird dafür wie folgt konfiguriert:

```dotenv
MAIL_TRANSPORT=smtp
MAIL_FROM=admin@germanseahawkers.com
MAIL_FROM_NAME=German Sea Hawkers Fantasy Football
SMTP_HOST=smtp-relay.gmail.com
SMTP_PORT=587
SMTP_ENCRYPTION=tls
SMTP_TIMEOUT=15
SMTP_USERNAME=
SMTP_PASSWORD=
```

Die leeren Zugangsdaten sind beabsichtigt: Google authentifiziert den Plesk-Server über seine zuvor in der Workspace-Admin-Konsole freigeschaltete öffentliche IP. TLS sowie die Prüfung des Google-Zertifikats sind verpflichtend. Vor dem Sammelversand muss im Administrationsbereich eine Testmail gesendet und deren SPF-, DKIM- und DMARC-Ergebnis im empfangenen Nachrichtenkopf kontrolliert werden. SMTP-Antworten und Fehler werden beim Sammelversand im Versandprotokoll gespeichert.

In Schritt 4 kann außerdem ein Reminder-Versand angestoßen werden. Die App gleicht dafür zuerst alle betroffenen Ligen live mit Sleeper ab und sendet anschließend ausschließlich an Teilnehmer, deren ursprüngliche Zuteilungsmail erfolgreich versendet wurde und für die weiterhin kein Beitritt erkannt wurde. Schlägt der Sleeper-Abgleich fehl oder fehlt eine League-ID, werden keine Erinnerungen versendet.

Eine erfolgreich versendete Zuteilungsmail wird zusammen mit der Liga gespeichert, für die der Link verschickt wurde. Der Sammelversand berücksichtigt dadurch nur neu zugeteilte Nachrücker ohne Einladung sowie Teilnehmer, deren Liga seit der letzten Einladung geändert wurde. Nach der ersten vollständigen Verteilung ändert der Verteilungsbutton seine Funktion und verteilt ausschließlich noch unzugeteilte Nachrücker auf freie Plätze; bestehende Zuordnungen bleiben erhalten. Bei der manuellen Verschiebung bereits eingeladener Teilnehmer weist die Oberfläche darauf hin, dass beim nächsten Zuteilungsversand eine neue Mail für die neue Liga verschickt wird.

`MAIL_TRANSPORT=mail` aktiviert bei Bedarf weiterhin die PHP-Mailfunktion des Plesk-Servers. Diese Einstellung sollte nur verwendet werden, wenn der lokale Mailserver einschließlich Envelope-Absender, SPF und DKIM vollständig konfiguriert ist.

## Sleeper-Grenzen

Die offizielle Sleeper-API ist lesend. Die App validiert Accounts und prüft, wer der zugewiesenen Liga beigetreten ist. Ligen, Einladungslinks sowie ein nachträgliches Entfernen oder Verschieben bereits beigetretener Nutzer müssen weiterhin in Sleeper selbst verwaltet werden.

Falls App-Zuordnungen versehentlich neu verteilt wurden, können die tatsächlichen Sleeper-Mitgliedschaften als sichere Quelle für eine Wiederherstellung verwendet werden. Zuerst muss zwingend ein Dry-Run ausgeführt werden:

```sh
php bin/reconcile-sleeper-leagues.php --season=latest --dry-run
```

Der Bericht verändert keine Daten und vergleicht ausschließlich die Besitzer tatsächlicher Sleeper-Roster; reine Commissioner- oder Co-Owner-Zugriffe gelten nicht als Ligateilnahme. Mehrfachmitgliedschaften sowie Roster-Besitzer ohne passende App-Anmeldung werden separat ausgewiesen. Nur wenn die angezeigten Korrekturen plausibel sind, werden eindeutige Mitgliedschaften übernommen:

```sh
php bin/reconcile-sleeper-leagues.php --season=latest --apply
```

Teilnehmer, die in keiner oder in mehreren eingerichteten Sleeper-Ligen gefunden werden, verändert das Werkzeug grundsätzlich nicht.

## Mitwirken

Beiträge sind willkommen. Hinweise zur lokalen Entwicklung und zu Pull Requests stehen in [CONTRIBUTING.md](CONTRIBUTING.md). Sicherheitsrelevante Meldungen bitte gemäß [SECURITY.md](SECURITY.md) vertraulich einreichen.

## Lizenz

Veröffentlicht unter der [MIT-Lizenz](LICENSE).
