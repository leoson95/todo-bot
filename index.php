<?php

declare(strict_types=1);

$BOT_TOKEN  = '8986995462:AAHYYyD61BFTZSzlPTQF4ksmvQsnt9FePtQ';
$BOT_PASSWORD = '1374512';

$dbFile = __DIR__ . '/todo_bot.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE IF NOT EXISTS users (user_id INTEGER PRIMARY KEY, is_authenticated INTEGER DEFAULT 0, state TEXT DEFAULT NULL, state_data TEXT DEFAULT NULL)');
$pdo->exec('CREATE TABLE IF NOT EXISTS tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, category TEXT, text TEXT, done INTEGER DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');

foreach (['state TEXT DEFAULT NULL', 'state_data TEXT DEFAULT NULL'] as $col) { try { $pdo->exec("ALTER TABLE users ADD COLUMN {$col}"); } catch (PDOException $e) {} }

// API Functions
function apiRequest($method, $payload) {
    global $BOT_TOKEN;
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/{$method}");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res ?: '{}', true) ?? [];
}

function sendMessage($chat_id, $text, $keyboard = []) {
    $p = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $p['reply_markup'] = $keyboard;
    return apiRequest('sendMessage', $p);
}

function editMessage($chat_id, $msg_id, $text, $keyboard = []) {
    $p = ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $p['reply_markup'] = $keyboard;
    apiRequest('editMessageText', $p);
}

function answerCallback($cb_id) { apiRequest('answerCallbackQuery', ['callback_query_id' => $cb_id]); }

// State
function getUser($user_id, $pdo) {
    $pdo->prepare("INSERT OR IGNORE INTO users (user_id) VALUES (?)")->execute([$user_id]);
    return $pdo->prepare("SELECT * FROM users WHERE user_id = ?")->execute([$user_id]) ? $pdo->prepare("SELECT * FROM users WHERE user_id = ?")->execute([$user_id]) : [];
}

function setState($user_id, $state, $data, $pdo) { $pdo->prepare("UPDATE users SET state = ?, state_data = ? WHERE user_id = ?")->execute([$state, $data, $user_id]); }

function clearState($user_id, $pdo) { setState($user_id, null, null, $pdo); }

// UI


// Main
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { echo 'OK'; exit; }

$chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
$user_id = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? null;
if (!$chat_id || !$user_id) { echo 'OK'; exit; }

$user = getUser($user_id, $pdo);
$isAuth = (bool)($user['is_authenticated'] ?? 0);
$state = $user['state'] ?? null;
$sData = $user['state_data'] ?? null;

// Message Handler
if (isset($update['message']['text'])) {
    $text = trim($update['message']['text']);

    if ($text === '/start') {
        if ($isAuth) {
            clearState($user_id, $pdo);
            sendMessage($chat_id, formatTaskList($user_id, $pdo), getMainKeyboard());
        } else {
            setState($user_id, 'waiting_password', null, $pdo);
            sendMessage($chat_id, "🔐 رمز عبور را وارد کن:");
        }
        echo 'OK'; exit;
    }

    if (!$isAuth) {
        if ($text === $BOT_PASSWORD) {
            $pdo->prepare("UPDATE users SET is_authenticated = 1 WHERE user_id = ?")->execute([$user_id]);
            clearState($user_id, $pdo);
            sendMessage($chat_id, "✅ خوش آمدی!");
            sendMessage($chat_id, formatTaskList($user_id, $pdo), getMainKeyboard());
        } else {
            sendMessage($chat_id, "❌ رمز اشتباه است.");
        }
        echo 'OK'; exit;
    }

    // Any text message = Add new task
    if ($isAuth) {
        setState($user_id, 'waiting_task_category', $text, $pdo);
        sendMessage($chat_id, "📂 دسته را انتخاب کن:", getCategoryKeyboard());
    }
}

// Callback Handler
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $data = $cb['data'];
    $msg_id = $cb['message']['message_id'];
    answerCallback($cb['id']);

    if (!$isAuth) { echo 'OK'; exit; }

    if (str_starts_with($data, 'cat_')) {
        $cat = substr($data, 4);
        if ($state === 'waiting_task_category' && $sData) {
            $pdo->prepare("INSERT INTO tasks (user_id, category, text) VALUES (?,?,?)")->execute([$user_id, $cat, $sData]);
            clearState($user_id, $pdo);
            editMessage($chat_id, $msg_id, formatTaskList($user_id, $pdo), getMainKeyboard());
        }
    }

    if ($data === 'mark_done_menu') {
        // Show tasks to mark as done
        $stmt = $pdo->prepare("SELECT id, text FROM tasks WHERE user_id = ? AND done = 0");
        $stmt->execute([$user_id]);
        $tasks = $stmt->fetchAll();
        if (empty($tasks)) {
            editMessage($chat_id, $msg_id, "هیچ کاری برای انجام وجود ندارد.", getMainKeyboard());
        } else {
            $kb = [];
            foreach ($tasks as $t) {
                $kb[] = [['text' => '🔲 ' . mb_substr($t['text'], 0, 35), 'callback_data' => 'done_' . $t['id']]];
            }
            $kb[] = [['text' => '↩️ بازگشت', 'callback_data' => 'refresh']];
            editMessage($chat_id, $msg_id, "✅ کدام کار را انجام دادی؟", ['inline_keyboard' => $kb]);
        }
    }

    if (str_starts_with($data, 'done_')) {
        $tid = (int)substr($data, 5);
        $pdo->prepare("UPDATE tasks SET done = 1 WHERE id = ? AND user_id = ?")->execute([$tid, $user_id]);
        editMessage($chat_id, $msg_id, formatTaskList($user_id, $pdo), getMainKeyboard());
    }

    if ($data === 'refresh') {
        editMessage($chat_id, $msg_id, formatTaskList($user_id, $pdo), getMainKeyboard());
    }
}

echo 'OK';

function formatTaskList($user_id, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND done = 0 ORDER BY category");
    $stmt->execute([$user_id]);
    $tasks = $stmt->fetchAll();
    if (empty($tasks)) return "📝 لیست خالی است.";

    $text = "📋 <b>لیست کارهای شما</b>\n";
    $cur = null;
    foreach ($tasks as $t) {
        if ($t['category'] != $cur) {
            $text .= "\n🗂 <b>{$t['category']}</b>\n";
            $cur = $t['category'];
        }
        $text .= "🔲 {$t['text']}\n";
    }
    return $text;
}

function getMainKeyboard() {
    return ['inline_keyboard' => [
        [['text' => '➕ افزودن کار', 'callback_data' => 'add_task']],
        [['text' => '✅ انجام شده', 'callback_data' => 'mark_done_menu']],
        [['text' => '🔄 به‌روزرسانی', 'callback_data' => 'refresh']]
    ]];
}

function getCategoryKeyboard() {
    return ['inline_keyboard' => [
        [['text' => '💼 کاری', 'callback_data' => 'cat_کاری'], ['text' => '🏠 خانه', 'callback_data' => 'cat_خانه']],
        [['text' => '🛒 خرید', 'callback_data' => 'cat_خرید'], ['text' => '📚 شخصی', 'callback_data' => 'cat_شخصی']]
    ]];
}
