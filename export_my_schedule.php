<?php
require 'config.php';
if(!isset($_SESSION['user_id'])){ header('Location: index.php'); exit(); }
if($_SESSION['role']!= 'employee'){ header('Location: week.php'); exit(); }

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$business_id = $_SESSION['business_id'];

$week = $_GET['week']?? date('Y-m-d', strtotime('monday this week'));
$monday = date('Y-m-d', strtotime($week));
$sunday = date('Y-m-d', strtotime($monday. ' +6 days'));

// Helper for overnight
function getHours($start, $end){
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    if($end_ts < $start_ts) $end_ts += 86400;
    return round(($end_ts - $start_ts) / 3600, 2);
}

// Get all shifts
$stmt = $conn->prepare("
    SELECT s.*, u.hourly_rate
    FROM shifts s
    JOIN users u ON s.employee_id = u.id
    WHERE s.employee_id =? AND s.business_id =?
    AND s.shift_date BETWEEN? AND?
    ORDER BY s.shift_date, s.start_time
");
$stmt->execute([$user_id, $business_id, $monday, $sunday]);
$shifts = $stmt->fetchAll();

// Get all open_shifts for bonus
$all_os_stmt = $conn->prepare("SELECT shift_date, start_time, end_time, pay FROM open_shifts WHERE business_id=? AND shift_date BETWEEN? AND?");
$all_os_stmt->execute([$business_id, $monday, $sunday]);
$all_open_shifts = $all_os_stmt->fetchAll();

// Headers for Excel
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=График_".e($user_name)."_".date('d.m-Y',strtotime($monday)).".xls");
header("Pragma: no-cache");
header("Expires: 0");
echo "\xEF\xBB\xBF"; // UTF-8 BOM

echo "<table border='1'>";
echo "<tr>
        <th>Дата</th>
        <th>Начало план</th>
        <th>Конец план</th>
        <th>Часы план</th>
        <th>Вход факт</th>
        <th>Выход факт</th>
        <th>Часы факт</th>
        <th>Ставка ₽/ч</th>
        <th>ЗП по часам ₽</th>
        <th>Бонус ₽</th>
        <th>Итого ₽</th>
        <th>Статус</th>
      </tr>";

$total_hours = 0;
$total_pay = 0;
$total_bonus = 0;

foreach($shifts as $s){
    $planned_h = getHours($s['shift_date'].' '.$s['start_time'], $s['shift_date'].' '.$s['end_time']);
    
    $actual_h = 0;
    $pay_hours = 0;
    $bonus = 0;
    $total = 0;
    $status = 'Запланировано';
    
    if($s['status'] == 'absent') $status = 'Неявка';
    if($s['clock_in'] && $s['clock_out']){
        $actual_h = getHours($s['clock_in'], $s['clock_out']);
        $pay_hours = $actual_h * ($s['hourly_rate']?? 0);
        
        // Check bonus
        foreach($all_open_shifts as $os){
            if($os['shift_date']==$s['shift_date'] && $os['start_time']==$s['start_time'] && $os['end_time']==$s['end_time']){
                $bonus = $os['pay'];
                break;
            }
        }
        $total = $pay_hours + $bonus;
        $status = 'Завершено';
    } elseif($s['clock_in']) {
        $status = 'В процессе';
    }

    $total_hours += $actual_h;
    $total_pay += $pay_hours;
    $total_bonus += $bonus;

    echo "<tr>";
    echo "<td>".date('d.m.Y', strtotime($s['shift_date']))."</td>";
    echo "<td>".date('H:i', strtotime($s['start_time']))."</td>";
    echo "<td>".date('H:i', strtotime($s['end_time']))."</td>";
    echo "<td>".$planned_h."</td>";
    echo "<td>".($s['clock_in']? date('H:i', strtotime($s['clock_in'])) : '-')."</td>";
    echo "<td>".($s['clock_out']? date('H:i', strtotime($s['clock_out'])) : '-')."</td>";
    echo "<td>".$actual_h."</td>";
    echo "<td>".number_format($s['hourly_rate'],0)."</td>";
    echo "<td>".number_format($pay_hours,0)."</td>";
    echo "<td>".number_format($bonus,0)."</td>";
    echo "<td>".number_format($total,0)."</td>";
    echo "<td>".$status."</td>";
    echo "</tr>";
}

// Total row
echo "<tr style='font-weight:bold; background:#f0f0f0;'>";
echo "<td colspan='6'>ИТОГО ЗА НЕДЕЛЮ</td>";
echo "<td>".$total_hours."</td>";
echo "<td>-</td>";
echo "<td>".number_format($total_pay,0)."</td>";
echo "<td>".number_format($total_bonus,0)."</td>";
echo "<td>".number_format($total_pay + $total_bonus,0)."</td>";
echo "<td>-</td>";
echo "</tr>";

echo "</table>";