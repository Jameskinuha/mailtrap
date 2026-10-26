<?php
require_once __DIR__ . '/config.php';

try {
    $db = (new Lab\Database())->connect();
    $schema = file_get_contents(__DIR__ . '/schema.sql');

    if ($schema === false) {
        throw new RuntimeException('Could not read schema.sql');
    }

    $db->exec($schema);

    echo "Database tables created successfully." . PHP_EOL;
    echo "Tables: customers, marketing_outbox, sent_marketing_emails" . PHP_EOL;
} catch (Throwable $e) {
    echo "Database initialization failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}