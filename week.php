<?php
require 'config.php';
session_start(); // на всякий случай если не в config
checkAdmin();

$business_id = $_SESSION['business_id'];
$admin_id = $_SESSION['user_id'];

// === 1. ТРИАЛ БЛОК - ДОЛЖЕН БЫТЬ ПОСЛЕ require и session ===
$stmt = $conn->prepare("SELECT b.*, p.name as plan_name FROM businesses b JOIN plans p ON b.plan_id=p.id WHERE b.id=?");
$stmt->execute([$business_id]);
$business = $stmt->fetch();

$days_left = $business['trial_ends_at']? ceil((strtotime($business['trial_ends_at']) - time())/86400) : 0;
// === КОНЕЦ ТРИАЛ БЛОКА ===

$week = $_GET['week']?? date('Y-m-d', strtotime('monday this week'));
$monday = date('Y-m-d', strtotime($week));
$sunday = date('Y-m-d', strtotime($monday. ' +6 days'));
$today = date('Y-m-d');

// NEW: MARK NOTIFICATION AS READ FOR ADMIN
if(isset($_POST['mark_read']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])){
    $notif_id = intval($_POST['notif_id']);
    $conn->prepare("UPDATE shift_notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$notif_id, $admin_id]);
    header("Location: week.php?week=$monday"); exit();
}

// NEW: Load admin notifications
$notif_stmt = $conn->prepare("SELECT * FROM shift_notifications WHERE user_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 10");
$notif_stmt->execute([$admin_id]);
$notifications = $notif_stmt->fetchAll();

// FIXED: Helper now takes full datetime to handle overnight
function getHours($start, $end){
    if(empty($start) || empty($end)) return 0;
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    if($end_ts < $start_ts) $end_ts += 86400; // FIX: overnight shift
    $diff = $end_ts - $start_ts;
    return $diff > 0? round($diff / 3600, 2) : 0;
}

// Get data - ADDED hourly_rate
$employees = $conn->prepare("SELECT * FROM users WHERE business_id =? AND role = 'employee' ORDER BY name");
$employees->execute([$business_id]); $employees = $employees->fetchAll();

$shifts = $conn->prepare("SELECT s.*, u.name, u.hourly_rate FROM shifts s JOIN users u ON s.employee_id = u.id WHERE s.business_id =? AND s.shift_date BETWEEN? AND?"); // ADDED u.hourly_rate
$shifts->execute([$business_id, $monday, $sunday]); $shifts = $shifts->fetchAll();

// NEW: Load Open Shifts for this week
$open_shifts = $conn->prepare("SELECT * FROM open_shifts WHERE business_id=? AND shift_date BETWEEN? AND? AND status='open' ORDER BY shift_date");
$open_shifts->execute([$business_id, $monday, $sunday]);
$open_shifts = $open_shifts->fetchAll();

// NEW: Load all open_shifts with bonus to check if shift was from open_shift
$all_os_stmt = $conn->prepare("SELECT id, shift_date, start_time, end_time, pay FROM open_shifts WHERE business_id=? AND shift_date BETWEEN? AND?");
$all_os_stmt->execute([$business_id, $monday, $sunday]);
$all_open_shifts = $all_os_stmt->fetchAll();

// Group shifts by [employee_id][date] for fast lookup
$shifts_by_day = [];
$weekly_hours = [];
$weekly_actual_hours = [];
$weekly_pay = []; // NEW
$weekly_bonus = []; // NEW: track bonuses
foreach($shifts as $s){
    $shifts_by_day[$s['employee_id']][$s['shift_date']][] = $s;
    $rate = $s['hourly_rate']?? 0; // NEW

    // FIXED: use full datetime for planned hours
    $planned_start = $s['shift_date'].' '.$s['start_time'];
    $planned_end = $s['shift_date'].' '.$s['end_time'];
    $weekly_hours[$s['employee_id']] = ($weekly_hours[$s['employee_id']]?? 0) + getHours($planned_start, $planned_end);

    // NEW: Calculate actual hours + pay if clocked
    if($s['clock_in'] && $s['clock_out']){
        $actual_h = getHours($s['clock_in'], $s['clock_out']);
        $weekly_actual_hours[$s['employee_id']] = ($weekly_actual_hours[$s['employee_id']]?? 0) + $actual_h;
        $base_pay = $actual_h * $rate;
        $weekly_pay[$s['employee_id']] = ($weekly_pay[$s['employee_id']]?? 0) + $base_pay; // NEW

        // NEW: Check if this shift was from open_shift and add bonus
        foreach($all_open_shifts as $os){
            if($os['shift_date']==$s['shift_date'] && $os['start_time']==$s['start_time'] && $os['end_time']==$s['end_time']){
                $weekly_bonus[$s['employee_id']] = ($weekly_bonus[$s['employee_id']]?? 0) + $os['pay'];
                $weekly_pay[$s['employee_id']] += $os['pay']; // Add bonus to total pay
                break;
            }
        }
    }
}

$swaps = $conn->prepare("SELECT sw.*, u.name as by_name, s.shift_date, s.start_time FROM shift_swaps sw JOIN shifts s ON sw.shift_id=s.id JOIN users u ON sw.requested_by=u.id WHERE s.business_id=? AND sw.status='pending'");
$swaps->execute([$business_id]); $swaps = $swaps->fetchAll();

// ACTIONS - YOUR CODE UNCHANGED + NEW CHECK
if(isset($_POST['add_shift']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])){
    if($_POST['shift_date'] < $today){
        setFlash('danger','Нельзя добавить смену в прошедший день');
    } else {
        $stmt = $conn->prepare("SELECT id FROM shifts WHERE employee_id=? AND shift_date=? AND ((start_time <? AND end_time >?) OR (start_time <? AND end_time >?))");
        $stmt->execute([$_POST['employee_id'], $_POST['shift_date'], $_POST['end_time'], $_POST['start_time'], $_POST['end_time'], $_POST['start_time']]);
        if($stmt->fetch()) setFlash('danger','Ошибка: у сотрудника уже есть смена в это время');
        elseif($_POST['end_time'] <= $_POST['start_time']) setFlash('danger','Конец смены должен быть позже начала');
        else {
            $stmt = $conn->prepare("INSERT INTO shifts (business_id, employee_id, shift_date, start_time, end_time) VALUES (?,?,?,?,?)");
            $stmt->execute([$business_id, $_POST['employee_id'], $_POST['shift_date'], $_POST['start_time'], $_POST['end_time']]);
            setFlash('success', 'Смена добавлена');
        }
    }
    header("Location: week.php?week=$monday"); exit();
}

