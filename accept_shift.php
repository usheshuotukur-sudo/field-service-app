<?php
require 'config.php';
session_start();
if(!isset($_SESSION['user_id'])){ header('Location: index.php'); exit(); }

$user_id = $_SESSION['user_id'];
$os_id = $_POST['open_shift_id'];

$conn->beginTransaction();
$os = $conn->prepare("SELECT * FROM open_shifts WHERE id=? AND status='open' FOR UPDATE");
$os->execute([$os_id]);
$shift = $os->fetch();

if($shift && $shift['slots_taken'] < $shift['slots_total']){
    $conn->prepare("INSERT IGNORE INTO open_shift_applications (open_shift_id, staff_id) VALUES (?,?)")->execute([$os_id, $user_id]);
    $conn->prepare("UPDATE open_shifts SET slots_taken = slots_taken + 1 WHERE id=?")->execute([$os_id]);
    
    // Create real shift so it shows in both dashboards
    $conn->prepare("INSERT INTO shifts (business_id, employee_id, shift_date, start_time, end_time) VALUES (?,?,?,?,?)")
    ->execute([$shift['business_id'], $user_id, $shift['shift_date'], $shift['start_time'], $shift['end_time']]);

    if($shift['slots_taken']+1 >= $shift['slots_total']){
        $conn->prepare("UPDATE open_shifts SET status='closed' WHERE id=?")->execute([$os_id]);
    }
    $conn->commit();
    setFlash('success', '✅ Вы заняли место на смене!');
} else {
    $conn->rollBack();
    setFlash('danger', 'Мест уже нет');
}
header('Location: my_schedule.php');