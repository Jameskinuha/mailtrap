<?php
require_once __DIR__ . '/config.php';

$db = (new Lab\Database())->connect();

echo "=== CUSTOMER COUNTS ===" . PHP_EOL;
echo "Total: " . $db->query("SELECT COUNT(*) FROM customers")->fetchColumn() . PHP_EOL;
echo "Opted in: " . $db->query("SELECT COUNT(*) FROM customers WHERE marketing_opt_in = 1")->fetchColumn() . PHP_EOL;
echo "Opted out: " . $db->query("SELECT COUNT(*) FROM customers WHERE marketing_opt_in = 0")->fetchColumn() . PHP_EOL;

echo PHP_EOL . "=== CAMPAIGN CAMP-001 ===" . PHP_EOL;

$stmt = $db->prepare(
    "SELECT status, COUNT(*) AS total
     FROM marketing_outbox
     WHERE campaign_id = ?
     GROUP BY status"
);
$stmt->execute(['CAMP-001']);

$rows = $stmt->fetchAll();

if (!$rows) {
    echo "No campaign records found." . PHP_EOL;
} else {
    foreach ($rows as $row) {
        echo $row['status'] . ": " . $row['total'] . PHP_EOL;
    }
}