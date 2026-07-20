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
- PHP-Erweiterungen `pdo_mysql`, `curl` und `mbstring`
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

## Bedienung

- Öffentliche Anmeldung: `https://fantasy.germanseahawkers.com/`
- Administration: `https://fantasy.germanseahawkers.com/admin.php`

Zuerst wird eine Saison angelegt, danach die gewünschte Anzahl an Ligen. Jede Liga erhält Kapazität, Sleeper League-ID und den in Sleeper kopierten Einladungslink.

Teilnehmer, die im Formular ihre Admin-Bereitschaft erklären, werden im Backend hervorgehoben. Für jede Liga wird anschließend genau ein tatsächlicher Liga-Admin ausgewählt. Derselbe Teilnehmer kann technisch nicht Admin zweier Ligen sein und bleibt bei der automatischen Verteilung in seiner Liga.

Nach Anmeldeschluss setzt der Cronjob die Saison auf geschlossen und erzeugt automatisch einen gleichmäßigen Zuteilungsentwurf. Erst der Button **Freigeben & Mails versenden** verschickt die Einladungen. So bleibt Zeit für Drag-and-drop-Korrekturen.

## E-Mail-Zustellung

Die App übergibt Nachrichten an die PHP-Mailfunktion des Plesk-Servers und protokolliert Annahme oder Fehler. Vor dem echten Sammelversand sollten SPF, DKIM und DMARC für die Absenderdomain eingerichtet und ein Test mit wenigen Adressen durchgeführt werden. Die Annahme durch `mail()` garantiert noch keine Zustellung beim Empfänger.

## Sleeper-Grenzen

Die offizielle Sleeper-API ist lesend. Die App validiert Accounts und prüft, wer der zugewiesenen Liga beigetreten ist. Ligen, Einladungslinks sowie ein nachträgliches Entfernen oder Verschieben bereits beigetretener Nutzer müssen weiterhin in Sleeper selbst verwaltet werden.

## Mitwirken

Beiträge sind willkommen. Hinweise zur lokalen Entwicklung und zu Pull Requests stehen in [CONTRIBUTING.md](CONTRIBUTING.md). Sicherheitsrelevante Meldungen bitte gemäß [SECURITY.md](SECURITY.md) vertraulich einreichen.

## Lizenz

Veröffentlicht unter der [MIT-Lizenz](LICENSE).
