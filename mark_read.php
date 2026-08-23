<?php
require 'config.php';
session_start();
$conn->prepare("UPDATE shift_notifications SET is_read=1 WHERE id=? AND user_id=?")
->execute([$_GET['id'], $_SESSION['user_id']]);
header('Location: my_schedule.php');