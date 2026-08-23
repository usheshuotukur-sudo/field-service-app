<?php 
require 'config.php'; 
checkAdmin();

$business_id = $_SESSION['business_id'];

// Get date range. Default = current month
$start_date = $_GET['start']?? date('Y-m-01');
$end_date = $_GET['end']?? date('Y-m-t');

// Set headers for Excel download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=payroll_'.date('Y-m', strtotime($start_date)).'.csv');
header('Pragma: no-cache');
header('Expires: 0');

// BOM for Excel to read UTF-8 Russian correctly
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// CSV Headers
fputcsv($output, ['ОТЧЕТ ПО СМЕНАМ', '', '', '']);
fputcsv($output, ['Период:', date('d.m.Y', strtotime($start_date)).' - '.date('d.m.Y', strtotime($end_date)), '', '']);
fputcsv($output, []); // empty row
fputcsv($output, ['Сотрудник', 'Дата', 'Начало', 'Конец', 'Часы', 'Ставка, ₽', 'Сумма, ₽']);

// Get all shifts in date range with user data
$stmt = $conn->prepare("
    SELECT 
        u.name, 
        s.shift_date, 
        s.start_time, 
        s.end_time,
        u.hourly_rate,
        ROUND(TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time)/60, 2) as hours
    FROM shifts s 
    JOIN users u ON s.employee_id = u.id 
    WHERE s.business_id = ? 
    AND s.shift_date BETWEEN ? AND ?
    ORDER BY u.name, s.shift_date
");
$stmt->execute([$business_id, $start_date, $end_date]);

$total_hours = 0;
$total_pay = 0;
$employee_totals = []; // for summary at bottom

while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
    $pay = round($row['hours'] * $row['hourly_rate'], 2);
    $total_hours += $row['hours'];
    $total_pay += $pay;
    
    // Track per employee
    $employee_totals[$row['name']]['hours'] = ($employee_totals[$row['name']]['hours']?? 0) + $row['hours'];
    $employee_totals[$row['name']]['pay'] = ($employee_totals[$row['name']]['pay']?? 0) + $pay;

    fputcsv($output, [
        $row['name'],
        date('d.m.Y', strtotime($row['shift_date'])),
        date('H:i', strtotime($row['start_time'])),
        date('H:i', strtotime($row['end_time'])),
        $row['hours'],
        $row['hourly_rate'],
        $pay
    ]);
}

fputcsv($output, []); // empty row
fputcsv($output, ['ИТОГО:', '', '', '', $total_hours, '', $total_pay]);

// Summary per employee
fputcsv($output, []);
fputcsv($output, ['СВОДКА ПО СОТРУДНИКАМ']);
fputcsv($output, ['Сотрудник', 'Всего часов', 'Всего к выплате, ₽']);
foreach($employee_totals as $name => $data){
    fputcsv($output, [$name, round($data['hours'],2), round($data['pay'],2)]);
}

fclose($output);
exit();
?>