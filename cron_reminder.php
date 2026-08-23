<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'config.php';
date_default_timezone_set('Europe/Moscow'); // RU TIME

echo "<pre>";
echo "=== DEBUG CRON ===\n";
echo "Server Time MSK: ". date('Y-m-d H:i:s')."\n\n";

$smsc_login = $settings['smsc_login'];
$smsc_password = $settings['smsc_password'];
$business_name = $settings['business_name']?? 'Company';

$reminders = [
    12 => ['column' => 'rem_12h_sent', 'text' => 'Напоминание за 12 часов'],
    6 => ['column' => 'rem_6h_sent', 'text' => 'Напоминание за 6 часов'],
    3 => ['column' => 'rem_3h_sent', 'text' => 'Напоминание за 3 часа'],
];

$total_sent = 0;

foreach($reminders as $hours => $data){
    $column = $data['column'];
    $label = $data['text'];
    echo "--- Checking {$hours}h reminders ---\n";

    $window_start = date('Y-m-d H:i:00', strtotime("+{$hours} hours"));
    $window_end = date('Y-m-d H:i:00', strtotime("+{$hours} hours +10 minutes"));
    echo "Looking for shifts BETWEEN: $window_start AND $window_end\n";

    // 1. SHOW ALL SHIFTS IN NEXT 24H SO WE CAN SEE FORMAT
    $debug = $conn->query("SELECT id, shift_date, start_time, end_time, employee_id, $column FROM shifts ORDER BY shift_date, start_time LIMIT 5");
    echo "First 5 shifts in DB:\n";
    foreach($debug as $d){
        $dt = $d['shift_date'].' '.$d['start_time'];
        echo " ID:{$d['id']} | DT: $dt | Flag $column:{$d[$column]}\n";
    }
    echo "\n";

    // 2. NOW RUN THE REAL QUERY
    $stmt = $conn->prepare("
        SELECT s.id, s.shift_date, s.start_time, s.end_time,
               u.name, u.phone
        FROM shifts s
        JOIN users u ON s.employee_id = u.id
        WHERE CONCAT(s.shift_date, ' ', s.start_time) BETWEEN? AND?
        AND s.$column = 0
        AND u.role = 'employee'
    ");
    $stmt->execute([$window_start, $window_end]);
    $shifts = $stmt->fetchAll();

    echo "Found: ".count($shifts)." shifts for {$hours}h\n";

    foreach($shifts as $s){
        //... same send code as before...
        $phone = preg_replace('/[^0-9]/', '', $s['phone']);
        if(strlen($phone) == 11 && $phone[0] == '8') $phone = '7'.substr($phone, 1);
        elseif(strlen($phone) == 10) $phone = '7'.$phone;

        $text = "$label: $business_name\nСмена: ".date('d.m H:i', strtotime($s['shift_date'].' '.$s['start_time']))."-".date('H:i', strtotime($s['end_time']));

        $ch = curl_init("https://smsc.ru/sys/send.php");
        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query(['login'=>$smsc_login,'psw'=>$smsc_password,'phones'=>$phone,'mes'=>$text,'fmt'=>3]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if(isset($res['id']) && $res['id'] > 0){
            $conn->prepare("UPDATE shifts SET $column = 1 WHERE id =?")->execute([$s['id']]);
            echo "[OK] {$hours}h -> {$s['name']} - +$phone\n";
            $total_sent++;
        } else {
            echo "[FAIL] {$hours}h -> {$s['name']} | Error: ".($res['error']??'Unknown')."\n";
        }
        sleep(1);
    }
}
echo "=== DONE === Total Sent: $total_sent ===\n";
echo "</pre>";