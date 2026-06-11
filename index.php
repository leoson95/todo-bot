<?php

declare(strict_types=1);

// ==================== CONFIG ====================
$BOT_TOKEN  = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$BOT_PASSWORD = getenv('BOT_PASSWORD') ?: '12345';

if (empty($BOT_TOKEN)) {
    http_response_code(500);
    die('Bot token not set');
}

// ==================== DATABASE ====================
$dbFile = __DIR__ . '/todo_bot.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE IF NOT EXISTS users (
    user_id       INTEGER PRIMARY KEY,
    is_authenticated INTEGER DEFAULT 0,
    state         TEXT    DEFAULT NULL,
    state_data    TEXT    DEFAULT NULL
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS tasks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER,
    category   TEXT,
    text       TEXT,
    done       INTEGER   DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)');

// Migration: add state columns if upgrading from old schema
foreach (['state TEXT DEFAULT NULL', 'state_data TEXT DEFAULT NULL'] as $col) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN {$col}"); } catch (PDOException $e) { /* already exists */ }
}

// ==================== TELEGRAM API ====================
function apiRequest(string $method, array $payload): array
{
    global $BOT_TOKEN;
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => json_encode($payload),
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT       => 10,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result ?: '{}', true) ?? [];
}

function sendMessage(int $chat_id, string $text, array $keyboard = []): array
{
    $payload = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if (!empty($keyboard)) $payload['reply_markup'] = $keyboard;
    return apiRequest('sendMessage', $payload);
}

function editMessage(int $chat_id, int $msg_id, string $text, array $keyboard = []): void
{
    $payload = ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if (!empty($keyboard)) $payload['reply_markup'] = $keyboard;
    apiRequest('editMessageText', $payload);
}

function answerCallback(string $cb_id, string $text = ''): void
{
    apiRequest('answerCallbackQuery', ['callback_query_id' => $cb_id, 'text' => $text]);
}

