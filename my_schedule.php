<?php
require 'config.php';
if(!isset($_SESSION['user_id'])){ header('Location: index.php'); exit(); }
if($_SESSION['role']!= 'employee'){ header('Location: week.php'); exit(); }

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$business_id = $_SESSION['business_id'];
$today = date('Y-m-d');
$now = date('H:i:s');

$week = $_GET['week']?? date('Y-m-d', strtotime('monday this week'));
$monday = date('Y-m-d', strtotime($week));
$sunday = date('Y-m-d', strtotime($monday. ' +6 days'));
$prev_week = date('Y-m-d', strtotime($monday. ' -7 days'));
$next_week = date('Y-m-d', strtotime($monday. ' +7 days'));

// NEW: GET WEEK STATS FOR DASHBOARD
$week_stmt = $conn->prepare("SELECT SUM(TIMESTAMPDIFF(MINUTE, clock_in, clock_out))/60 as hours FROM shifts WHERE employee_id=? AND business_id=? AND clock_in IS NOT NULL AND clock_out IS NOT NULL AND shift_date >=? AND shift_date <=?");
$week_stmt->execute([$user_id, $business_id, $monday, $sunday]);
$week_data = $week_stmt->fetch();
$week_hours = round($week_data['hours']?? 0, 2);
$hourly_rate = $conn->query("SELECT hourly_rate FROM users WHERE id=$user_id")->fetch()['hourly_rate']?? 0;
$est_salary = $week_hours * $hourly_rate;

