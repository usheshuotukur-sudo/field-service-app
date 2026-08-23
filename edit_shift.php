<?php 
require 'config.php'; 
checkAdmin();

$business_id = $_SESSION['business_id'];
$shift_id = $_GET['id']?? 0;
$error = ""; 
$success = "";

// 1. Get shift + verify it belongs to this business
$stmt = $conn->prepare("SELECT s.*, u.name FROM shifts s JOIN users u ON s.employee_id=u.id WHERE s.id=? AND s.business_id=?");
$stmt->execute([$shift_id, $business_id]); 
$shift = $stmt->fetch();

if(!$shift){
    setFlash('danger','Смена не найдена');
    header("Location: week.php"); exit();
}

// 2. Get all employees for dropdown
$employees = $conn->prepare("SELECT * FROM users WHERE business_id =? AND role = 'employee' ORDER BY name");
$employees->execute([$business_id]); $employees = $employees->fetchAll();

// 3. Handle UPDATE
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_shift']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])){
    
    // Overlap check for the NEW employee/date/time
    $stmt = $conn->prepare("SELECT id FROM shifts WHERE employee_id=? AND shift_date=? AND id !=? AND ((start_time <? AND end_time >?) OR (start_time <? AND end_time >?))");
    $stmt->execute([$_POST['employee_id'], $_POST['shift_date'], $shift_id, $_POST['end_time'], $_POST['start_time'], $_POST['end_time'], $_POST['start_time']]);
    
    if($stmt->fetch()) $error = "Ошибка: у сотрудника уже есть смена в это время";
    elseif($_POST['end_time'] <= $_POST['start_time']) $error = "Конец смены должен быть позже начала";
    else {
        $stmt = $conn->prepare("UPDATE shifts SET employee_id=?, shift_date=?, start_time=?, end_time=? WHERE id=? AND business_id=?");
        $stmt->execute([$_POST['employee_id'], $_POST['shift_date'], $_POST['start_time'], $_POST['end_time'], $shift_id, $business_id]);
        
        // NEW: Notify employee about edit
        $msg = "Админ изменил вашу смену на: ".date('d.m',strtotime($_POST['shift_date']))." ".$_POST['start_time']."-".$_POST['end_time'];
        $conn->prepare("INSERT INTO shift_notifications (user_id, business_id, message) VALUES (?,?,?)")
        ->execute([$_POST['employee_id'], $business_id, $msg]);
        // END NEW

        setFlash('success', 'Смена обновлена');
        header("Location: week.php?week=".$_POST['shift_date']); exit();
    }
}

// 4. Handle DELETE
if(isset($_POST['delete_shift']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])){
    $stmt = $conn->prepare("DELETE FROM shifts WHERE id =? AND business_id =?");
    $stmt->execute([$shift_id, $business_id]);
    setFlash('danger', 'Смена удалена');
    header("Location: week.php?week=".$shift['shift_date']); exit();
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<title>Редактировать смену</title>
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-body p-4">
                    <h3>Редактировать смену: <?= e($shift['name'])?></h3>
                    <p class="text-muted">Дата: <?= date('d.m.Y', strtotime($shift['shift_date']))?></p>

                    <?php foreach($flash as $type=>$msg) echo "<div class='alert alert-$type'>$msg</div>";?>
                    <?php if($error) echo "<div class='alert alert-danger'>$error</div>"; ?>

                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Сотрудник</label>
                            <select name="employee_id" class="form-select" required>
                                <?php foreach($employees as $e):?>
                                <option value="<?= $e['id']?>" <?= $e['id']==$shift['employee_id']?'selected':''?>><?= e($e['name'])?></option>
                                <?php endforeach;?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Дата</label>
                            <input name="shift_date" type="date" class="form-control" value="<?= $shift['shift_date']?>" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Начало</label>
                                <input name="start_time" type="time" class="form-control" value="<?= $shift['start_time']?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Конец</label>
                                <input name="end_time" type="time" class="form-control" value="<?= $shift['end_time']?>" required>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <button name="update_shift" class="btn btn-primary">Сохранить</button>
                            <button name="delete_shift" class="btn btn-danger" onclick="return confirm('Точно удалить смену?')">Удалить</button>
                        </div>
                        <a href="week.php?week=<?= $shift['shift_date']?>" class="btn btn-secondary w-100 mt-2">Назад</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>