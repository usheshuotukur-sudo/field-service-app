<?php 
session_start();
require 'config.php'; 
$error = ""; 
$selected_plan = intval($_GET['plan'] ?? 2); // Get plan from landing. Default Pro
$plan_name = [1=>'Starter',2=>'Pro',3=>'Business'][$selected_plan];
$is_paid_flow = isset($_GET['paid']); // NEW: пришли после оплаты?

// NEW: CHECK IF USER IS ALREADY LOGGED IN AND IN LAST 2 DAYS OF TRIAL
$show_trial_warning = false;
$days_left = 0;
if(isset($_SESSION['business_id'])){
    $stmt = $conn->prepare("SELECT trial_ends_at, subscription_status FROM businesses WHERE id = ?");
    $stmt->execute([$_SESSION['business_id']]);
    $business = $stmt->fetch();
    if($business && $business['subscription_status'] == 'trial'){
        $days_left = ceil((strtotime($business['trial_ends_at']) - time()) / 86400);
        if($days_left <= 2 && $days_left > 0){
            $show_trial_warning = true;
        }
    }
}

if($_SERVER['REQUEST_METHOD'] == 'POST' && hash_equals($_SESSION['csrf'], $_POST['csrf'])){
    $business_name = trim($_POST['business_name']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $plan_id = intval($_POST['plan_id']); // get plan from hidden input

    try {
        // 1. VALIDATION
        if(strlen($password) < 6) $error = "Пароль должен быть минимум 6 символов";
        elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = "Неверный формат email";
        elseif(!preg_match('/^7\d{10}$/', $phone)) $error = "Телефон в формате: 79031234567";
        elseif(empty($business_name)) $error = "Введите название компании";
        else {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if($stmt->fetch()) {
                $error = "Этот email уже зарегистрирован";
            } else {
                $conn->beginTransaction();

                if($is_paid_flow){ // NEW: СРАЗУ АКТИВНЫЙ
                    $paid_until = date('Y-m-d H:i:s', strtotime('+1 month'));
                    $stmt = $conn->prepare("INSERT INTO businesses (name, plan_id, subscription_status, paid_until) VALUES (?,?, 'active', ?)"); 
                    $stmt->execute([$business_name, $plan_id, $paid_until]);
                } else { // NEW: ТРИАЛ
                    $trial_ends = date('Y-m-d H:i:s', strtotime('+14 days'));
                    $stmt = $conn->prepare("INSERT INTO businesses (name, plan_id, trial_ends_at, subscription_status) VALUES (?,?,?,'trial')"); 
                    $stmt->execute([$business_name, $plan_id, $trial_ends]);
                }
                $business_id = $conn->lastInsertId();

                // 3. Create Admin User for that business
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (business_id, name, email, password, role, phone, hourly_rate) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([
                    $business_id, $name, $email, $hash, 'admin', $phone, 0.00
                ]);
                $user_id = $conn->lastInsertId();

                $conn->commit();

                // 4. AUTO-LOGIN
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user_id;
                $_SESSION['business_id'] = $business_id;
                $_SESSION['role'] = 'admin';
                $_SESSION['user_name'] = htmlspecialchars($name);
                
                $msg = $is_paid_flow ? 'Оплата прошла! Подписка активна.' : 'Компания создана! 14 дней триала на тарифе '.$plan_name;
                setFlash('success', $msg);
                header("Location: week.php"); 
                exit();
            }
        }
    } catch(PDOException $e) {
        $conn->rollBack();
        $error = "Ошибка регистрации: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<title>Регистрация компании</title>
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-body p-4">
                    <a href="landing.php" class="text-decoration-none">← Назад на главную</a>
                    
                    <!-- NEW: SHOW WARNING ONLY IN LAST 2 DAYS -->
                    <?php if($show_trial_warning): ?>
                    <div class="alert alert-warning text-center">
                        Внимание! До окончания бесплатного периода осталось <?= $days_left ?> дн. 
                        <a href="billing.php" class="alert-link">Продлить подписку</a>
                    </div>
                    <?php endif; ?>

                    <h3 class="text-center mb-2 mt-2"><?= $is_paid_flow ? 'Завершить регистрацию' : 'Создать аккаунт компании' ?></h3>
                    <p class="text-center text-primary fw-bold">
                        Тариф: <?= $plan_name ?> | <?= $is_paid_flow ? 'Оплата пройдена' : '14 дней бесплатно' ?>
                    </p>
                    <?php if($error) echo "<div class='alert alert-danger'>$error</div>"; ?>
                    <?php if(isset($_GET['success'])) echo "<div class='alert alert-success'>✓ План изменен</div>"; ?>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                        <input type="hidden" name="plan_id" value="<?= $selected_plan ?>"> <!-- SAVE PLAN -->

                        <div class="mb-3">
                            <label class="form-label">Название компании</label>
                            <input name="business_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ваше ФИО</label>
                            <input name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email для входа</label>
                            <input name="email" type="email" value="<?=htmlspecialchars($_GET['email']??'')?>" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Телефон</label>
                            <input name="phone" class="form-control" placeholder="79031234567" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Пароль</label>
                            <input name="password" type="password" class="form-control" required minlength="6">
                        </div>
                        <button class="btn btn-primary w-100"><?= $is_paid_flow ? 'Активировать подписку' : 'Начать триал' ?></button>
                    </form>
                    <p class="text-center mt-3">Уже есть аккаунт? <a href="index.php">Войти</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>