// NEW: ACTION TO POST OPEN SHIFT + TELEGRAM NOTIFY
if(isset($_POST['post_open_shift']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])){
    $slots = max(1, intval($_POST['os_slots'])); // CHANGED: cannot be less than 1
    $stmt = $conn->prepare("INSERT INTO open_shifts (business_id, shift_date, start_time, end_time, role, pay, slots_total) VALUES (?,?,?,?,?,?,?)"); // CHANGED: bonus -> pay. Keep column name pay in DB
    $stmt->execute([$business_id, $_POST['os_date'], $_POST['os_start'], $_POST['os_end'], $_POST['os_role'], $_POST['os_pay'], $slots]); // CHANGED: os_bonus -> os_pay
    
    // NEW: Send Telegram notification to all employees with telegram_id
    $emps = $conn->prepare("SELECT telegram_id FROM users WHERE business_id=? AND role='employee' AND telegram_id IS NOT NULL");
    $emps->execute([$business_id]);
    $msg = "🚨 <b>New Open Shift</b>\n📅 Date: ".date('d.m',strtotime($_POST['os_date']))."\n⏰ Time: ".$_POST['os_start']."-".$_POST['os_end']."\n👤 Role: ".e($_POST['os_role'])."\n💰 Bonus: ".$_POST['os_pay']." ₽\n\nOpen the website to take it";
    while($e = $emps->fetch()){
        sendTelegram($e['telegram_id'], $msg);
    }
    
    setFlash('success', 'Открытая смена опубликована!');
    header("Location: week.php?week=$monday"); exit();
}

