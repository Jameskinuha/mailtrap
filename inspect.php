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

echo PHP_EOL . "=== SENT EMAILS ===" . PHP_EOL;

$stmt = $db->query(
    "SELECT campaign_id, customer_id, recipient_email, subject, provider, message_id, sent_at
     FROM sent_marketing_emails
     WHERE status = 'SENT'
     ORDER BY sent_at, campaign_id, customer_id"
);

$rows = $stmt->fetchAll();

if (!$rows) {
    echo "No sent email records found." . PHP_EOL;
} else {
    foreach ($rows as $row) {
        echo implode(' | ', [
            $row['campaign_id'],
            $row['customer_id'],
            $row['recipient_email'] ?? 'unknown recipient',
            $row['subject'],
            $row['provider'] ?? 'unknown provider',
            $row['message_id'] ?? 'no message ID',
            $row['sent_at'] ?? 'unknown send time',
        ]) . PHP_EOL;
    }
}