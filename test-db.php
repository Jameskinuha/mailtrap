<?php
require __DIR__ . '/config.php';

try {
    $db = (new Lab\Database())->connect();
    echo "Database connection successful." . PHP_EOL;
    echo "Database: " . $db->query("SELECT DATABASE()")->fetchColumn() . PHP_EOL;
} catch (Throwable $e) {
    echo "Database connection failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
