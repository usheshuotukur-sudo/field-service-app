<?php
require 'config.php';
session_start();
if(!isset($_SESSION['user_id'])){ header('Location: index.php'); exit(); }
if($_SESSION['role']!= 'employee'){ header('Location: week.php'); exit(); }

if(isset($_POST['cancel_shift']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])){
    $shift_id = intval($_POST['shift_id']);
    $reason = trim($_POST['reason']);
    $user_id = $_SESSION['user_id'];
    $business_id = $_SESSION['business_id'];
    $today = date('Y-m-d');

    if(empty($reason)){
        setFlash('danger','Укажите причину отмены');
        header("Location: my_schedule.php"); exit();
    }

    // 1. Get shift
    $stmt = $conn->prepare("SELECT * FROM shifts WHERE id=? AND employee_id=? AND business_id=?");
    $stmt->execute([$shift_id, $user_id, $business_id]);
    $shift = $stmt->fetch();

    if(!$shift){
        setFlash('danger','Смена не найдена');
    } elseif($shift['shift_date'] < $today){
        setFlash('danger','Нельзя отменить прошедшую смену');
    } elseif($shift['clock_in']){
        setFlash('danger','Нельзя отменить начатую смену');
    } else {
        // 2. LOG THE CANCELLATION FIRST
        $log = $conn->prepare("INSERT INTO shift_cancellations (business_id, shift_id, employee_id, shift_date, start_time, end_time, reason) VALUES (?,?,?,?,?,?,?)");
        $log->execute([$business_id, $shift_id, $user_id, $shift['shift_date'], $shift['start_time'], $shift['end_time'], $reason]);

        // 3. Check if this shift already came from open_shift
        $find_os = $conn->prepare("SELECT id FROM open_shifts WHERE shift_date=? AND start_time=? AND end_time=? AND business_id=?");
        $find_os->execute([$shift['shift_date'], $shift['start_time'], $shift['end_time'], $business_id]);
        $os = $find_os->fetch();

        if($os){
            // Just free the slot
            $conn->prepare("UPDATE open_shifts SET slots_taken = GREATEST(0, slots_taken - 1), status='open' WHERE id=?")->execute([$os['id']]);
            $conn->prepare("DELETE FROM open_shift_applications WHERE open_shift_id=? AND staff_id=?")->execute([$os['id'], $user_id]);
        } else {
            // 4. Create new open_shift from this cancelled shift
            $conn->prepare("INSERT INTO open_shifts (business_id, shift_date, start_time, end_time, role, pay, slots_total, slots_taken) VALUES (?,?,?,?,?,?,1,0)")
            ->execute([$business_id, $shift['shift_date'], $shift['start_time'], $shift['end_time'], 'Смена', 0]);
        }

        // 5. Delete the shift from employee
        $conn->prepare("DELETE FROM shifts WHERE id=?")->execute([$shift_id]);

        // 6. Notify ALL other employees
        $msg = "🚨 Сотрудник отменил смену: ".date('d.m',strtotime($shift['shift_date']))." ".$shift['start_time']."-".$shift['end_time'].". Причина: ".$reason;
        $notify_all = $conn->prepare("SELECT id FROM users WHERE business_id=? AND role='employee' AND id!=?");
        $notify_all->execute([$business_id, $user_id]);
        $ins_notif = $conn->prepare("INSERT INTO shift_notifications (user_id, business_id, message) VALUES (?,?,?)");
        while($emp = $notify_all->fetch()){
            $ins_notif->execute([$emp['id'], $business_id, $msg]);
        }

        setFlash('success','Смена отменена. Она теперь доступна другим сотрудникам.');
    }
    header("Location: my_schedule.php?week=".$shift['shift_date']); exit();
}
header('Location: my_schedule.php'); exit();