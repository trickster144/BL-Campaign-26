<?php
session_start();
include __DIR__ . "/../files/config.php";

$error = "";

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, account_type, team FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (password_verify($password, $row['password'])) {
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['account_type'] = $row['account_type'];
                $_SESSION['team'] = $row['team'] ?? 'grey';
                header("Location: " . BASE_URL . "index.php");
                exit;
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            $error = "Invalid username or password.";
        }
        $stmt->close();
    }
}

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
	<meta charset="utf-8">
	<title>Black Legion Clan | Login</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link href="<?= BASE_URL ?>assets/css/vendor.min.css" rel="stylesheet">
	<link href="<?= BASE_URL ?>assets/css/app.min.css" rel="stylesheet">
</head>
<body class="pace-top">
	<div id="app" class="app app-full-height app-without-header">
		<!-- BEGIN login -->
		<div class="login">
			<div class="login-content">
				<form action="login.php" method="POST" name="login_form">
					<h1 class="text-center">Sign In</h1>
					<div class="text-inverse text-opacity-50 text-center mb-4">
						For your protection, please verify your identity.
					</div>
					<?php if ($error): ?>
						<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
					<?php endif; ?>
					<div class="mb-3">
						<label class="form-label">Username <span class="text-danger">*</span></label>
						<input type="text" class="form-control form-control-lg bg-inverse bg-opacity-5" name="username" required autofocus>
					</div>
					<div class="mb-3">
						<label class="form-label">Password <span class="text-danger">*</span></label>
						<input type="password" class="form-control form-control-lg bg-inverse bg-opacity-5" name="password" required>
					</div>
					<button type="submit" class="btn btn-outline-theme btn-lg d-block w-100 fw-500 mb-3">Sign In</button>
					<div class="text-center text-inverse text-opacity-50">
						Don't have an account yet? <a href="register.php">Sign up</a>.
					</div>
				</form>
			</div>
		</div>
		<!-- END login -->
<?php
//include __DIR__ . "/../files/themepanel.php";
include __DIR__ . "/../files/scripts.php";
?>	
