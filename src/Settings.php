<?php
namespace Lab;

use Aws\Sqs\SqsClient;
use RuntimeException;

final class Settings
{
    public static function required(string $key): string
    {
        $value = getenv($key);

        if ($value === false || trim($value) === '') {
            throw new RuntimeException("Missing required setting: $key");
        }

        return $value;
    }

    public static function queue(): SqsClient
    {
        return new SqsClient([
            'version' => 'latest',
            'region' => self::required('AWS_REGION'),
        ]);
    }
}