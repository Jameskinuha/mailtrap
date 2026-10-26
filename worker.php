<?php

require __DIR__ . '/config.php';

use Lab\Database;
use Lab\Settings;
use PHPMailer\PHPMailer\PHPMailer;

$queueUrl = Settings::required('SQS_QUEUE_URL');
$db = (new Database())->connect();
$sqs = Settings::queue();

echo "Worker started. Press Ctrl+C to stop.\n";

while (true) {
    try {
        $result = $sqs->receiveMessage([
            'QueueUrl' => $queueUrl,
            'MaxNumberOfMessages' => 1,
            'WaitTimeSeconds' => 20,
            'VisibilityTimeout' => 180,
        ]);

        foreach ($result->get('Messages') ?? [] as $message) {
            try {
                $payload = json_decode(
                    $message['Body'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                $stmt = $db->prepare("
                    SELECT
                        o.request_id,
                        o.campaign_id,
                        o.customer_id,
                        o.promo_code,
                        o.subject,
                        o.body,
                        c.email,
                        c.marketing_opt_in
                    FROM marketing_outbox o
                    INNER JOIN customers c
                        ON c.customer_id = o.customer_id
                    WHERE o.request_id = :request_id
                    LIMIT 1
                ");
                $stmt->execute([
                    'request_id' => $payload['request_id'],
                ]);
                $row = $stmt->fetch();

                if (!$row) {
                    throw new RuntimeException('Outbox request not found.');
                }

                $existing = $db->prepare("
                    SELECT status
                    FROM sent_marketing_emails
                    WHERE campaign_id = :campaign_id
                      AND customer_id = :customer_id
                    LIMIT 1
                ");
                $existing->execute([
                    'campaign_id' => $row['campaign_id'],
                    'customer_id' => $row['customer_id'],
                ]);

                $existingStatus = $existing->fetchColumn();

                if (in_array($existingStatus, ['SENT', 'SKIPPED'], true)) {
                    $sqs->deleteMessage([
                        'QueueUrl' => $queueUrl,
                        'ReceiptHandle' => $message['ReceiptHandle'],
                    ]);
                    continue;
                }

                $status = 'SKIPPED';
                $provider = 'Mailtrap';
                $messageId = null;
                $reason = null;
                $sentAt = null;

                if (!(int) $row['marketing_opt_in']) {
                    $reason = 'Customer has not opted in to marketing emails.';
                } else {
                    try {
                        $mail = new PHPMailer(true);
			$mail->isSMTP();
			$mail->SMTPDebug = 2;
			$mail->Debugoutput = 'error_log';
			$mail->Host = Settings::required('MAILTRAP_HOST');
                        $mail->SMTPAuth = true;
                        $mail->Username = Settings::required('MAILTRAP_USERNAME');
                        $mail->Password = Settings::required('MAILTRAP_PASSWORD');
                        $mail->Port = (int) Settings::required('MAILTRAP_PORT');

                        if ($mail->Port === 465) {
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                        } else {
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        }

                        $mail->CharSet = 'UTF-8';
                        $mail->setFrom(
                            Settings::required('MAIL_FROM'),
                            'Marketing Lab'
                        );
                        $mail->addAddress($row['email']);
                        $mail->Subject = $row['subject'];
                        $mail->Body = $row['body'];
                        $mail->isHTML(false);
                        $mail->send();

                        $status = 'SENT';
                        $messageId = $mail->getLastMessageID();
                        $sentAt = gmdate('Y-m-d H:i:s');
                    } catch (Throwable $e) {
                       error_log('Mail send failed: ' . $mail->ErrorInfo);
error_log('SMTP debug: ' . $mail->getSMTPInstance()->getLastTransactionID());
                        echo "Email failed; SQS will retry this message.\n";
                        continue;
                    }
                }

                $save = $db->prepare("
                    INSERT INTO sent_marketing_emails (
                        campaign_id,
                        customer_id,
                        request_id,
                        promo_code,
                        recipient_email,
                        subject,
                        status,
                        provider,
                        message_id,
                        reason,
                        processed_at,
                        sent_at
                    ) VALUES (
                        :campaign_id,
                        :customer_id,
                        :request_id,
                        :promo_code,
                        :recipient_email,
                        :subject,
                        :status,
                        :provider,
                        :message_id,
                        :reason,
                        UTC_TIMESTAMP(),
                        :sent_at
                    )
                ");

                $save->execute([
                    'campaign_id' => $row['campaign_id'],
                    'customer_id' => $row['customer_id'],
                    'request_id' => $row['request_id'],
                    'promo_code' => $row['promo_code'],
                    'recipient_email' => $row['email'],
                    'subject' => $row['subject'],
                    'status' => $status,
                    'provider' => $provider,
                    'message_id' => $messageId,
                    'reason' => $reason,
                    'sent_at' => $sentAt,
                ]);

                $sqs->deleteMessage([
                    'QueueUrl' => $queueUrl,
                    'ReceiptHandle' => $message['ReceiptHandle'],
                ]);

                echo "{$status}: {$row['customer_id']}\n";
sleep(5);
           } catch (Throwable $e) {
    error_log('Mail send failed: ' . $e->getMessage());

    if (isset($mail) && $mail instanceof PHPMailer) {
        error_log('PHPMailer error: ' . $mail->ErrorInfo);
    }

    echo "Email failed; SQS will retry this message.\n";
    sleep(5);
    continue;
}
        }
    } catch (Throwable $e) {
        error_log('Worker error: ' . $e->getMessage());
        echo "Worker error occurred; see PHP error log.\n";
        sleep(2);
    }
}