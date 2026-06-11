<?php

// تنظیمات توکن ربات
$botToken = "8986995462:AAHYYyD61BFTZSzlPTQF4ksmvQsnt9FePtQ"; 
$apiUrl = "https://api.telegram.org/bot" . $botToken;

// ۱. اتصال به دیتابیس SQLite در مسیر هارد دائمی Railway
try {
    $db = new PDO('sqlite:/app/data/todo.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ساخت جدول کارها
    $db->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id TEXT,
        text TEXT,
        category TEXT,
        is_done INTEGER DEFAULT 0
    )");
    
    // ساخت جدول مدیریت سشن‌ها و پیام اصلی
    $db->exec("CREATE TABLE IF NOT EXISTS user_session (
        chat_id TEXT PRIMARY KEY,
        main_message_id INTEGER,
        temp_message_id INTEGER,
        state TEXT,
        temp_text TEXT
    )");
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    exit;
}

// ۲. دریافت اطلاعات از وب‌هوک تلگرام
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) exit;

$chat_id = null;
$message_id = null;
$is_callback = false;

if (isset($update['message'])) {
    $chat_id = $update['message']['chat']['id'];
    $message_id = $update['message']['message_id'];
    $text = trim($update['message']['text']);
} elseif (isset($update['callback_query'])) {
    $is_callback = true;
    $chat_id = $update['callback_query']['message']['chat']['id'];
    $message_id = $update['callback_query']['message']['message_id'];
    $callback_data = $update['callback_query']['data'];
    $callback_query_id = $update['callback_query']['id'];
}

if (!$chat_id) exit;

// دریافت یا ایجاد سشن کاربر
$stmt = $db->prepare("SELECT * FROM user_session WHERE chat_id = ?");
$stmt->execute([$chat_id]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    $db->prepare("INSERT INTO user_session (chat_id, main_message_id, temp_message_id, state, temp_text) VALUES (?, NULL, NULL, NULL, NULL)")->execute([$chat_id]);
    $session = ['chat_id' => $chat_id, 'main_message_id' => null, 'temp_message_id' => null, 'state' => null, 'temp_text' => null];
}

// --- توابع کمکی ربات ---