// FIXED DELETE BLOCK
if(isset($_GET['delete']) &&!empty($_GET['delete'])){
    $shift_id = intval($_GET['delete']);

    // 1. Get shift first
    $check = $conn->prepare("SELECT id, shift_date, start_time, end_time, clock_in, clock_out, employee_id FROM shifts WHERE id=? AND business_id=? LIMIT 1");
    $check->execute([$shift_id, $business_id]);
    $shift = $check->fetch();

    if(!$shift){
        setFlash('danger','Смена не найдена');
    } elseif($shift['shift_date'] < $today){
        setFlash('danger','Нельзя удалить смену в прошедшем дне');
    } elseif($shift['clock_in'] && $shift['clock_out']){
        setFlash('danger','Нельзя удалить завершенную смену. Сначала очистите отметки.');
    } else {
        // 2. FIX: Find if this shift came from open_shift by matching date+time, not by application
        $find_os = $conn->prepare("
            SELECT id as open_shift_id, role FROM open_shifts
            WHERE shift_date=? AND start_time=? AND end_time=? AND business_id=?
            LIMIT 1
        ");
        $find_os->execute([$shift['shift_date'], $shift['start_time'], $shift['end_time'], $business_id]);
        $os_data = $find_os->fetch();

        if($os_data){
            // Free the slot: 1/2 becomes 0/2
            $conn->prepare("UPDATE open_shifts SET slots_taken = GREATEST(0, slots_taken - 1) WHERE id=?")->execute([$os_data['open_shift_id']]);
            $conn->prepare("UPDATE open_shifts SET status='open' WHERE id=?")->execute([$os_data['open_shift_id']]);
            // Remove application so employee can take it again
            $conn->prepare("DELETE FROM open_shift_applications WHERE open_shift_id=? AND staff_id=?")->execute([$os_data['open_shift_id'], $shift['employee_id']]);

            // NEW: Notify all OTHER employees that slot is available
            $msg_available = "🚨 Доступна смена: ".date('d.m',strtotime($shift['shift_date']))." ".$shift['start_time']."-".$shift['end_time']." ".$os_data['role'];
            $notify_all = $conn->prepare("SELECT id FROM users WHERE business_id=? AND role='employee' AND id!=?");
            $notify_all->execute([$business_id, $shift['employee_id']]);
            $ins_notif = $conn->prepare("INSERT INTO shift_notifications (user_id, business_id, message) VALUES (?,?,?)");
            while($emp = $notify_all->fetch()){
                $ins_notif->execute([$emp['id'], $business_id, $msg_available]);
            }
        }

        // 3. Notify deleted employee
        if($shift['employee_id']){
            $msg = "Админ отменил вашу смену: ".date('d.m',strtotime($shift['shift_date']))." ".$shift['start_time']."-".$shift['end_time'];
            $conn->prepare("INSERT INTO shift_notifications (user_id, business_id, message) VALUES (?,?,?)")
            ->execute([$shift['employee_id'], $business_id, $msg]);
        }

        // 4. Delete the shift
        $stmt = $conn->prepare("DELETE FROM shifts WHERE id =? AND business_id =?");
        $stmt->execute([$shift_id, $business_id]);
        setFlash('success', 'Смена удалена. Слот освобожден');
    }
    header("Location: week.php?week=$monday"); exit();
}
// END FIXED DELETE BLOCK

if(isset($_POST['approve_swap'])){
    $stmt = $conn->prepare("UPDATE shifts SET employee_id=? WHERE id=(SELECT shift_id FROM shift_swaps WHERE id=?)");
    $stmt->execute([$_POST['new_employee'], $_POST['swap_id']]);
    $stmt = $conn->prepare("UPDATE shift_swaps SET status='approved' WHERE id=?");
    $stmt->execute([$_POST['swap_id']]);
    setFlash('success', 'Обмен одобрен');
    header("Location: week.php?week=$monday"); exit();
}
$flash = getFlash();

// Colors for employees - Light Blue and Light Green
$colors = ['info','success'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<title>Расписание - ShiftPro</title>
<style>
:root{
    --bg: #f7f9fc;
    --card: #ffffff;
    --border: #d1d5db;
    --text: #111827;
    --muted: #4b5563;
}
body{ background: var(--bg); font-family: 'Inter', sans-serif; color: var(--text);}
.navbar{ backdrop-filter: blur(10px); background: rgba(17,24,39,0.95)!important; }

.shift-card { 
    font-size: 12px; 
    border-radius: 12px; 
    border: 1px solid var(--border);
    transition: all .2s;
    color: var(--text);
}
.shift-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.06); }

