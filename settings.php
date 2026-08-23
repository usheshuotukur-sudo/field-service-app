<?php 
require 'config.php'; 
checkAdmin();

if($_SERVER['REQUEST_METHOD'] == 'POST' && hash_equals($_SESSION['csrf'], $_POST['csrf'])){
    $stmt = $conn->prepare("UPDATE settings SET business_name=?, smsc_login=?, smsc_password=?, reminder_time=? WHERE id=1");
    $stmt->execute([
        $_POST['business_name'],
        $_POST['smsc_login'],
        $_POST['smsc_password'],
        $_POST['reminder_time']
    ]);
    setFlash('success', 'Настройки сохранены');
    header("Location: settings.php"); exit();
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<title>Настройки</title>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand" href="week.php"><?= e($settings['business_name'])?></a>
  </div>
</nav>

<div class="container mt-4">
    <div class="card shadow">
        <div class="card-body p-4">
            <h3>⚙️ Настройки системы</h3>
            <?php foreach($flash as $type=>$msg) echo "<div class='alert alert-$type'>$msg</div>";?>

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                
                <h5 class="mt-3">1. Компания</h5>
                <div class="mb-3">
                    <label class="form-label">Название компании</label>
                    <input name="business_name" class="form-control" value="<?= e($settings['business_name'])?>" required>
                    <small>Это название будет в SMS и отчетах</small>
                </div>

                <h5 class="mt-4">2. SMSC.ru для напоминаний</h5>
                <div class="mb-3">
                    <label class="form-label">Логин SMSC</label>
                    <input name="smsc_login" class="form-control" value="<?= e($settings['smsc_login'])?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Пароль SMSC</label>
                    <input name="smsc_password" type="password" class="form-control" value="<?= e($settings['smsc_password'])?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">За сколько часов отправлять напоминание</label>
                    <input name="reminder_time" type="number" class="form-control" value="<?= e($settings['reminder_time'])?>">
                </div>

                <button class="btn btn-primary">Сохранить</button>
                <a href="week.php" class="btn btn-secondary">Назад</a>
            </form>
        </div>
    </div>
</div>


<div class="mb-3">
    <label>Country Code for SMS</label>
    <input type="text" name="country_code" class="form-control" value="<?= e($settings['country_code']?? '993')?>">
    <small>Turkmenistan = 993, Russia = 7, Kazakhstan = 7</small>
</div>

</body>
</html>

