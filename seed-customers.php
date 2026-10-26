<?php
require_once __DIR__ . '/config.php';

use Faker\Factory;

try {
    $db = (new Lab\Database())->connect();

    $existing = (int) $db->query('SELECT COUNT(*) FROM customers')->fetchColumn();

    if ($existing > 0) {
        exit("Customers table already contains data ($existing rows). Seeding cancelled to prevent duplicates.\n");
    }

    $faker = Factory::create();
    $faker->seed(2026);

    $stmt = $db->prepare(
        'INSERT INTO customers
        (customer_id, full_name, email, marketing_opt_in, created_at)
        VALUES (?, ?, ?, ?, ?)'
    );

    $db->beginTransaction();

    for ($i = 1; $i <= 10000; $i++) {
        $customerId = sprintf('CUST-%05d', $i);
        $email = sprintf('customer%05d@example.com', $i);
        $optIn = $i <= 8000 ? 1 : 0;

        $stmt->execute([
            $customerId,
            $faker->name(),
            $email,
            $optIn,
            gmdate('Y-m-d H:i:s'),
        ]);

        if ($i % 1000 === 0) {
            echo "Generated $i customers." . PHP_EOL;
        }
    }

    $db->commit();

    echo "Customer seeding completed successfully." . PHP_EOL;
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    exit("Customer seeding failed: " . $e->getMessage() . PHP_EOL);
}