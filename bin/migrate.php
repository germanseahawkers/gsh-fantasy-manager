#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

GSH\Fantasy\Database::migrate();
fwrite(STDOUT, "Datenbank ist bereit.\n");

