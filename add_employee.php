<?php 
require 'config.php'; 
checkAdmin(); 

$success = ""; 
$error = "";

if($_SERVER['REQUEST_METHOD'] == 'POST' && hash_equals($_SESSION['csrf'], $_POST['csrf'])){
    try {
        // 1. Check if email already exists in THIS business
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND business_id = ?");
        $stmt->execute([$_POST['email'], $_SESSION['business_id']]);
        if($stmt->fetch()) {
            $error = "Сотрудник с таким email уже есть";
        } else {
            $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (business_id, name, email, password, role, phone, hourly_rate) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([
                $_SESSION['business_id'], 
                e($_POST['name']), 
                $_POST['email'], 
                $hash, 
                'employee', 
                $_POST['phone'], 
                $_POST['hourly_rate'] ?: 0.00
            ]);
            $success = "Сотрудник добавлен!";
        }
    } catch(PDOException $e) {
        $error = "Ошибка: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Добавить сотрудника</title>
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-body p-4">
                    <h3>Добавить сотрудника</h3>
                    <?php if($success) echo "<div class='alert alert-success'>$success</div>"; ?>
                    <?php if($error) echo "<div class='alert alert-danger'>$error</div>"; ?>

                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">ФИО</label>
                            <input name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input name="email" type="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Телефон для WhatsApp</label>
                            <input name="phone" class="form-control" placeholder="79031234567" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ставка в час, ₽</label>
                            <input name="hourly_rate" type="number" step="0.01" value="500" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Пароль для входа</label>
                            <input name="password" type="password" class="form-control" required>
                        </div>
                        <button class="btn btn-success">Добавить</button> 
                        <a href="week.php" class="btn btn-secondary">Назад</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>