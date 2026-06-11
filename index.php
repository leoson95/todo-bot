<?php

declare(strict_types=1);

// Simple Telegram Webhook Handler

$token = getenv('TELEGRAM_BOT_TOKEN') ?: '';

if (empty($token)) {
    http_response_code(500);
    die('Bot token not configured');
}

$update = json_decode(file_get_contents('php://input'), true);

if (!$update) {
    echo 'No update received';
    exit;
}

// TODO: Process update
file_put_contents('debug.log', print_r($update, true), FILE_APPEND);

echo 'OK';