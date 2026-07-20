# Contributing

Vielen Dank für dein Interesse am GSH Fantasy Manager.

## Entwicklung

Vorausgesetzt werden PHP 8.1 oder neuer sowie die Erweiterungen `curl`, `mbstring` und `pdo`.

1. Repository forken oder lokal klonen.
2. `.env.example` als `.env` kopieren und eine lokale Datenbank konfigurieren.
3. `php bin/migrate.php` ausführen.
4. Änderungen in einem eigenen Branch entwickeln.
5. Vor einem Pull Request die Prüfungen ausführen:

   ```sh
   find src public bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
   php -d zend.assertions=1 -d assert.exception=1 tests/AllocatorTest.php
   ```

## Pull Requests

- Pull Requests sollten ein klar umrissenes Problem lösen.
- Neue Funktionen benötigen nach Möglichkeit Tests oder eine nachvollziehbare manuelle Prüfanleitung.
- Persönliche Daten, Zugangsdaten, `.env`-Dateien und echte Teilnehmerdaten dürfen nicht committed werden.
- Änderungen am Datenmodell müssen mit bestehenden Installationen kompatibel oder klar dokumentiert sein.

## Fehler melden

Bitte beschreibe das beobachtete Verhalten, das erwartete Verhalten, die PHP-Version und die notwendigen Schritte zur Reproduktion. Veröffentliche keine Passwörter, E-Mail-Adressen oder Mitgliedsdaten in Issues.

