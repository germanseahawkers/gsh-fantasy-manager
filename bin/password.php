#!/usr/bin/env php
<?php

declare(strict_types=1);

if (!isset($argv[1]) || $argv[1] === '') {
    fwrite(STDERR, "Aufruf: php bin/password.php 'sicheres-passwort'\n");
    exit(1);
}

fwrite(STDOUT, password_hash($argv[1], PASSWORD_DEFAULT) . "\n");
