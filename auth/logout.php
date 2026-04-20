<?php
session_start();
include __DIR__ . "/../files/config.php";
session_unset();
session_destroy();
header("Location: " . BASE_URL . "index.php");
exit;
?>
