<?php
require_once 'config/database.php';

$email = "admin@amgc.com";
$password = "admin123";

$sql = "SELECT * FROM users WHERE email='$email'";
$result = $conn->query($sql);
$user = $result->fetch_assoc();

var_dump($user);
echo "<br>";

var_dump(password_verify($password, $user['password_hash']));