// ==================== STATE (in DB, not file) ====================
function getUser(int $user_id, PDO $pdo): array
{
    // Upsert: ensure row exists
    $pdo->prepare("INSERT OR IGNORE INTO users (user_id) VALUES (?)")->execute([$user_id]);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function setState(int $user_id, ?string $state, ?string $data, PDO $pdo): void
{
    $pdo->prepare("UPDATE users SET state = ?, state_data = ? WHERE user_id = ?")
        ->execute([$state, $data, $user_id]);
}

function clearState(int $user_id, PDO $pdo): void
{
    setState($user_id, null, null, $pdo);
}

// ==================== UI HELPERS ====================
function getMainKeyboard(): array
{
    return ['inline_keyboard' => [
        [['text' => '➕ افزودن کار جدید',       'callback_data' => 'add_task']],
        [['text' => '✅ علامت زدن انجام شده',    'callback_data' => 'mark_done_menu']],
        [['text' => '🔄 به‌روزرسانی لیست',        'callback_data' => 'refresh']],
    ]];
}

function getCategoryKeyboard(): array
{
    return ['inline_keyboard' => [
        [
            ['text' => '💼 کاری',   'callback_data' => 'cat_کاری'],
            ['text' => '🏠 خانه',  'callback_data' => 'cat_خانه'],
        ],
        [
            ['text' => '🛒 خرید',  'callback_data' => 'cat_خرید'],
            ['text' => '📚 شخصی', 'callback_data' => 'cat_شخصی'],
        ],
        [['text' => '❌ انصراف', 'callback_data' => 'cancel']],
    ]];
}

function formatTaskList(int $user_id, PDO $pdo): string
{
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND done = 0 ORDER BY category, id");
    $stmt->execute([$user_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($tasks)) {
        return "📝 لیست کارهای شما خالی است.\n\nبا دکمه پایین کار جدید اضافه کن.";
    }

    $text    = "📝 <b>لیست کارهای شما</b>\n";
    $current = null;
    foreach ($tasks as $t) {
        if ($t['category'] !== $current) {
            $text   .= "\n🗂 <b>{$t['category']}</b>\n";
            $current = $t['category'];
        }
        $text .= "🔲 {$t['text']}\n";
    }
    return $text;
}

function buildMarkDoneKeyboard(int $user_id, PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT id, category, text FROM tasks WHERE user_id = ? AND done = 0 ORDER BY category, id");
    $stmt->execute([$user_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($tasks)) return [];

    $rows = [];
    foreach ($tasks as $t) {
        $label  = "[{$t['category']}] " . mb_substr($t['text'], 0, 28);
        $rows[] = [['text' => $label, 'callback_data' => "done_{$t['id']}"]];
    }
    $rows[] = [['text' => '↩️ بازگشت', 'callback_data' => 'refresh']];
    return ['inline_keyboard' => $rows];
}

// ==================== MAIN ====================
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { echo 'OK'; exit; }

$chat_id = $update['message']['chat']['id']
        ?? $update['callback_query']['message']['chat']['id']
        ?? null;
$user_id = $update['message']['from']['id']
        ?? $update['callback_query']['from']['id']
        ?? null;

if (!$chat_id || !$user_id) { echo 'OK'; exit; }

$user   = getUser((int)$user_id, $pdo);   // guarantees row exists
$isAuth = (bool)($user['is_authenticated'] ?? 0);
$state  = $user['state']      ?? null;
$sData  = $user['state_data'] ?? null;

// ==================== MESSAGE HANDLER ====================
if (isset($update['message']['text'])) {
    $text = trim($update['message']['text']);

    // /start
    if ($text === '/start') {
        if ($isAuth) {
            clearState((int)$user_id, $pdo);
            sendMessage((int)$chat_id, formatTaskList((int)$user_id, $pdo), getMainKeyboard());
        } else {
            setState((int)$user_id, 'waiting_password', null, $pdo);
            sendMessage((int)$chat_id, "🔐 لطفاً رمز عبور را وارد کن:");
        }
        echo 'OK'; exit;
    }

    // Not authenticated: only accept password
    if (!$isAuth) {
        if ($state !== 'waiting_password') {
            setState((int)$user_id, 'waiting_password', null, $pdo);
        }
        if ($text === $BOT_PASSWORD) {
            $pdo->prepare("UPDATE users SET is_authenticated = 1 WHERE user_id = ?")
                ->execute([$user_id]);
            clearState((int)$user_id, $pdo);
            sendMessage((int)$chat_id, "✅ رمز صحیح بود! خوش اومدی 👋");
            sendMessage((int)$chat_id, formatTaskList((int)$user_id, $pdo), getMainKeyboard());
        } else {
            sendMessage((int)$chat_id, "❌ رمز اشتباه است. دوباره تلاش کن:");
        }
        echo 'OK'; exit;
    }

    // Authenticated — state machine
    if ($state === 'waiting_task_text') {
        // Save text, ask for category
        setState((int)$user_id, 'waiting_task_category', $text, $pdo);
        sendMessage((int)$chat_id, "📂 دسته‌بندی را انتخاب کن:", getCategoryKeyboard());
    } else {
        // Any other message → show list
        sendMessage((int)$chat_id, formatTaskList((int)$user_id, $pdo), getMainKeyboard());
    }
}

// ==================== CALLBACK HANDLER ====================
if (isset($update['callback_query'])) {
    $cb     = $update['callback_query'];
    $data   = $cb['data'];
    $msg_id = $cb['message']['message_id'];

    answerCallback($cb['id']);

    if (!$isAuth) { echo 'OK'; exit; }

    // ── Add task (step 1) ──────────────────────────────────────
    if ($data === 'add_task') {
        setState((int)$user_id, 'waiting_task_text', null, $pdo);
        sendMessage((int)$chat_id, "✏️ متن کار جدید را بنویس:");
    }

    // ── Category chosen (step 2) ───────────────────────────────
    elseif (str_starts_with($data, 'cat_')) {
        $category = substr($data, 4);
        if ($state === 'waiting_task_category' && !empty($sData)) {
            $pdo->prepare("INSERT INTO tasks (user_id, category, text) VALUES (?,?,?)")
                ->execute([$user_id, $category, $sData]);
            clearState((int)$user_id, $pdo);
            editMessage((int)$chat_id, (int)$msg_id, formatTaskList((int)$user_id, $pdo), getMainKeyboard());
        } else {
            // State lost — recover gracefully
            clearState((int)$user_id, $pdo);
            editMessage((int)$chat_id, (int)$msg_id, "⚠️ خطا در ذخیره کار. دوباره امتحان کن.", getMainKeyboard());
        }
    }

    // ── Mark-done menu ─────────────────────────────────────────
    elseif ($data === 'mark_done_menu') {
        $keyboard = buildMarkDoneKeyboard((int)$user_id, $pdo);
        if (empty($keyboard)) {
            editMessage((int)$chat_id, (int)$msg_id, "✅ هیچ کار باقی‌مانده‌ای وجود ندارد!", getMainKeyboard());
        } else {
            editMessage((int)$chat_id, (int)$msg_id, "✅ کدام کار را انجام دادی؟", $keyboard);
        }
    }

    // ── Mark specific task done ────────────────────────────────
    elseif (str_starts_with($data, 'done_')) {
        $task_id = (int)substr($data, 5);
        // Verify ownership before marking
        $stmt = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND user_id = ?");
        $stmt->execute([$task_id, $user_id]);
        if ($stmt->fetchColumn()) {
            $pdo->prepare("UPDATE tasks SET done = 1 WHERE id = ?")->execute([$task_id]);
            answerCallback($cb['id'], '✅ انجام شد!');
        }
        editMessage((int)$chat_id, (int)$msg_id, formatTaskList((int)$user_id, $pdo), getMainKeyboard());
    }

    // ── Refresh / Cancel ───────────────────────────────────────
    elseif ($data === 'refresh' || $data === 'cancel') {
        clearState((int)$user_id, $pdo);
        editMessage((int)$chat_id, (int)$msg_id, formatTaskList((int)$user_id, $pdo), getMainKeyboard());
    }
}

echo 'OK';
