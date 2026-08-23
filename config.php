<?php
session_start();
date_default_timezone_set('Europe/Moscow'); // change to your timezone

// DB CONNECTION
$host = 'localhost';
$db   = 'scheduler_db';      // CHANGE THIS
$user = 'root';          // CHANGE THIS  
$pass = '';              // CHANGE THIS
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $conn = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}

// LOAD SETTINGS FROM DB
$settings_stmt = $conn->query("SELECT * FROM settings WHERE id = 1");
$settings = $settings_stmt->fetch();
if(!$settings){ // create default if empty
    $conn->exec("INSERT INTO settings(id) VALUES(1)");
    $settings = $conn->query("SELECT * FROM settings WHERE id = 1")->fetch();
}

// HELPERS
function e($str){ return htmlspecialchars($str); }
function checkAdmin(){ if(!isset($_SESSION['user_id'])){ header('Location: index.php'); exit(); } }
function setFlash($type, $msg){ $_SESSION['flash'][$type] = $msg; }
function getFlash(){ $flash = $_SESSION['flash']?? []; unset($_SESSION['flash']); return $flash; }

// CSRF
if(empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

// TELEGRAM BOT
define('TELEGRAM_BOT_TOKEN', '8751888801:AAFmNfdS3Fpe6lIqAmHgfMb4aIOh6iikNhs');

function sendTelegram($chat_id, $text){
    $token = TELEGRAM_BOT_TOKEN;
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}
?>