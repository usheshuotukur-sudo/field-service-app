<?php 
session_start(); // ADDED: you were missing this
require 'config.php'; 
$error = "";

// If already logged in, send to correct dashboard
if(isset($_SESSION['user_id'])){
    $redirect = ($_SESSION['role'] == 'admin') ? 'week.php' : 'my_schedule.php'; 
    header("Location: $redirect"); 
    exit();
}

if($_SERVER['REQUEST_METHOD'] == 'POST' && hash_equals($_SESSION['csrf'], $_POST['csrf'])){
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if(empty($email) || empty($password)){
        $error = "Заполните email и пароль";
    } else {
        $stmt = $conn->prepare("SELECT u.*, b.subscription_status, b.trial_ends_at, b.paid_until FROM users u JOIN businesses b ON u.business_id = b.id WHERE u.email = ? LIMIT 1");
        $stmt->execute([$email]); 
        $user = $stmt->fetch();

        if($user && password_verify($password, $user['password'])){
            
            // CHECK SUBSCRIPTION STATUS BEFORE LOGIN
            $now = date('Y-m-d H:i:s');
            $is_active = ($user['subscription_status'] == 'active' && $user['paid_until'] >= $now);
            $is_trial = ($user['subscription_status'] == 'trial' && $user['trial_ends_at'] >= $now);

            if($user['role'] == 'admin'){
                // ADMIN: Check if trial/paid is active
                if(!$is_active && !$is_trial){
                    // Redirect with flag so billing.php can show "trial expired" banner
                    header("Location: billing.php?expired=trial");
                    exit();
                }
            } else {
                // EMPLOYEE: Can only login if admin has active sub
                if(!$is_active && !$is_trial){
                    $error = "Доступ временно недоступен. Обратитесь к администратору.";
                    $user = false; // prevent login
                }
            }

            if($user){ // Only login if not blocked above
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['business_id'] = $user['business_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['user_name'] = e($user['name']);

                // THIS IS THE DIFFERENTIATION
                $redirect = ($user['role'] == 'admin') ? 'week.php' : 'my_schedule.php';
                setFlash('success', 'С возвращением, '.$user['name']);
                header("Location: $redirect"); 
                exit();
            }

        } else {
            $error = "Неверный email или пароль";
        }
    }
}
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<title>Вход - <?= e($settings['business_name']??'Shift Scheduler')?></title>
</head>
<body class="bg-light d-flex align-items-center" style="min-height: 100vh;">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card shadow">
                <div class="card-body p-4">
                    <h3 class="text-center mb-4"><?= e($settings['business_name']??'Shift Scheduler')?></h3>
                    
                    <?php foreach($flash as $type=>$msg) echo "<div class='alert alert-$type'>$msg</div>"; ?>
                    <?php if($error) echo "<div class='alert alert-danger'>$error</div>"; ?>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input name="email" type="email" class="form-control" required value="<?= e($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Пароль</label>
                            <input name="password" type="password" class="form-control" required>
                        </div>
                        <button class="btn btn-primary w-100">Войти</button>
                    </form>
                    
                    <hr>
                    <p class="text-center mb-0">Вы владелец бизнеса?</p>
                    <a href="register.php" class="btn btn-outline-primary w-100">Создать компанию</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>