/* NEW: Light Blue and Light Green backgrounds */
.shift-info { background: #e0f2fe; border-color: #7dd3fc; }
.shift-success { background: #d1fae5; border-color: #6ee7b7; }

/* FIXED: Past days with proper contrast */
.past-day { background-color: #e5e7eb!important; color: #111827!important; }
.past-day .shift-card{ opacity: 0.85; }
.past-day .text-muted{ color: #4b5563!important; }

.open-shift-card { border: 2px dashed #ef4444; background: #fef2f2; border-radius: 12px; }
.notif-dropdown { max-height: 400px; overflow-y: auto; width: 380px; border-radius: 12px; }
.gps-link { font-size: 10px; color: #2563eb; text-decoration: none; }
.gps-link:hover { text-decoration: underline; }
.btn{ border-radius: 10px; font-weight: 500; }
.card{ border-radius: 16px; border: 1px solid var(--border); }
.table{ border: 1px solid var(--border); }
.table thead th{ background: #111827; color: #fff; font-weight: 600; border: 1px solid #374151!important; }
.table td{ vertical-align: top; border: 1px solid var(--border)!important; }
.avatar{ width: 36px; height: 36px; border-radius: 50%; background: #e5e7eb; display: inline-flex; align-items:center; justify-content:center; font-weight: 600; color: #374151; }
.badge{ border-radius: 8px; }
</style>
</head>
<body>
<nav class="navbar navbar-dark sticky-top">
<div class="container-fluid">
    <a class="navbar-brand fw-bold" href="landing.php">🚀 ShiftPro</a>
    <div class="d-flex align-items-center gap-2">
        <!-- NOTIFICATIONS -->
        <div class="dropdown">
            <button class="btn btn-outline-light btn-sm position-relative" type="button" data-bs-toggle="dropdown">
                🔔 Уведомления
                <?php if(count($notifications)>0):?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= count($notifications)?></span><?php endif;?>
            </button>
            <div class="dropdown-menu dropdown-menu-end p-2 notif-dropdown">
                <?php if(count($notifications)==0):?>
                    <small class="text-muted">Нет новых уведомлений</small>
                <?php else: foreach($notifications as $n):?>
                    <div class="alert alert-warning alert-sm p-2 mb-2 rounded-3">
                        <small><?= e($n['message'])?></small>
                        <form method="POST" class="mt-1">
                            <input type="hidden" name="csrf" value="<?= $_SESSION['csrf']?>">
                            <input type="hidden" name="notif_id" value="<?= $n['id']?>">
                            <button type="submit" name="mark_read" class="btn btn-sm btn-outline-dark w-100">Прочитано</button>
                        </form>
                    </div>
                <?php endforeach; endif;?>
            </div>
        </div>

        <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#openShiftModal">🚨 Открытая смена</button>
        <a href="cancellations.php" class="btn btn-sm btn-warning">📋 Журнал</a>
        <a href="add_employee.php" class="btn btn-sm btn-success">+ Сотрудник</a>
        <a href="export.php" class="btn btn-sm btn-info text-white">📊 Экспорт</a>
        <a href="logout.php" class="btn btn-sm btn-outline-light">Выйти</a>
    </div>
</div>
</nav>

<div class="container-fluid mt-4">

    <!-- TRIAL BLOCK -->
    <?php if($business['subscription_status'] == 'trial' && $days_left > 0):?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center rounded-3">
      <div>⚡ Триал: Осталось <b><?=$days_left?> дней</b>. Тариф: <b><?=e($business['plan_name'])?></b></div>
      <div><a href="billing.php" class="btn btn-sm btn-warning">Оплатить сейчас</a></div>
    </div>
    <?php elseif($business['subscription_status']!= 'active'):?>
    <div class="alert alert-danger d-flex justify-content-between align-items-center rounded-3">
      <div>❌ Доступ ограничен.</div>
      <div><a href="billing.php" class="btn btn-sm btn-danger">Продлить подписку</a></div>
    </div>
    <?php endif;?>

    <!-- WEEK NAV -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="week.php?week=<?= date('Y-m-d', strtotime($monday. ' -7 days'))?>" class="btn btn-outline-secondary">← Пред</a>
        <h2 class="h4 fw-bold mb-0">Неделя: <?= date('d.m', strtotime($monday))?> - <?= date('d.m', strtotime($sunday))?></h2>
        <a href="week.php?week=<?= date('Y-m-d', strtotime($monday. ' +7 days'))?>" class="btn btn-outline-secondary">След →</a>
    </div>

    <?php foreach($flash as $type=>$msg) echo "<div class='alert alert-$type alert-dismissible fade show rounded-3'>$msg <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";?>

    <!-- OPEN SHIFTS -->
    <?php if(count($open_shifts)>0):?>
    <div class="card mb-4 border-danger">
        <div class="card-header bg-danger text-white fw-bold rounded-top-3">🚨 Открытые смены на эту неделю</div>
        <div class="card-body">
            <div class="row g-2">
            <?php foreach($open_shifts as $os):?>
                <div class="col-md-4">
                    <div class="p-3 open-shift-card">
                        <b><?= date('d.m', strtotime($os['shift_date']))?> <?= e($os['role'])?></b><br>
                        <small><?= $os['start_time']?>-<?= $os['end_time']?> | Мест: <?= $os['slots_taken']?>/<?= $os['slots_total']?> | Бонус: <?= number_format($os['pay'],0)?> ₽</small>
                    </div>
                </div>
            <?php endforeach;?>
            </div>
        </div>
    </div>
    <?php endif;?>

    <!-- Quick Add Form -->
    <div class="card mb-4">
        <div class="card-header fw-bold">⚡ Быстрое добавление смены</div>
        <div class="card-body">
            <form method="POST" class="row g-3 align-items-end">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf']?>">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Сотрудник</label>
                    <select name="employee_id" class="form-select" required><option value="">Выберите...</option><?php foreach($employees as $e):?><option value='<?= $e['id']?>'><?= e($e['name'])?></option><?php endforeach;?></select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Дата</label>
                    <input type="date" name="shift_date" class="form-control" required min="<?= $today?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Начало</label>
                    <input type="time" name="start_time" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Конец</label>
                    <input type="time" name="end_time" class="form-control" required>
                </div>
                <div class="col-md-2"><button name="add_shift" class="btn btn-primary w-100">Добавить</button></div>
            </form>
            <small class="text-muted mt-2 d-block">Можно добавлять смены только с <?= date('d.m.Y')?> и позже</small>
        </div>
    </div>

    <!-- Weekly Grid -->
    <div class="card">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:240px">Сотрудник / План / Факт / ЗП</th>
                        <?php for($i=0; $i<7; $i++): $day = date('Y-m-d', strtotime($monday. " +$i days")); $is_past = $day < $today;?>
                        <th class="text-center <?= $is_past? 'past-day' : ''?>">
                            <div class="fw-bold"><?= date('D', strtotime($day))?></div>
                            <small><?= date('d.m', strtotime($day))?></small>
                            <?php if($is_past) echo '<div><span class="badge bg-secondary mt-1">Прошло</span></div>';?>
                        </th>
                        <?php endfor;?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($employees as $idx => $emp): $color = $colors[$idx % count($colors)];?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="avatar"><?= mb_substr($emp['name'],0,1)?></div>
                            <div>
                                <div class="fw-bold"><?= e($emp['name'])?></div>
                                <small class="text-muted">Ставка: <?= number_format($emp['hourly_rate'],0)?> ₽/ч</small><br>
                                <small class="text-muted">План: <?= $weekly_hours[$emp['id']]?? 0?> ч | Факт: <?= $weekly_actual_hours[$emp['id']]?? 0?> ч</small><br>
                                <?php $bonus = $weekly_bonus[$emp['id']]?? 0;?>
                                <span class="badge bg-success">ЗП: <?= number_format($weekly_pay[$emp['id']]?? 0, 2, '.', '')?> ₽</span>
                                <?php if($bonus > 0):?><small class="text-success d-block">+ Бонус: <?= number_format($bonus,0)?> ₽</small><?php endif;?>
                            </div>
                        </div>
                    </td>
                    <?php for($i=0; $i<7; $i++): $day = date('Y-m-d', strtotime($monday. " +$i days")); $is_past = $day < $today;?>
                    <td class="p-2 <?= $is_past? 'past-day' : ''?>">
                        <?php if(isset($shifts_by_day[$emp['id']][$day])):?>
                            <?php foreach($shifts_by_day[$emp['id']][$day] as $s):
                                $rate = $s['hourly_rate']?? 0;
                                $actual_h = 0; $actual_pay = 0; $bonus_for_shift = 0;
                                $is_completed = $s['clock_in'] && $s['clock_out'];
                                if($is_completed){
                                    $actual_h = getHours($s['clock_in'], $s['clock_out']);
                                    $actual_pay = $actual_h * $rate;
                                    foreach($all_open_shifts as $os){
                                        if($os['shift_date']==$s['shift_date'] && $os['start_time']==$s['start_time'] && $os['end_time']==$s['end_time']){
                                            $bonus_for_shift = $os['pay'];
                                            $actual_pay += $bonus_for_shift;
                                            break;
                                        }
                                    }
                                }
                      ?>
                            <div class="p-2 mb-2 shift-card shift-<?= $color?>">
                                <div class="d-flex justify-content-between">
                                    <b>План:</b> <span><?= date('H:i', strtotime($s['start_time']))?>-<?= date('H:i', strtotime($s['end_time']))?></span>
                                </div>

                                <?php if($s['clock_in']):?>
                                    <div class="text-success"><small>🟢 Вход: <?= date('H:i', strtotime($s['clock_in']))?></small></div>
                                    <?php if($s['clock_in_lat'] && $s['clock_in_lng']):?>
                                        <a href="https://www.google.com/maps?q=<?=$s['clock_in_lat']?>,<?=$s['clock_in_lng']?>" target="_blank" class="gps-link">📍 Посмотреть на карте</a>
                                    <?php endif;?>
                                <?php endif;?>
                                <?php if($s['clock_out']):?>
                                    <div class="text-danger"><small>🔴 Выход: <?= date('H:i', strtotime($s['clock_out']))?></small></div>
                                    <?php if($s['clock_out_lat'] && $s['clock_out_lng']):?>
                                        <a href="https://www.google.com/maps?q=<?=$s['clock_out_lat']?>,<?=$s['clock_out_lng']?>" target="_blank" class="gps-link">📍 Посмотреть на карте</a>
                                    <?php endif;?>
                                    <div class="text-success fw-bold"><small><?= $actual_h?> ч = <?= number_format($actual_pay, 0)?> ₽</small></div>
                                    <?php if($bonus_for_shift > 0):?><small class="text-success">+ Бонус: <?= $bonus_for_shift?> ₽</small><?php endif;?>
                                <?php endif;?>

                                <div class="mt-2 d-flex gap-1">
                                    <?php if($is_completed):?>
                                        <span class="badge bg-success">✅ Завершено</span>
                                    <?php elseif(!$is_past):?>
                                        <a href="edit_shift.php?id=<?= $s['id']?>" class="btn btn-sm btn-dark py-0 px-2">✏️</a>
                                        <a href="week.php?delete=<?= $s['id']?>&week=<?=$monday?>" onclick="return confirm('Удалить смену?')" class="btn btn-sm btn-danger py-0 px-2">×</a>
                                    <?php else:?>
                                        <span class="badge bg-secondary">Заблокировано</span>
                                    <?php endif;?>
                                </div>
                            </div>
                            <?php endforeach;?>
                        <?php endif;?>
                    </td>
                    <?php endfor;?>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL FOR POSTING OPEN SHIFT -->
<div class="modal fade" id="openShiftModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content rounded-3">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf']?>">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">🚨 Опубликовать открытую смену</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Дата</label><input type="date" name="os_date" class="form-control" required min="<?= $today?>"></div>
        <div class="row mb-3">
          <div class="col"><label class="form-label">Начало</label><input type="time" name="os_start" class="form-control" required></div>
          <div class="col"><label class="form-label">Конец</label><input type="time" name="os_end" class="form-control" required></div>
        </div>
        <div class="mb-3"><label class="form-label">Роль</label><input type="text" name="os_role" class="form-control" placeholder="Официант" required></div>
        <div class="row">
          <div class="col"><label class="form-label">Кол-во мест</label><input type="number" name="os_slots" value="1" min="1" class="form-control" required></div>
          <div class="col"><label class="form-label">Бонус ₽</label><input type="number" name="os_pay" value="0" class="form-control"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button name="post_open_shift" class="btn btn-danger">Опубликовать</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>