// NEW: MARK NOTIFICATION AS READ
if(isset($_POST['mark_read']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])){
    $notif_id = intval($_POST['notif_id']);
    $conn->prepare("UPDATE shift_notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$notif_id, $user_id]);
    header("Location: my_schedule.php?week=$monday"); exit();
}

// NEW: AUTO ABSENT CHECK - Run every page load
$stmt_absent = $conn->prepare("
    UPDATE shifts SET status='absent', absent_marked_at=NOW()
    WHERE employee_id=? AND status='scheduled' AND clock_in IS NULL
    AND CONCAT(shift_date,' ',start_time) <= DATE_SUB(NOW(), INTERVAL 2 HOUR)
");
$stmt_absent->execute([$user_id]);

// NEW: Load notifications
$notif_stmt = $conn->prepare("SELECT * FROM shift_notifications WHERE user_id=? AND is_read=0 ORDER BY created_at DESC");
$notif_stmt->execute([$user_id]);
$notifications = $notif_stmt->fetchAll();

// FIX: Also load hourly_rate and shift_id to check bonus
$stmt = $conn->prepare("
    SELECT s.*, b.name as business_name, u.hourly_rate
    FROM shifts s
    JOIN businesses b ON s.business_id = b.id
    JOIN users u ON s.employee_id = u.id
    WHERE s.employee_id =? AND s.business_id =?
    AND s.shift_date BETWEEN? AND?
    ORDER BY s.shift_date, s.start_time
");
$stmt->execute([$user_id, $business_id, $monday, $sunday]);
$shifts = $stmt->fetchAll();

// NEW: Load all open_shifts for bonus calculation
$all_os_stmt = $conn->prepare("SELECT shift_date, start_time, end_time, pay FROM open_shifts WHERE business_id=? AND shift_date BETWEEN? AND?");
$all_os_stmt->execute([$business_id, $monday, $sunday]);
$all_open_shifts = $all_os_stmt->fetchAll();

// NEW: Load all open_shift_applications for this employee this week to check conflicts + deleted
$applied_shifts_stmt = $conn->prepare("
    SELECT osa.*, os.shift_date, os.start_time, os.end_time
    FROM open_shift_applications osa
    JOIN open_shifts os ON osa.open_shift_id = os.id
    WHERE osa.staff_id=? AND os.business_id=? AND os.shift_date BETWEEN? AND?
");
$applied_shifts_stmt->execute([$user_id, $business_id, $monday, $sunday]);
$my_applied_open_shifts = $applied_shifts_stmt->fetchAll();

// FIXED: Load open_shifts that were cancelled for this employee by checking shift_notifications
$blocked_stmt = $conn->prepare("
    SELECT os.id FROM open_shifts os
    WHERE os.business_id=? AND os.shift_date BETWEEN? AND?
    AND EXISTS (
        SELECT 1 FROM shift_notifications sn
        WHERE sn.user_id=? AND sn.business_id=?
        AND sn.message LIKE CONCAT('%', DATE_FORMAT(os.shift_date,'%d.%m'), '%', os.start_time, '%', os.end_time, '%')
        AND sn.message LIKE '%Админ отменил вашу смену%'
    )
");
$blocked_stmt->execute([$business_id, $monday, $sunday, $user_id, $business_id]);
$blocked_open_shift_ids = array_column($blocked_stmt->fetchAll(), 'id');

$days = [];
$total_planned = 0;
$total_actual = 0;
$total_pay = 0; // NEW
$total_bonus = 0; // NEW

// FIX: Helper for overnight
function getHours($start, $end){
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    if($end_ts < $start_ts) $end_ts += 86400;
    return round(($end_ts - $start_ts) / 3600, 1);
}

foreach($shifts as $s){
    $days[$s['shift_date']][] = $s;

    // FIX: Use getHours for overnight
    $planned_h = getHours($s['shift_date'].' '.$s['start_time'], $s['shift_date'].' '.$s['end_time']);
    $total_planned += $planned_h;

    $shift_pay = 0;
    $shift_bonus = 0;
    if($s['clock_in'] && $s['clock_out']){
        $actual_h = getHours($s['clock_in'], $s['clock_out']);
        $total_actual += $actual_h;
        $shift_pay = $actual_h * ($s['hourly_rate']?? 0);

        // NEW: Check if this was open shift and add bonus
        foreach($all_open_shifts as $os){
            if($os['shift_date']==$s['shift_date'] && $os['start_time']==$s['start_time'] && $os['end_time']==$s['end_time']){
                $shift_bonus = $os['pay'];
                $total_bonus += $shift_bonus;
                break;
            }
        }
    }
    $total_pay += $shift_pay + $shift_bonus;
}
$flash = getFlash();

// HELPER: Check if 2 time ranges overlap
function timesOverlap($s1, $e1, $s2, $e2){
    return ($s1 < $e2) && ($e1 > $s2);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<title>Мой график - ShiftPro</title>
<style>
:root{
    --bg: #f7f9fc;
    --card: #ffffff;
    --border: #d1d5db;
    --text: #111827;
}
body{ background: var(--bg); font-family: 'Inter', sans-serif; color: var(--text);}
.navbar{ backdrop-filter: blur(10px); background: rgba(37,99,235,0.95)!important; }

.clock-btn { font-size: 16px; font-weight: 600; border-radius: 12px; }
.shift-card {
    border-radius: 12px;
    border: 1px solid var(--border);
    background: var(--card);
    transition: all.2s;
}
.shift-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.06); }

/* Light Blue and Light Green for shifts */
.shift-info { background: #e0f2fe; border-color: #7dd3fc; }
.shift-success { background: #d1fae5; border-color: #6ee7b7; }

.today-card { border: 2px solid #2563eb!important; box-shadow: 0 0 15px rgba(37,99,235,.2); }
.absent-card { border-left: 5px solid #dc3545; background:#fef2f2!important; }
.dash-card{background:var(--card); padding:20px; border-radius:16px; box-shadow:0 2px 8px rgba(0,0,0,0.05); border:1px solid var(--border)}
.card{ border-radius: 16px; border: 1px solid var(--border); }
.badge{ border-radius: 8px; }
</style>
</head>
<body>
<nav class="navbar navbar-dark sticky-top">
  <div class="container">
    <span class="navbar-brand fw-bold">📅 График: <?= e($user_name)?></span>
    <a href="logout.php" class="btn btn-outline-light btn-sm">Выйти</a>
  </div>
</nav>

<div class="container mt-4">
    <!-- EMPLOYEE DASHBOARD -->
    <div class="row mb-4">
        <div class="col-6 col-md-3 mb-3">
            <div class="dash-card text-center">
                <div class="text-muted small">Часов за неделю</div>
                <div class="h3 fw-bold"><?=$week_hours?></div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="dash-card text-center">
                <div class="text-muted small">Оценка ЗП</div>
                <div class="h3 fw-bold"><?=number_format($est_salary,0)?> ₽</div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="dash-card text-center">
                <div class="text-muted small">Всего смен</div>
                <div class="h3 fw-bold"><?=count($shifts)?></div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="dash-card text-center">
                <div class="text-muted small">Бонусы</div>
                <div class="h3 fw-bold text-success"><?=number_format($total_bonus,0)?> ₽</div>
            </div>
        </div>
    </div>

    <!-- SHOW NOTIFICATIONS -->
    <?php foreach($notifications as $n):
        $shift_date_for_notif = '';
        if(preg_match('/(\d{2}\.\d{2})/', $n['message'], $matches)){
            $shift_date_for_notif = date('Y'). '-'. substr($matches[1],3,2). '-'. substr($matches[1],0,2);
        }
?>
        <div class="alert alert-warning alert-dismissible fade show rounded-3"
             id="notif-<?= $n['id']?>"
             data-shift-date="<?= $shift_date_for_notif?>">
            ⚠️ <?= e($n['message'])?>
            <form method="POST" class="d-inline">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf']?>">
                <input type="hidden" name="notif_id" value="<?= $n['id']?>">
                <button type="submit" name="mark_read" class="btn-close" aria-label="Close"></button>
            </form>
        </div>
    <?php endforeach;?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="my_schedule.php?week=<?= $prev_week?>" class="btn btn-outline-secondary">← Пред неделя</a>
        <h2 class="h5 text-center fw-bold mb-0">Неделя: <?= date('d.m', strtotime($monday))?> - <?= date('d.m', strtotime($sunday))?></h2>
        <div>
            <a href="export_my_schedule.php?week=<?= $monday?>" class="btn btn-success btn-sm me-1">📊 Экспорт Excel</a>
            <a href="my_schedule.php?week=<?= $next_week?>" class="btn btn-outline-secondary">След неделя →</a>
        </div>
    </div>

    <div class="card mb-4 bg-primary text-white">
        <div class="card-body d-flex justify-content-around text-center">
            <div><div class="h4"><?= $total_planned?></div><small>План часов</small></div>
            <div><div class="h4"><?= $total_actual?></div><small>Факт часов</small></div>
            <div><div class="h4"><?= count($shifts)?></div><small>Смен</small></div>
            <div><div class="h4"><?= number_format($total_pay,0)?> ₽</div><small>ЗП за неделю</small></div>
        </div>
    </div>

    <?php foreach($flash as $type=>$msg) echo "<div class='alert alert-$type alert-dismissible fade show rounded-3'>$msg <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";?>

    <?php for($i=0; $i<7; $i++):
        $date = new DateTime($monday); $date->modify("+$i days");
        $date_str = $date->format('Y-m-d');
        $is_today = $date_str == $today;
        $is_past = $date_str < $today;
        $card_color = ($i % 2 == 0)? 'info' : 'success'; // alternate colors

        $os_stmt = $conn->prepare("SELECT * FROM open_shifts WHERE business_id=? AND shift_date=? AND status='open'");
        $os_stmt->execute([$business_id, $date_str]);
        $open_shifts_today = $os_stmt->fetchAll();
?>
    <div class="card mb-3 <?= $is_today? 'today-card' : ''?>">
        <div class="card-header d-flex justify-content-between fw-bold">
            <div>
                <b><?= $date->format('d.m.Y, l')?></b>
                <?= $is_today? '<span class="badge bg-primary ms-2">Сегодня</span>' : ''?>
                <?= $is_past? '<span class="badge bg-secondary ms-2">Прошло</span>' : ''?>
            </div>
        </div>
        <div class="card-body">

        <?php if(count($open_shifts_today)>0):?>
            <div class="alert alert-danger mb-3 rounded-3">
                <h6 class="mb-2 fw-bold">🚨 Доступные открытые смены:</h6>
                <?php foreach($open_shifts_today as $os):
                    $already_applied = false;
                    $was_deleted_by_admin = false;
                    $was_edited_by_admin = false;
                    $has_time_conflict = false;
                    $was_blocked_for_me = in_array($os['id'], $blocked_open_shift_ids);

                    $applied = $conn->prepare("SELECT id FROM open_shift_applications WHERE open_shift_id=? AND staff_id=?");
                    $applied->execute([$os['id'], $user_id]);
                    if($applied_row = $applied->fetch()) $already_applied = true;

                    if($already_applied){
                        $check_shift = $conn->prepare("SELECT id FROM shifts WHERE employee_id=? AND shift_date=? AND start_time=? AND end_time=? AND business_id=?");
                        $check_shift->execute([$user_id, $os['shift_date'], $os['start_time'], $os['end_time'], $business_id]);
                        if(!$check_shift->fetch()){
                            $check_any = $conn->prepare("SELECT id FROM shifts WHERE employee_id=? AND business_id=? LIMIT 1");
                            $check_any->execute([$user_id, $business_id]);
                            if($check_any->fetch()){ $was_edited_by_admin = true; } else { $was_deleted_by_admin = true; }
                        }
                    }

                    if(isset($days[$date_str])){
                        foreach($days[$date_str] as $my_shift){
                            if(timesOverlap($os['start_time'], $os['end_time'], $my_shift['start_time'], $my_shift['end_time'])){
                                $has_time_conflict = true; break;
                            }
                        }
                    }
                    foreach($my_applied_open_shifts as $my_os){
                        if($my_os['shift_date'] == $date_str && $my_os['open_shift_id']!= $os['id']){
                            if(timesOverlap($os['start_time'], $os['end_time'], $my_os['start_time'], $my_os['end_time'])){
                                $has_time_conflict = true; break;
                            }
                        }
                    }
   ?>
                <div class="p-3 mb-2 bg-white rounded-3 border">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <b><?= e($os['role'])?></b> <?= $os['start_time']?>-<?= $os['end_time']?><br>
                            <small>Бонус: <?= number_format($os['pay'],0)?> ₽ | Мест: <?= $os['slots_total'] - $os['slots_taken']?></small>
                        </div>
                        <div>
                        <?php if($was_blocked_for_me):?>
                            <span class="badge bg-dark">Админ отменил</span>
                        <?php elseif($was_edited_by_admin):?>
                            <span class="badge bg-warning">Админ изменил</span>
                        <?php elseif($was_deleted_by_admin):?>
                            <span class="badge bg-danger">Отменено</span>
                        <?php elseif(!$already_applied &&!$has_time_conflict && ($os['slots_total'] - $os['slots_taken']) > 0):?>
                            <form method="POST" action="accept_shift.php" class="d-inline">
                                <input type="hidden" name="open_shift_id" value="<?= $os['id']?>">
                                <button class="btn btn-sm btn-success">Занять место</button>
                            </form>
                        <?php elseif($already_applied):?>
                            <span class="badge bg-secondary">Уже занято</span>
                        <?php elseif($has_time_conflict):?>
                            <span class="badge bg-warning">Пересечение</span>
                        <?php else:?>
                            <span class="badge bg-secondary">Мест нет</span>
                        <?php endif;?>
                        </div>
                    </div>
                </div>
                <?php endforeach;?>
            </div>
        <?php endif;?>

        <?php if(isset($days[$date_str])):?>
            <?php foreach($days[$date_str] as $s):
                $planned_hours = getHours($s['shift_date'].' '.$s['start_time'], $s['shift_date'].' '.$s['end_time']);
                $shift_end_datetime = $date_str.' '.$s['end_time'];
                $deadline_to_clock_out = date('Y-m-d H:i:s', strtotime($shift_end_datetime.' +3 hours'));
                $can_clock_out = $is_today && (date('Y-m-d H:i:s') <= $deadline_to_clock_out);
                $is_absent = $s['status'] == 'absent';
                $can_cancel =!$is_past &&!$s['clock_in'] &&!$is_absent;

                $actual_h = 0; $shift_pay = 0; $shift_bonus = 0;
                if($s['clock_in'] && $s['clock_out']){
                    $actual_h = getHours($s['clock_in'], $s['clock_out']);
                    $shift_pay = $actual_h * ($s['hourly_rate']?? 0);
                    foreach($all_open_shifts as $os){
                        if($os['shift_date']==$s['shift_date'] && $os['start_time']==$s['start_time'] && $os['end_time']==$s['end_time']){
                            $shift_bonus = $os['pay'];
                            break;
                        }
                    }
                }
?>
            <div class="p-3 mb-3 shift-card shift-<?= $card_color?> <?= $is_absent? 'absent-card' : ''?>">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="mb-1 fw-bold"><?= e($s['business_name'])?>
                            <?php if($is_absent):?><span class="badge bg-danger ms-2">❌ Неявка</span><?php endif;?>
                        </h6>
                        <div><b>План:</b> <?= date('H:i', strtotime($s['start_time']))?> - <?= date('H:i', strtotime($s['end_time']))?> | <?= $planned_hours?> ч</div>

                        <?php if($s['clock_in']):?>
                            <div class="text-success mt-1"><b>🟢 Вход:</b> <?= date('H:i', strtotime($s['clock_in']))?></div>
                        <?php endif;?>
                        <?php if($s['clock_out']):?>
                            <div class="text-danger"><b>🔴 Выход:</b> <?= date('H:i', strtotime($s['clock_out']))?></div>
                            <div class="text-success fw-bold"><small><?= $actual_h?> ч = <?= number_format($shift_pay + $shift_bonus, 0)?> ₽</small></div>
                            <?php if($shift_bonus > 0):?><small class="text-success">+ Бонус: <?= $shift_bonus?> ₽</small><?php endif;?>
                        <?php endif;?>
                    </div>
                    <?php if($can_cancel):?>
                    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#cancelModal<?= $s['id']?>">Отменить</button>
                    <?php endif;?>
                </div>

                <?php if($is_today &&!$is_past &&!$is_absent):?>
                <!-- GPS WITH FALLBACK -->
                <form method="POST" action="clock.php" class="mt-3" id="clockForm<?=$s['id']?>">
                    <input type="hidden" name="shift_id" value="<?= $s['id']?>">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf']?>">
                    <input type="hidden" name="lat" id="lat<?=$s['id']?>">
                    <input type="hidden" name="lng" id="lng<?=$s['id']?>">
                    <input type="hidden" name="action" id="action<?=$s['id']?>">

                    <?php if(!$s['clock_in']):?>
                        <button type="button" class="btn btn-success btn-lg w-100 clock-btn" onclick="clockWithGPS(<?=$s['id']?>,'in')">🟢 Я ПРИШЕЛ</button>
                    <?php elseif(!$s['clock_out']):?>
                        <?php if($can_clock_out):?>
                            <button type="button" class="btn btn-danger btn-lg w-100 clock-btn" onclick="clockWithGPS(<?=$s['id']?>,'out')">🔴 Я УШЕЛ</button>
                        <?php else:?>
                            <button class="btn btn-secondary btn-lg w-100 clock-btn" disabled>🔴 Время отметки истекло</button>
                            <small class="text-danger">Прошло более 3 часов после окончания смены</small>
                        <?php endif;?>
                    <?php else:?>
                        <div class="alert alert-success text-center mb-0 rounded-3">✅ Смена закрыта</div>
                    <?php endif;?>
                </form>
                <?php elseif($is_absent):?>
                    <div class="alert alert-danger mt-3 mb-0 rounded-3">Вы не отметились в течение 2 часов. Смена отмечена как неявка.</div>
                <?php elseif(!$is_today &&!$is_past):?>
                    <small class="text-muted mt-2 d-block">Отметка будет доступна в день смены</small>
                <?php elseif($is_past &&!$s['clock_in'] &&!$is_absent):?>
                    <small class="text-danger mt-2 d-block">Пропуск: не было отметки</small>
                <?php endif;?>
            </div>

            <div class="modal fade" id="cancelModal<?= $s['id']?>" tabindex="-1">
              <div class="modal-dialog">
                <form method="POST" action="cancel_shift.php" class="modal-content rounded-3">
                  <input type="hidden" name="csrf" value="<?= $_SESSION['csrf']?>">
                  <input type="hidden" name="shift_id" value="<?= $s['id']?>">
                  <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Отменить смену</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <p>Смена: <b><?= date('d.m', strtotime($s['shift_date']))?> <?= $s['start_time']?>-<?= $s['end_time']?></b></p>
                    <div class="mb-3">
                      <label class="form-label">Причина отмены *</label>
                      <textarea name="reason" class="form-control" rows="3" required placeholder="Укажите причину..."></textarea>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                    <button name="cancel_shift" class="btn btn-danger">Подтвердить отмену</button>
                  </div>
                </form>
              </div>
            </div>
            <?php endforeach;?>
        <?php endif;?>

        <?php if(!isset($days[$date_str]) && count($open_shifts_today)==0):?>
            <span class="text-muted">Выходной</span>
        <?php endif;?>
        </div>
    </div>
    <?php endfor;?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// FIXED: Always submit after 3 seconds even if GPS blocked
function clockWithGPS(shiftId, action){
    const btn = event.target;
    btn.disabled = true;
    btn.innerText = 'Определение местоположения...';
    let submitted = false;

    function submitNow(lat, lng){
        if(submitted) return;
        submitted = true;
        document.getElementById('lat'+shiftId).value = lat;
        document.getElementById('lng'+shiftId).value = lng;
        document.getElementById('action'+shiftId).value = action;
        document.getElementById('clockForm'+shiftId).submit();
    }

    if(navigator.geolocation){
        navigator.geolocation.getCurrentPosition(
            function(pos){ submitNow(pos.coords.latitude, pos.coords.longitude); },
            function(err){ submitNow('', ''); },
            {timeout: 3000}
        );
        setTimeout(function(){ submitNow('', ''); }, 3000);
    } else {
        submitNow('', '');
    }
}
</script>
</body>
</html>