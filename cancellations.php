<?php
require 'config.php';
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin'){ header('Location: index.php'); exit(); }

$business_id = $_SESSION['business_id'];
$business_name = $_SESSION['business_name'];

// Filter by date if needed
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

$stmt = $conn->prepare("
    SELECT sc.*, u.name, u.phone
    FROM shift_cancellations sc
    JOIN users u ON sc.employee_id = u.id
    WHERE sc.business_id = ? AND sc.shift_date BETWEEN ? AND ?
    ORDER BY sc.cancelled_at DESC
");
$stmt->execute([$business_id, $from, $to]);
$cancels = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<title>Журнал отмен</title>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark">
  <div class="container">
    <span class="navbar-brand">Журнал отмен: <?= e($business_name)?></span>
    <a href="week.php" class="btn btn-outline-light btn-sm">← Назад в график</a>
  </div>
</nav>

<div class="container mt-4">
    <form method="GET" class="card card-body mb-3">
        <div class="row">
            <div class="col"><label>С</label><input type="date" name="from" value="<?= $from?>" class="form-control"></div>
            <div class="col"><label>По</label><input type="date" name="to" value="<?= $to?>" class="form-control"></div>
            <div class="col d-flex align-items-end"><button class="btn btn-primary w-100">Фильтр</button></div>
        </div>
    </form>

    <div class="card">
        <div class="card-header">
            <b>Всего отмен: <?= count($cancels)?></b>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Дата смены</th>
                        <th>Время</th>
                        <th>Сотрудник</th>
                        <th>Причина</th>
                        <th>Отменено</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(count($cancels)==0):?>
                    <tr><td colspan="5" class="text-center text-muted p-4">Нет отмен за этот период</td></tr>
                <?php endif;?>
                <?php foreach($cancels as $c):?>
                <tr>
                    <td><?= date('d.m.Y', strtotime($c['shift_date']))?></td>
                    <td><?= date('H:i', strtotime($c['start_time']))?>-<?= date('H:i', strtotime($c['end_time']))?></td>
                    <td><?= e($c['name'])?><br><small class="text-muted"><?= e($c['phone'])?></small></td>
                    <td><?= e($c['reason'])?></td>
                    <td><small><?= date('d.m H:i', strtotime($c['cancelled_at']))?></small></td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>