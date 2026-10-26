<?php

require __DIR__ . '/config.php';

use Lab\Database;
use Lab\Settings;

use Aws\Exception\AwsException;

$campaignId = 'CAMP-001';
$queueUrl = Settings::required('SQS_QUEUE_URL');

$db = (new Database())->connect();
$sqs = Settings::queue();

$sql = "
    SELECT
        o.request_id,
        o.campaign_id,
        o.customer_id,
        o.promo_code,
        o.subject,
        o.body
    FROM marketing_outbox o
    INNER JOIN customers c
        ON c.customer_id = o.customer_id
    WHERE o.campaign_id = :campaign_id
      AND o.status = 'PENDING'
      AND c.marketing_opt_in = 1
    ORDER BY o.created_at
";

$stmt = $db->prepare($sql);
$stmt->execute(['campaign_id' => $campaignId]);
$requests = $stmt->fetchAll();

if (!$requests) {
    echo "No eligible pending requests found.\n";
    exit;
}

$queued = 0;

foreach ($requests as $request) {
    try {
        $result = $sqs->sendMessage([
            'QueueUrl' => $queueUrl,
            'MessageBody' => json_encode([
                'request_id' => $request['request_id'],
                'campaign_id' => $request['campaign_id'],
                'customer_id' => $request['customer_id'],
                'promo_code' => $request['promo_code'],
                'subject' => $request['subject'],
                'body' => $request['body'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $update = $db->prepare("
            UPDATE marketing_outbox
            SET status = 'QUEUED',
                queued_at = UTC_TIMESTAMP(),
                sqs_message_id = :message_id
            WHERE request_id = :request_id
              AND status = 'PENDING'
        ");

        $update->execute([
            'message_id' => $result['MessageId'],
            'request_id' => $request['request_id'],
        ]);

        if ($update->rowCount() === 1) {
            $queued++;
            echo "Queued request {$request['request_id']}\n";
        }
    } catch (Throwable $e) {
        error_log(
            "Failed to queue request {$request['request_id']}: " .
            $e->getMessage()
        );
        echo "Failed request {$request['request_id']}; check logs.\n";
    }
}

echo "Publisher finished. Queued: {$queued}\n";