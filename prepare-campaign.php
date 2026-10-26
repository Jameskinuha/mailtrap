<?php
require_once __DIR__ . '/config.php';

try {
    $db = (new Lab\Database())->connect();
    $campaignId = $argv[1] ?? 'CAMP-001';
    $promoCode = $argv[2] ?? 'PROMO15';

    $check = $db->prepare(
        'SELECT COUNT(*) FROM marketing_outbox WHERE campaign_id = ?'
    );
    $check->execute([$campaignId]);
    $existing = (int) $check->fetchColumn();

    if ($existing === 50) {
        exit("Campaign $campaignId already has 50 recipients. Existing selection preserved.\n");
    }

    if ($existing > 0) {
        exit("Campaign $campaignId has $existing existing recipients. Stopping to prevent an incomplete or changed selection.\n");
    }

    $db->beginTransaction();

    $customers = $db->query(
        "SELECT customer_id, full_name, email
         FROM customers
         WHERE marketing_opt_in = 1
         ORDER BY RAND()
         LIMIT 50"
    )->fetchAll();

    if (count($customers) !== 50) {
        throw new RuntimeException('Could not find 50 opted-in customers. Run the customer seeder first.');
    }

    $insert = $db->prepare(
        "INSERT INTO marketing_outbox
        (request_id, campaign_id, customer_id, promo_code, subject, body, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?)"
    );

    $createdAt = gmdate('Y-m-d H:i:s');

    foreach ($customers as $customer) {
        $requestId = bin2hex(random_bytes(16));
        $subject = "Your exclusive $promoCode offer";
        $body = "Hello {$customer['full_name']},\n\n"
              . "Thank you for being a valued customer. Use promo code $promoCode "
              . "for your exclusive promotional offer.\n\n"
              . "This is a test marketing email for the laboratory.";

        $insert->execute([
            $requestId,
            $campaignId,
            $customer['customer_id'],
            $promoCode,
            $subject,
            $body,
            $createdAt,
        ]);
    }

    $db->commit();

    echo "Campaign prepared successfully." . PHP_EOL;
    echo "Campaign ID: $campaignId" . PHP_EOL;
    echo "Promo code: $promoCode" . PHP_EOL;
    echo "Recipients selected: 50" . PHP_EOL;
    echo "Status: PENDING" . PHP_EOL;
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    exit("Campaign preparation failed: " . $e->getMessage() . PHP_EOL);
}