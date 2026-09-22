<?php
require 'config.php';
$token = defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '8751888801:AAFmNfdS3Fpe6lIqAmHgfMb4aIOh6iikNhs'; 
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit();

// DEBUG LOG - delete later
file_put_contents('tg_log.txt', date('H:i:s')." ".json_encode($update)."\n", FILE_APPEND);

// Handle both message and callback_query (for inline buttons)
$chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
$text = $update['message']['text'] ?? $update['callback_query']['data'] ?? '';
$callback_id = $update['callback_query']['id'] ?? null;
if (!$chat_id) exit();

// ============ HELPER FUNCTIONS ============
function sendMsg($chat_id, $text, $keyboard = null){
    global $token;
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if($keyboard){
        $data['reply_markup'] = json_encode($keyboard);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    file_put_contents('tg_log.txt', "SEND to $chat_id: $res\n", FILE_APPEND);
    curl_close($ch);
}

function answerCallback($callback_id, $text){
    global $token;
    $url = "https://api.telegram.org/bot$token/answerCallbackQuery";
    $data = ['callback_query_id' => $callback_id, 'text' => $text, 'show_alert' => true];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// ============ 1. /start + LINK ============
if($text == '/start'){
    sendMsg($chat_id, "👋 Welcome to Tukur_ShiftPro_bot!\n\nSend your registered email to link your account.");
    exit();
}

if(filter_var($text, FILTER_VALIDATE_EMAIL)){
    try{
        $stmt = $conn->prepare("UPDATE users SET telegram_chat_id=? WHERE email=? AND telegram_chat_id IS NULL");
        $stmt->execute([$chat_id, $text]);
        // Also update if they already linked but changed device - FIX: update both columns
        if($stmt->rowCount() == 0){
            $check = $conn->prepare("SELECT id FROM users WHERE email=?");
            $check->execute([$text]);
            if($check->fetch()){
                // FIXED: update both telegram_chat_id and telegram_id for compatibility
                $conn->prepare("UPDATE users SET telegram_chat_id=?, telegram_id=? WHERE email=?")->execute([$chat_id, $chat_id, $text]);
                sendMsg($chat_id, "✅ Re-linked! You will now get shift alerts from Tukur_ShiftPro_bot.");
                exit();
            }
        }
        if($stmt->rowCount() > 0){
            // FIXED: also set telegram_id
            $conn->prepare("UPDATE users SET telegram_id=? WHERE email=?")->execute([$chat_id, $text]);
            sendMsg($chat_id, "✅ Linked! You will now get shift opening alerts from Tukur_ShiftPro_bot.");
        } else {
            sendMsg($chat_id, "❌ Email not found. Register on website first.");
        }
    }catch(Exception $e){
        file_put_contents('tg_log.txt', "LINK ERROR: ".$e->getMessage()."\n", FILE_APPEND);
        sendMsg($chat_id, "DB Error: ".$e->getMessage());
    }
    exit();
}

// ============ 2. ACCEPT LOGIC WITH TRANSACTION ============
if(strpos($text, 'accept_') === 0){
    $os_id = intval(str_replace('accept_', '', $text));
    
    $conn->beginTransaction();
    try {
        // Get user - FIXED: check both columns
        $staff = $conn->prepare("SELECT id, business_id FROM users WHERE telegram_chat_id=? OR telegram_id=? FOR UPDATE");
        $staff->execute([$chat_id, $chat_id]);
        $user = $staff->fetch();
        if(!$user) throw new Exception("Please /start and link email first");

        // Lock open_shift row
        $os = $conn->prepare("SELECT * FROM open_shifts WHERE id=? AND status='open' FOR UPDATE");
        $os->execute([$os_id]);
        $shift = $os->fetch();
        if(!$shift) throw new Exception("❌ No more vacancies - all taken!");
        if($shift['slots_taken'] >= $shift['slots_total']) throw new Exception("❌ Sorry, filled! Slots: {$shift['slots_taken']}/{$shift['slots_total']}");

        // Check if user already took it
        $chk = $conn->prepare("SELECT id FROM open_shift_applications WHERE open_shift_id=? AND staff_id=?");
        $chk->execute([$os_id, $user['id']]);
        if($chk->fetch()) throw new Exception("You already took this shift");

        // Check if user is free that day (no overlap)
        $busy = $conn->prepare("SELECT id FROM shifts WHERE employee_id=? AND shift_date=? AND ((start_time < ? AND end_time > ?) OR (start_time < ? AND end_time > ?))");
        $busy->execute([$user['id'], $shift['shift_date'], $shift['end_time'], $shift['start_time'], $shift['end_time'], $shift['start_time']]);
        if($busy->fetch()) throw new Exception("❌ You already have a shift at that time");

        // Apply
        $conn->prepare("INSERT INTO open_shift_applications (open_shift_id, staff_id) VALUES (?,?)")->execute([$os_id, $user['id']]);
        $conn->prepare("UPDATE open_shifts SET slots_taken = slots_taken + 1 WHERE id=?")->execute([$os_id]);
        $conn->prepare("INSERT INTO shifts (business_id, employee_id, shift_date, start_time, end_time) VALUES (?,?,?,?,?)")
            ->execute([$shift['business_id'], $user['id'], $shift['shift_date'], $shift['start_time'], $shift['end_time']]);

        // Check if now full
        $is_full = ($shift['slots_taken'] + 1 >= $shift['slots_total']);
        if($is_full){
            $conn->prepare("UPDATE open_shifts SET status='closed' WHERE id=?")->execute([$os_id]);
        }
        $conn->commit();

        // Reply to accepter
        if($callback_id) answerCallback($callback_id, "✅ Shift taken!");
        $keyboard = ['inline_keyboard' => [[['text' => '❌ Cancel My Shift', 'callback_data' => "cancel_{$os_id}"]]]];
        $pay = $shift['pay'] ?? $shift['bonus'] ?? 0;
        sendMsg($chat_id, "✅ You took the shift: <b>{$shift['shift_date']} {$shift['start_time']}-{$shift['end_time']}</b>\nBonus: $pay ₽", $keyboard);

        // If FULL - notify everyone else
        if($is_full){
            broadcastClosed($conn, $os_id);
        }

    } catch(Exception $e){
        $conn->rollBack();
        file_put_contents('tg_log.txt', "ACCEPT ERROR: ".$e->getMessage()."\n", FILE_APPEND);
        if($callback_id) answerCallback($callback_id, $e->getMessage());
        else sendMsg($chat_id, $e->getMessage());
    }
    exit();
}

// ============ 3. CANCEL LOGIC ============
if(strpos($text, 'cancel_') === 0){
    $os_id = intval(str_replace('cancel_', '', $text));
    $conn->beginTransaction();
    try{
        $staff = $conn->prepare("SELECT id FROM users WHERE telegram_chat_id=? OR telegram_id=?");
        $staff->execute([$chat_id, $chat_id]);
        $user = $staff->fetch();
        if(!$user) throw new Exception("Not linked");

        $os = $conn->prepare("SELECT * FROM open_shifts WHERE id=? FOR UPDATE");
        $os->execute([$os_id]);
        $shift = $os->fetch();
        if(!$shift) throw new Exception("Shift not found");

        // Remove application
        $del = $conn->prepare("DELETE FROM open_shift_applications WHERE open_shift_id=? AND staff_id=?");
        $del->execute([$os_id, $user['id']]);
        if($del->rowCount()==0) throw new Exception("You didn't take this shift");

        $conn->prepare("DELETE FROM shifts WHERE business_id=? AND employee_id=? AND shift_date=? AND start_time=? AND end_time=? LIMIT 1")
            ->execute([$shift['business_id'], $user['id'], $shift['shift_date'], $shift['start_time'], $shift['end_time']]);
        $conn->prepare("UPDATE open_shifts SET slots_taken = GREATEST(0, slots_taken - 1), status='open' WHERE id=?")->execute([$os_id]);
        $conn->commit();

        if($callback_id) answerCallback($callback_id, "Cancelled");
        sendMsg($chat_id, "✅ Cancelled: {$shift['shift_date']} {$shift['start_time']}-{$shift['end_time']}. Slot freed.");

        // Re-broadcast that 1 slot is open again
        broadcastReopen($conn, $os_id);

    } catch(Exception $e){
        $conn->rollBack();
        file_put_contents('tg_log.txt', "CANCEL ERROR: ".$e->getMessage()."\n", FILE_APPEND);
        if($callback_id) answerCallback($callback_id, $e->getMessage());
        else sendMsg($chat_id, $e->getMessage());
    }
    exit();
}

// ============ BROADCAST HELPERS ============
function getFreeEmployees($conn, $shift){
    // FIXED: check both telegram columns
    $sql = "SELECT u.telegram_chat_id FROM users u 
            WHERE u.business_id=? AND u.role='employee' AND (u.telegram_chat_id IS NOT NULL OR u.telegram_id IS NOT NULL)
            AND NOT EXISTS (
                SELECT 1 FROM shifts s WHERE s.employee_id=u.id AND s.shift_date=? 
                AND ((s.start_time < ? AND s.end_time > ?) OR (s.start_time < ? AND s.end_time > ?))
            )";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$shift['business_id'], $shift['shift_date'], $shift['end_time'], $shift['start_time'], $shift['end_time'], $shift['start_time']]);
    return array_filter($stmt->fetchAll(PDO::FETCH_COLUMN));
}

function broadcastShift($conn, $os_id){
    $os = $conn->prepare("SELECT os.*, b.name as business_name FROM open_shifts os JOIN businesses b ON b.id=os.business_id WHERE os.id=?");
    $os->execute([$os_id]);
    $shift = $os->fetch();
    if(!$shift) return;

    $free_ids = getFreeEmployees($conn, $shift);
    if(empty($free_ids)) return;

    $remaining = $shift['slots_total'] - $shift['slots_taken'];
    $pay = $shift['pay'] ?? $shift['bonus'] ?? 0;
    $msg = "🚨 <b>NEW SHIFT OPEN - Tukur_ShiftPro_bot</b> 🚨\n\n";
    $msg .= "<b>".($shift['business_name'] ?? 'Shift')."</b>\n📅 {$shift['shift_date']}\n⏰ {$shift['start_time']}-{$shift['end_time']}\n👤 {$shift['role']}\n💰 Bonus: $pay ₽\n<b>Slots left: $remaining</b>";

    $keyboard = ['inline_keyboard' => [
        [['text' => '✅ TAKE SHIFT', 'callback_data' => "accept_{$os_id}"]]
    ]];
    foreach($free_ids as $tg_id){
        sendMsg($tg_id, $msg, $keyboard);
    }
}

function broadcastClosed($conn, $os_id){
    $os = $conn->prepare("SELECT * FROM open_shifts WHERE id=?");
    $os->execute([$os_id]);
    $shift = $os->fetch();
    $msg = "🔒 <b>All vacancies taken</b>\n{$shift['shift_date']} {$shift['start_time']}-{$shift['end_time']} is now FULL. - Tukur_ShiftPro_bot";
    $users = $conn->prepare("SELECT COALESCE(telegram_chat_id, telegram_id) FROM users WHERE business_id=? AND (telegram_chat_id IS NOT NULL OR telegram_id IS NOT NULL)");
    $users->execute([$shift['business_id']]);
    while($u = $users->fetchColumn()){
        sendMsg($u, $msg);
    }
}

function broadcastReopen($conn, $os_id){
    $os = $conn->prepare("SELECT os.*, b.name as business_name FROM open_shifts os JOIN businesses b ON b.id=os.business_id WHERE os.id=?");
    $os->execute([$os_id]);
    $shift = $os->fetch();
    $msg = "♻️ <b>1 SLOT REOPENED!</b>\n{$shift['shift_date']} {$shift['start_time']}-{$shift['end_time']}\nSomeone cancelled - be fast! - Tukur_ShiftPro_bot";
    $keyboard = ['inline_keyboard' => [[['text' => '✅ TAKE SHIFT', 'callback_data' => "accept_{$os_id}"]]]];
    $free_ids = getFreeEmployees($conn, $shift);
    foreach($free_ids as $tg_id){
        sendMsg($tg_id, $msg, $keyboard);
    }
}
?>
