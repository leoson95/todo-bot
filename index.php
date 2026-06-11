<?php

declare(strict_types=1);

$BOT_TOKEN = '8986995462:AAHYYyD61BFTZSzlPTQF4ksmvQsnt9FePtQ';

$ALLOWED_USERS = [8445082757];

$dbFile = __DIR__ . '/todo_bot.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE IF NOT EXISTS tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, category TEXT, text TEXT, done INTEGER DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');

// API Functions
function apiRequest($method, $payload) {
    global $BOT_TOKEN;
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/{$method}");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res ?: '{}', true) ?? [];
}

function sendMessage($chat_id, $text, $keyboard = null) {
    $p = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $p['reply_markup'] = $keyboard;
    return apiRequest('sendMessage', $p);
}

function editMessage($chat_id, $msg_id, $text, $keyboard = null) {
    $p = ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $p['reply_markup'] = $keyboard;
    apiRequest('editMessageText', $p);
}

function answerCallback($cb_id) {
    apiRequest('answerCallbackQuery', ['callback_query_id' => $cb_id]);
}

// UI

function getMainKeyboard() {
    return ['inline_keyboard' => [
        [
            ['text' => '➕ افزودن کار', 'callback_data' => 'add_task'],
            ['text' => '✅ انجام شده', 'callback_data' => 'mark_done_menu']
        ]
    ]];
}

function getCategoryKeyboard() {
    return ['inline_keyboard' => [
        [['text' => '💼 کاری', 'callback_data' => 'cat_کاری'], ['text' => '🏠 خانه', 'callback_data' => 'cat_خانه']],
        [['text' => '🛒 خرید', 'callback_data' => 'cat_خرید'], ['text' => '📚 شخصی', 'callback_data' => 'cat_شخصی']]
    ]];
}

function formatTaskList($user_id, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND done = 0 ORDER BY category, id");
    $stmt->execute([$user_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($tasks)) {
        return "📝 <b>لیست کارهای شما خالی است</b>\n\nهر متنی بفرست تا به عنوان کار جدید اضافه شود.";
    }

    $text = "📋 <b>لیست کارهای شما</b>\n";
    $current = null;
    foreach ($tasks as $t) {
        if ($t['category'] !== $current) {
            if ($current !== null) $text .= "\n";
            $current = $t['category'];
            $text .= "🗂 <b>{$current}</b>\n";
        }
        $text .= "🔲 {$t['text']}\n";
    }
    return $text;
}

function buildMarkDoneKeyboard($user_id, $pdo) {
    $stmt = $pdo->prepare("SELECT id, category, text FROM tasks WHERE user_id = ? AND done = 0 ORDER BY category, id");
    $stmt->execute([$user_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($tasks)) return null;

    $rows = [];
    foreach ($tasks as $t) {
        $label = "🔲 [{$t['category']}] " . mb_substr($t['text'], 0, 32);
        $rows[] = [['text' => $label, 'callback_data' => 'done_' . $t['id']]];
    }
    $rows[] = [['text' => '↩️ بازگشت', 'callback_data' => 'refresh']];
    return ['inline_keyboard' => $rows];
}

// ==================== MAIN ====================
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { echo 'OK'; exit; }

$chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
$user_id = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? null;

if (!$chat_id || !$user_id) { echo 'OK'; exit; }

if (!in_array((int)$user_id, $ALLOWED_USERS)) {
    sendMessage($chat_id, "⛔️ شما مجاز به استفاده از این ربات نیستید.");
    echo 'OK'; exit;
}

// Message Handler
if (isset($update['message']['text'])) {
    $text = trim($update['message']['text']);

    if ($text === '/start') {
        sendMessage($chat_id, formatTaskList($user_id, $pdo), getMainKeyboard());
        echo 'OK'; exit;
    }

    // Any text message = Add new task → Ask for category
    // We store the task text temporarily
    file_put_contents("pending_task_{$user_id}.txt", $text);
    sendMessage($chat_id, "📂 دسته را انتخاب کن:", getCategoryKeyboard());
}

// Callback Handler
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $data = $cb['data'];
    $msg_id = $cb['message']['message_id'];

    answerCallback($cb['id']);

    if (str_starts_with($data, 'cat_')) {
        $category = substr($data, 4);
        $pendingFile = "pending_task_{$user_id}.txt";

        if (file_exists($pendingFile)) {
            $taskText = file_get_contents($pendingFile);
            @unlink($pendingFile);

            // Insert into database
            $pdo->prepare("INSERT INTO tasks (user_id, category, text) VALUES (?,?,?)")->execute([$user_id, $category, $taskText]);

            // Edit the message to show updated list
            editMessage($chat_id, $msg_id, formatTaskList($user_id, $pdo), getMainKeyboard());
        } else {
            editMessage($chat_id, $msg_id, "⚠️ خطا در ذخیره کار. دوباره امتحان کن.", getMainKeyboard());
        }
    }

    if ($data === 'mark_done_menu') {
        $keyboard = buildMarkDoneKeyboard($user_id, $pdo);
        if (!$keyboard) {
            editMessage($chat_id, $msg_id, "✅ هیچ کاری باقی نمانده است!", getMainKeyboard());
        } else {
            editMessage($chat_id, $msg_id, "✅ کدام کار را انجام دادی؟", $keyboard);
        }
    }

    if (str_starts_with($data, 'done_')) {
        $task_id = (int)substr($data, 5);
        $pdo->prepare("UPDATE tasks SET done = 1 WHERE id = ? AND user_id = ?")->execute([$task_id, $user_id]);
        editMessage($chat_id, $msg_id, formatTaskList($user_id, $pdo), getMainKeyboard());
    }

    if ($data === 'refresh') {
        editMessage($chat_id, $msg_id, formatTaskList($user_id, $pdo), getMainKeyboard());
    }
}

echo 'OK';
