<?php

declare(strict_types=1);

// G6 WP3c fixed helper: proves the runner reaches the rehearsal database as the application account. Reads only the environment the
// wrapper sets, takes no arguments, and runs one fixed query.

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
    (string) getenv('DB_USERNAME'),
    (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$row = $pdo->query('SELECT CURRENT_USER(), DATABASE()')->fetch(PDO::FETCH_NUM);
printf("ok user=%s database=%s\n", $row[0], $row[1]);