function apiRequest($method, $parameters) {
    global $apiUrl;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . '/' . $method);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function deleteMessage($chat_id, $msg_id) {
    if ($msg_id) apiRequest('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
}

// رندر کردن پوسته گرافیکی و لوکس لیست کارها
function renderMainList($chat_id, $db) {
    $stmt = $db->prepare("SELECT * FROM tasks WHERE chat_id = ? AND is_done = 0");
    $stmt->execute([$chat_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // دسته‌بندی‌ها
    $categories = [
        '🔴 فوری' => [],
        '💻 کاری' => [],
        '🏠 شخصی' => [],
        '🛍️ خرید' => [],
        '💅 زیبایی' => []
    ];

    foreach ($tasks as $task) {
        if (array_key_exists($task['category'], $categories)) {
            $categories[$task['category']][] = $task;
        }
    }

    $textOutput = "👑 <b>لیست کارهای هوشمند شما</b>\n";
    $textOutput .= "───────────────────\n\n";

    $hasTasks = false;
    $keyboardButtons = [];

    foreach ($categories as $catName => $catTasks) {
        $count = count($catTasks);
        if ($count > 0) {
            $hasTasks = true;
            $textOutput .= "📂 <b>" . $catName . "</b> (" . $count . " کار)\n";
            foreach ($catTasks as $index => $t) {
                $isLast = ($index === $count - 1);
                $prefix = $isLast ? "└─ " : "├─ ";
                $textOutput .= $prefix . "📝 " . htmlspecialchars($t['text']) . "\n";
                
                // ایجاد دکمه شیشه‌ای برای حذف/اتمام هر کار
                $keyboardButtons[] = [['text' => "✅ اتمام: " . $t['text'], 'callback_data' => "done_" . $t['id']]];
            }
            $textOutput .= "\n";
        }
    }

    if (!$hasTasks) {
        $textOutput .= "🕊️ <i>لیست کارهای شما خالی است!</i>\n\n";
    }

    $textOutput .= "───────────────────\n";
    $textOutput .= "💡 <b>راهنما:</b> برای اضافه کردن کار جدید، کافیست متن آن را در چت بنویسید و ارسال کنید.\n";

    return ['text' => $textOutput, 'keyboard' => $keyboardButtons];
}

// مدیریت نمایش یا ویرایش پیام اصلی لیست
function updateMainMessage($chat_id, $db, $session) {
    $listData = renderMainList($chat_id, $db);
    $params = [
        'chat_id' => $chat_id,
        'text' => $listData['text'],
        'parse_mode' => 'HTML',
        'reply_markup' => ['inline_keyboard' => $listData['keyboard']]
    ];

    $success = false;
    if ($session['main_message_id']) {
        $params['message_id'] = $session['main_message_id'];
        $res = apiRequest('editMessageText', $params);
        if ($res && $res['ok']) {
            $success = true;
        }
    }

    // اگر پیام اصلی وجود نداشت یا توسط کاربر پاک شده بود، پیام جدید می‌فرستیم
    if (!$success) {
        unset($params['message_id']);
        $res = apiRequest('sendMessage', $params);
        if ($res && $res['ok']) {
            $new_msg_id = $res['result']['message_id'];
            $db->prepare("UPDATE user_session SET main_message_id = ? WHERE chat_id = ?")->execute([$new_msg_id, $chat_id]);
        }
    }
}

// --- پردازش درخواست‌ها ---

if (!$is_callback) {
    // حذف پیام ارسالی کاربر برای تمیز ماندن چت
    deleteMessage($chat_id, $message_id);

    if ($text === '/start') {
        // بازنشانی سشن و نمایش لیست اصلی
        $db->prepare("UPDATE user_session SET state = NULL, temp_text = NULL WHERE chat_id = ?")->execute([$chat_id]);
        // حذف پیام موقت قبلی در صورت وجود
        deleteMessage($chat_id, $session['temp_message_id']);
        
        $session['state'] = null;
        $session['temp_message_id'] = null;
        updateMainMessage($chat_id, $db, $session);
    } else {
        // کاربر متنی فرستاده که به عنوان کار جدید در نظر گرفته می‌شود
        deleteMessage($chat_id, $session['temp_message_id']); // حذف پیام موقت قبلی
        
        // نمایش منوی انتخاب دسته‌بندی (پیام موقت)
        $catKeyboard = [
            'inline_keyboard' => [
                [['text' => "🔴 فوری", 'callback_data' => "cat_🔴 فوری"], ['text' => "💻 کاری", 'callback_data' => "cat_💻 کاری"]],
                [['text' => "🏠 شخصی", 'callback_data' => "cat_🏠 شخصی"], ['text' => "🛍️ خرید", 'callback_data' => "cat_🛍️ خرید"]],
                [['text' => "💅 زیبایی", 'callback_data' => "cat_💅 زیبایی"]],
                [['text' => "❌ انصراف", 'callback_data' => "cancel"]]
            ]
        ];
        
        $tempRes = apiRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🗂 <b>انتخاب دسته‌بندی برای کار جدید:</b>\n« " . htmlspecialchars($text) . " »",
            'parse_mode' => 'HTML',
            'reply_markup' => $catKeyboard
        ]);
        
        $temp_msg_id = $tempRes['ok'] ? $tempRes['result']['message_id'] : null;
        
        $db->prepare("UPDATE user_session SET state = 'AWAITING_CAT', temp_text = ?, temp_message_id = ? WHERE chat_id = ?")
           ->execute([$text, $temp_msg_id, $chat_id]);
    }
} else {
    // پردازش دکمه‌های شیشه‌ای (Callback Queries)
    
    if (strpos($callback_data, 'cat_') === 0) {
        $category = str_replace('cat_', '', $callback_data);
        
        if ($session['state'] === 'AWAITING_CAT' && !empty($session['temp_text'])) {
            // ذخیره کار در دیتابیس
            $stmt = $db->prepare("INSERT INTO tasks (chat_id, text, category) VALUES (?, ?, ?)");
            $stmt->execute([$chat_id, $session['temp_text'], $category]);
        }
        
        // پاکسازی سشن و حذف پیام موقت انتخاب دسته
        $db->prepare("UPDATE user_session SET state = NULL, temp_text = NULL, temp_message_id = NULL WHERE chat_id = ?")->execute([$chat_id]);
        deleteMessage($chat_id, $message_id);
        
        // به‌روزرسانی پیام اصلی لیست
        $session['state'] = null;
        $session['temp_message_id'] = null;
        updateMainMessage($chat_id, $db, $session);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => "کار با موفقیت اضافه شد."]);
        
    } elseif (strpos($callback_data, 'done_') === 0) {
        $task_id = str_replace('done_', '', $callback_data);
        
        // حذف یا اتمام کار از دیتابیس
        $stmt = $db->prepare("DELETE FROM tasks WHERE id = ? AND chat_id = ?");
        $stmt->execute([$task_id, $chat_id]);
        
        // به‌روزرسانی پیام اصلی لیست
        updateMainMessage($chat_id, $db, $session);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => "کار انجام شد و حذف گردید."]);
        
    } elseif ($callback_data === 'cancel') {
        // انصراف از افزودن کار
        $db->prepare("UPDATE user_session SET state = NULL, temp_text = NULL, temp_message_id = NULL WHERE chat_id = ?")->execute([$chat_id]);
        deleteMessage($chat_id, $message_id);
        
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => "عملیات لغو شد."]);
    }
}
