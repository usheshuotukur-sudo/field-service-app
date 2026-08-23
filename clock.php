<?php
require 'config.php';
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'employee') die("Нет доступа");

if($_SERVER['REQUEST_METHOD'] == 'POST' && hash_equals($_SESSION['csrf'], $_POST['csrf'])){
    $shift_id = intval($_POST['shift_id']);
    $user_id = $_SESSION['user_id'];
    $now = date('Y-m-d H:i:s');
    
    // GPS can be empty - that's ok
    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? floatval($_POST['lat']) : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? floatval($_POST['lng']) : null;

    // Security: check shift belongs to this employee
    $stmt = $conn->prepare("SELECT id FROM shifts WHERE id=? AND employee_id=?");
    $stmt->execute([$shift_id, $user_id]);
    if(!$stmt->fetch()) {
        setFlash('danger', 'Ошибка: чужая смена');
        header('Location: my_schedule.php');
        exit();
    }

    if($_POST['action'] == 'in'){
        $stmt = $conn->prepare("UPDATE shifts SET clock_in=?, clock_in_lat=?, clock_in_lng=? WHERE id=? AND clock_in IS NULL");
        if($stmt->execute([$now, $lat, $lng, $shift_id])){
            $gps_msg = $lat ? " | GPS сохранен" : " | GPS не получен";
            setFlash('success', 'Отметка: Пришел в '.date('H:i').$gps_msg);
        } else {
            setFlash('danger', 'Не удалось отметиться. Попробуйте снова.');
        }
    }
    
    if($_POST['action'] == 'out'){
        $stmt = $conn->prepare("UPDATE shifts SET clock_out=?, clock_out_lat=?, clock_out_lng=? WHERE id=? AND clock_in IS NOT NULL AND clock_out IS NULL");
        if($stmt->execute([$now, $lat, $lng, $shift_id])){
            $gps_msg = $lat ? " | GPS сохранен" : " | GPS не получен";
            setFlash('success', 'Отметка: Ушел в '.date('H:i').$gps_msg);
        } else {
            setFlash('danger', 'Не удалось отметиться. Попробуйте снова.');
        }
    }
}
header('Location: my_schedule.php');
exit();