<?php

declare(strict_types=1);

// ==================== CONFIG ====================
$BOT_TOKEN = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$BOT_PASSWORD = getenv('BOT_PASSWORD') ?: '12345';

if (empty($BOT_TOKEN)) {
    http_response_code(500);
    die('Bot token not set');
}

// ==================== DATABASE ====================
$dbFile = __DIR__ . '/todo_bot.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE IF NOT EXISTS users (user_id INTEGER PRIMARY KEY, is_authenticated INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    category TEXT,
    text TEXT,
    done INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)');

// ==================== FUNCTIONS ====================

function sendMessage($chat_id, $text, $reply_markup = null) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    @file_get_contents($url . '?' . http_build_query($data));
}

function editMessage($chat_id, $message_id, $text, $reply_markup = null) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/editMessageText";
    $data = ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    @file_get_contents($url . '?' . http_build_query($data));
}

function answerCallback($callback_id) {
    global $BOT_TOKEN;
    @file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/answerCallbackQuery?callback_query_id={$callback_id}");
}

function getMainKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '➕ افزودن کار جدید', 'callback_data' => 'add_task']],
            [['text' => '✅ علامت زدن انجام شده', 'callback_data' => 'mark_done_menu']],
            [['text' => '🔄 به‌روزرسانی لیست', 'callback_data' => 'refresh']]
        ]
    ];
}

function formatTaskList($user_id, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND done = 0 ORDER BY category");
    $stmt->execute([$user_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($tasks)) return "📝 لیست کارهای شما خالی است.\n\nبا دکمه پایین کار جدید اضافه کن.";

    $text = "📝 لیست کارهای شما\n\n";
    $current = null;
    foreach ($tasks as $t) {
        if ($t['category'] !== $current) {
            if ($current) $text .= "\n";
            $current = $t['category'];
            $text .= "🗂 {$current}\n";
        }
        $text .= "🔲 {$t['text']}\n";
    }
    return $text;
}

// ==================== PROCESS ====================
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { echo 'OK'; exit; }

$chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
$user_id = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? null;

if (!$chat_id || !$user_id) { echo 'OK'; exit; }

// Check auth
$stmt = $pdo->prepare("SELECT is_authenticated FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$isAuth = (bool)$stmt->fetchColumn();

// Message
if (isset($update['message']['text'])) {
    $text = trim($update['message']['text']);

    if ($text === '/start') {
        if ($isAuth) {
            sendMessage($chat_id, formatTaskList($user_id, $pdo), getMainKeyboard());
        } else {
            sendMessage($chat_id, "🔐 لطفاً رمز عبور را وارد کن:");
            // For simplicity, we use a file-based temp storage for password waiting
            file_put_contents("temp_{$user_id}.txt", 'waiting_password');
        }
    } elseif (file_exists("temp_{$user_id}.txt") && trim(file_get_contents("temp_{$user_id}.txt")) === 'waiting_password') {
        if ($text === $BOT_PASSWORD) {
            $pdo->prepare("INSERT OR REPLACE INTO users (user_id, is_authenticated) VALUES (?,1)")->execute([$user_id]);
            @unlink("temp_{$user_id}.txt");
            sendMessage($chat_id, "✅ رمز صحیح بود!");
            sendMessage($chat_id, formatTaskList($user_id, $pdo), getMainKeyboard());
        } else {
            sendMessage($chat_id, "❌ رمز اشتباه است.");
        }
    }
}

// Callback
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $data = $cb['data'];
    $msg_id = $cb['message']['message_id'];
    $chat_id = $cb['message']['chat']['id'];
    answerCallback($cb['id']);

    if ($data === 'add_task') {
        sendMessage($chat_id, "لطفاً متن کار را بنویس:");
        file_put_contents("temp_{$user_id}.txt", 'waiting_task_text');
    }

    if (str_starts_with($data, 'category_')) {
        $cat = str_replace('category_', '', $data);
        $taskText = @file_get_contents("temp_{$user_id}.txt");
        if (str_starts_with($taskText, 'task:')) {
            $taskText = substr($taskText, 5);
            $pdo->prepare("INSERT INTO tasks (user_id, category, text) VALUES (?,?,?)")->execute([$user_id, $cat, $taskText]);
            @unlink("temp_{$user_id}.txt");
            editMessage($chat_id, $msg_id, formatTaskList($user_id, $pdo), getMainKeyboard());
        }
    }

    if ($data === 'refresh') {
        editMessage($chat_id, $msg_id, formatTaskList($user_id, $pdo), getMainKeyboard());
    }
}

echo 'OK';