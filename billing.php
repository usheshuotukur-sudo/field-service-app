<?php 
session_start();
require 'config.php'; 
require 'vendor/autoload.php'; // Load YooKassa
use YooKassa\Client;

$plan_id = intval($_GET['plan'] ?? 2);
$stmt = $conn->prepare("SELECT * FROM plans WHERE id=?");
$stmt->execute([$plan_id]);
$plan = $stmt->fetch();
if(!$plan) die('Тариф не найден');

// TEST: Check if YooKassa loads
$yk_loaded = false;
try {
    $client = new Client();
    $client->setAuth('TEST_SHOP_ID', 'TEST_SECRET_KEY'); // put fake keys for now
    $yk_loaded = true;
} catch(Exception $e) {
    $yk_loaded = false;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<title>Оплата - <?=e($plan['name'])?></title>
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-body p-4">
                    <a href="landing.php" class="text-decoration-none">← Назад к тарифам</a>
                    <h3 class="text-center mb-3 mt-2">Оплата подписки</h3>

                    <!-- YooKassa Status -->
                    <?php if($yk_loaded): ?>
                        <div class="alert alert-success">✅ YooKassa SDK загружен</div>
                    <?php else: ?>
                        <div class="alert alert-danger">❌ Ошибка загрузки YooKassa</div>
                    <?php endif; ?>

                    <!-- NEW: SHOW IF TRIAL EXPIRED -->
                    <?php if(isset($_GET['expired']) && $_GET['expired'] == 'trial'): ?>
                    <div class="alert alert-danger">
                        <h5 class="alert-heading">⚠️ Бесплатный период истек</h5>
                        Ваш 14-дневный пробный период закончился. Для продолжения работы выберите тариф и оплатите подписку.
                    </div>
                    <?php endif; ?>

                    <div class="alert alert-info">
                        <h5 class="mb-0">Тариф: <?=e($plan['name'])?></h5>
                        <div class="fs-3 fw-bold"><?=number_format($plan['price'],0,'.',' ')?> ₽/мес</div>
                        <small>До <?= $plan['max_employees'] ?> сотрудников, <?= $plan['sms_included'] ?> SMS включено</small>
                    </div>
                    
                    <p>После успешной оплаты вы введете данные компании и сразу получите доступ.</p>
                    
                    <!-- ТУТ БУДЕТ YOOKASSA. ПОКА ДЕЛАЕМ ЗАГЛУШКУ -->
                    <a href="register.php?paid=1&plan=<?= $plan_id ?>" class="btn btn-success w-100 btn-lg">
                        Перейти к регистрации
                    </a>
                    <small class="text-muted d-block mt-2 text-center">* Позже тут подключим YooKassa. Сейчас это тестовая кнопка</small>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>