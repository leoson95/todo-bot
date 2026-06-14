<?php
date_default_timezone_set('Asia/Tehran');
$botToken = "8986995462:AAHYYyD61BFTZSzlPTQF4ksmvQsnt9FePtQ";
$apiUrl = "https://api.telegram.org/bot" . $botToken;

try {
    $db = new PDO('sqlite:/app/data/todo.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    exit;
}

$now = date('Y-m-d H:i');

$stmt = $db->prepare("SELECT * FROM tasks WHERE next_trigger IS NOT NULL AND next_trigger <= ? AND is_done = 0");
$stmt->execute([$now]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($tasks as $task) {
    $chat_id = $task['chat_id'];
    $task_id = $task['id'];

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => "✅ انجام شد", 'callback_data' => "cron_done_" . $task_id],
                ['text' => "👁️ دیدم", 'callback_data' => "cron_seen_" . $task_id]
            ]
        ]
    ];

    $messageText = "⏰ <b>یادآوری</b>\n\n";
    $messageText .= "📌 <b>" . htmlspecialchars($task['text']) . "</b>\n";
    $messageText .= "📂 " . $task['category'] . "\n\n";
    $messageText .= "لطفاً وضعیت کار را مشخص کنید.";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . '/sendMessage');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'chat_id' => $chat_id,
        'text' => $messageText,
        'parse_mode' => 'HTML',
        'reply_markup' => $keyboard
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);

    if ($task['repeat_days'] > 0) {
        $nextTrigger = date('Y-m-d H:i', strtotime($task['next_trigger'] . " + " . $task['repeat_days'] . " days"));
        $db->prepare("UPDATE tasks SET next_trigger = ? WHERE id = ?")->execute([$nextTrigger, $task_id]);
    } else {
        $db->prepare("UPDATE tasks SET next_trigger = NULL WHERE id = ?")->execute([$task_id]);
    }
}