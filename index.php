<?php
// تنظیمات توکن ربات
$botToken = "8986995462:AAHYYyD61BFTZSzlPTQF4ksmvQsnt9FePtQ";
$apiUrl = "https://api.telegram.org/bot" . $botToken;
// ۱. اتصال به دیتابیس SQLite در مسیر هارد دائمی Railway
try {
    $db = new PDO('sqlite:/app/data/todo.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
   
    $db->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id TEXT,
        text TEXT,
        category TEXT,
        is_done INTEGER DEFAULT 0
    )");
   
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
// --- توابع کمکی ---
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
// رندر پوسته گرافیکی لوکس، عریض و با فاصله‌گذاری عالی
function renderMainList($chat_id, $db) {
    $stmt = $db->prepare("SELECT * FROM tasks WHERE chat_id = ? AND is_done = 0");
    $stmt->execute([$chat_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // تنظیم دقیق ۶ دسته‌بندی درخواستی شما همراه با ایموجی‌های اختصاصی
    $categories = [
        '🔴 فوری' => [],
        '💅 کارای سالن' => [],
        '🛒 خریدای سالن' => [],
        '🏠 کارای خونه' => [],
        '🛍️ خریدای خونه' => [],
        '👤 کارای شخصی' => []
    ];
    foreach ($tasks as $task) {
        if (array_key_exists($task['category'], $categories)) {
            $categories[$task['category']][] = $task;
        }
    }
    $textOutput = "👑 <b>بایگانی کارهای هوشمند شما</b>\n";
    $textOutput .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
    $hasTasks = false;
    foreach ($categories as $catName => $catTasks) {
        $count = count($catTasks);
        if ($count > 0) {
            $hasTasks = true;
            // استایل هدینگ کارتونی لوکس
            $textOutput .= "📂 <b>" . $catName . "</b> │ 📋 <code>" . $count . " کار</code>\n";
            $textOutput .= "─────────────────────\n";
           
            foreach ($catTasks as $t) {
                // نشانگر ظریف و مینیمال برای کارها جهت جلوگری از شلوغی
                $textOutput .= " ▫️ " . htmlspecialchars($t['text']) . "\n";
            }
            // ایجاد فضای تفکیک‌کننده برای از بین بردن چسبیدگی متون
            $textOutput .= "🔹 ━━━━━━━━━━━━ 🔹\n\n";
        }
    }
    if (!$hasTasks) {
        $textOutput .= "🕊️ <i>در حال حاضر هیچ کاری در لیست شما وجود ندارد!</i>\n\n";
        $textOutput .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
    }
    $textOutput .= "💡 <b>راهنما:</b> برای افزودن کار جدید، کافیست متن آن را بنویسید و ارسال کنید.\n";
    // دکمه مدیریت واحد
    $keyboard = [[['text' => "⚡ مدیریت و اتمام کارها", 'callback_data' => "manage_start"]]];
    return ['text' => $textOutput, 'keyboard' => $keyboard];
}
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
        if ($res && $res['ok']) $success = true;
    }
    if (!$success) {
        unset($params['message_id']);
        $res = apiRequest('sendMessage', $params);
        if ($res && $res['ok']) {
            $new_msg_id = $res['result']['message_id'];
            $db->prepare("UPDATE user_session SET main_message_id = ? WHERE chat_id = ?")->execute([$new_msg_id, $chat_id]);
        }
    }
}
// --- پردازش پیام‌ها و دستورات ---
if (!$is_callback) {
    deleteMessage($chat_id, $message_id);
    if ($text === '/start') {
        $db->prepare("UPDATE user_session SET state = NULL, temp_text = NULL WHERE chat_id = ?")->execute([$chat_id]);
        deleteMessage($chat_id, $session['temp_message_id']);
       
        $session['state'] = null; $session['temp_message_id'] = null;
        updateMainMessage($chat_id, $db, $session);
    } else {
        deleteMessage($chat_id, $session['temp_message_id']);
       
        // چیدمان ۲ ستونه دکمه‌های افزودن بر اساس ۶ دسته جدید
        $catKeyboard = [
            'inline_keyboard' => [
                [['text' => "🔴 فوری", 'callback_data' => "addcat_🔴 فوری"], ['text' => "💅 کارای سالن", 'callback_data' => "addcat_💅 کارای سالن"]],
                [['text' => "🛒 خریدای سالن", 'callback_data' => "addcat_🛒 خریدای سالن"], ['text' => "🏠 کارای خونه", 'callback_data' => "addcat_🏠 کارای خونه"]],
                [['text' => "🛍️ خریدای خونه", 'callback_data' => "addcat_🛍️ خریدای خونه"], ['text' => "👤 کارای شخصی", 'callback_data' => "addcat_👤 کارای شخصی"]],
                [['text' => "❌ انصراف از افزودن", 'callback_data' => "cancel_temp"]]
            ]
        ];
       
        $tempRes = apiRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🗂️ <b>انتخاب دسته‌بندی برای کار جدید:</b>\n« <i>" . htmlspecialchars($text) . "</i> »",
            'parse_mode' => 'HTML',
            'reply_markup' => $catKeyboard
        ]);
       
        $temp_msg_id = $tempRes['ok'] ? $tempRes['result']['message_id'] : null;
        $db->prepare("UPDATE user_session SET state = 'AWAITING_ADD_CAT', temp_text = ?, temp_message_id = ? WHERE chat_id = ?")
           ->execute([$text, $temp_msg_id, $chat_id]);
    }
} else {
    // --- پردازش دکمه‌های شیشه‌ای ---
   
    if (strpos($callback_data, 'addcat_') === 0) {
        $category = str_replace('addcat_', '', $callback_data);
        if ($session['state'] === 'AWAITING_ADD_CAT' && !empty($session['temp_text'])) {
            $db->prepare("INSERT INTO tasks (chat_id, text, category) VALUES (?, ?, ?) " )->execute([$chat_id, $session['temp_text'], $category]);
        }
       
        $db->prepare("UPDATE user_session SET state = NULL, temp_text = NULL, temp_message_id = NULL WHERE chat_id = ?")->execute([$chat_id]);
        deleteMessage($chat_id, $message_id);
       
        $session['state'] = null; $session['temp_message_id'] = null;
        updateMainMessage($chat_id, $db, $session);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => "کار اضافه شد."]);
       
    } elseif ($callback_data === 'manage_start') {
        deleteMessage($chat_id, $session['temp_message_id']);
       
        // چیدمان ۲ ستونه دکمه‌های مدیریت بر اساس ۶ دسته جدید
        $manageKeyboard = [
            'inline_keyboard' => [
                [['text' => "🔴 فوری", 'callback_data' => "mget_🔴 فوری"], ['text' => "💅 کارای سالن", 'callback_data' => "mget_💅 کارای سالن"]],
                [['text' => "🛒 خریدای سالن", 'callback_data' => "mget_🛒 خریدای سالن"], ['text' => "🏠 کارای خونه", 'callback_data' => "mget_🏠 کارای خونه"]],
                [['text' => "🛍️ خریدای خونه", 'callback_data' => "mget_🛍️ خریدای خونه"], ['text' => "👤 کارای شخصی", 'callback_data' => "mget_👤 کارای شخصی"]],
                [['text' => "❌ بستن پنل مدیریت", 'callback_data' => "cancel_temp"]]
            ]
        ];
       
        $tempRes = apiRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🛠️ <b>منوی مدیریت | ابتدا دسته‌بندی مورد نظر را انتخاب کنید:</b>",
            'parse_mode' => 'HTML',
            'reply_markup' => $manageKeyboard
        ]);
       
        $temp_msg_id = $tempRes['ok'] ? $tempRes['result']['message_id'] : null;
        $db->prepare("UPDATE user_session SET state = 'MANAGE_SELECT_CAT', temp_message_id = ? WHERE chat_id = ?")->execute([$temp_msg_id, $chat_id]);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
    } elseif (strpos($callback_data, 'mget_') === 0) {
        $category = str_replace('mget_', '', $callback_data);
       
        $stmt = $db->prepare("SELECT * FROM tasks WHERE chat_id = ? AND category = ? AND is_done = 0");
        $stmt->execute([$chat_id, $category]);
        $catTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
       
        $taskKeyboard = [];
        if (count($catTasks) > 0) {
            foreach ($catTasks as $t) {
                $taskKeyboard[] = [['text' => "🗑️ " . $t['text'], 'callback_data' => "mdone_" . $t['id']]];
            }
        } else {
            $taskKeyboard[] = [['text' => "موردی در این دسته وجود ندارد", 'callback_data' => "none"]];
        }
        $taskKeyboard[] = [['text' => "🔙 بازگشت به دسته‌ها", 'callback_data' => "manage_start"]];
       
        apiRequest('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "📌 <b>حذف کار | روی کار مورد نظر کلیک کنید تا حذف شود:</b>\n📂 دسته: <b>" . $category . "</b>",
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $taskKeyboard]
        ]);
        $db->prepare("UPDATE user_session SET state = 'MANAGE_SELECT_TASK' WHERE chat_id = ?")->execute([$chat_id]);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
    } elseif (strpos($callback_data, 'mdone_') === 0) {
        $task_id = str_replace('mdone_', '', $callback_data);
       
        $stmt = $db->prepare("SELECT category FROM tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        $taskInfo = $stmt->fetch(PDO::FETCH_ASSOC);
       
        $db->prepare("DELETE FROM tasks WHERE id = ? AND chat_id = ?")->execute([$task_id, $chat_id]);
        updateMainMessage($chat_id, $db, $session);
       
        if ($taskInfo) {
            $category = $taskInfo['category'];
            $stmt = $db->prepare("SELECT * FROM tasks WHERE chat_id = ? AND category = ? AND is_done = 0");
            $stmt->execute([$chat_id, $category]);
            $catTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
           
            $taskKeyboard = [];
            foreach ($catTasks as $t) {
                $taskKeyboard[] = [['text' => "🗑️ " . $t['text'], 'callback_data' => "mdone_" . $t['id']]];
            }
            if (count($catTasks) == 0) {
                $taskKeyboard[] = [['text' => "موردی در این دسته وجود ندارد", 'callback_data' => "none"]];
            }
            $taskKeyboard[] = [['text' => "🔙 بازگشت به دسته‌ها", 'callback_data' => "manage_start"]];
           
            apiRequest('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => "📌 <b>حذف کار | روی کار مورد نظر کلیک کنید تا حذف شود:</b>\n📂 دسته: <b>" . $category . "</b>",
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => $taskKeyboard]
            ]);
        }
       
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => "کار حذف شد."]);
    } elseif ($callback_data === 'cancel_temp') {
        $db->prepare("UPDATE user_session SET state = NULL, temp_text = NULL, temp_message_id = NULL WHERE chat_id = ?")->execute([$chat_id]);
        deleteMessage($chat_id, $message_id);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'text' => "پنل بسته شد."]);
    }
}