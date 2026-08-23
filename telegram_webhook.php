<?php
require 'config.php';
$token = "YOUR_BOT_TOKEN";
$update = json_decode(file_get_contents('php://input'), true);
$chat_id = $update['message']['chat']['id'];
$text = $update['message']['text'];

// Format: /accept_123  where 123 = open_shift_id
if(strpos($text, '/accept_') === 0){
    $os_id = intval(str_replace('/accept_', '', $text));
    
    // Find staff_id by telegram_chat_id
    $staff = $conn->prepare("SELECT id FROM users WHERE telegram_chat_id=?");
    $staff->execute([$chat_id]);
    $staff = $staff->fetch();
    if(!$staff) exit();

    $user_id = $staff['id'];
    $os = $conn->prepare("SELECT * FROM open_shifts WHERE id=? AND slots_taken < slots_total");
    $os->execute([$os_id]);
    $shift = $os->fetch();
    if(!$shift) { sendMsg($chat_id, "Мест нет"); exit(); }

    // 1. Apply
    $conn->prepare("INSERT IGNORE INTO open_shift_applications (open_shift_id, staff_id) VALUES (?,?)")->execute([$os_id, $user_id]);
    $conn->prepare("UPDATE open_shifts SET slots_taken = slots_taken + 1 WHERE id=?")->execute([$os_id]);

    // 2. Create real shift immediately so it shows in week.php + my_schedule.php
    $conn->prepare("INSERT INTO shifts (business_id, employee_id, shift_date, start_time, end_time) VALUES (?,?,?,?,?)")
    ->execute([$shift['business_id'], $user_id, $shift['shift_date'], $shift['start_time'], $shift['end_time']]);

    // 3. Close if full
    if($shift['slots_taken']+1 >= $shift['slots_total']){
        $conn->prepare("UPDATE open_shifts SET status='closed' WHERE id=?")->execute([$os_id]);
    }
    
    sendMsg($chat_id, "✅ Вы заняли смену: {$shift['shift_date']} {$shift['start_time']}-{$shift['end_time']}");
}

function sendMsg($chat_id, $text){
    global $token;
    file_get_contents("https://api.telegram.org/bot$token/sendMessage?chat_id=$chat_id&text=".urlencode($text));
}
?>