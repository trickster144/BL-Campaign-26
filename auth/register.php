<?php
session_start();
include __DIR__ . "/../files/config.php";

$error = "";
$success = "";

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($username) || empty($password) || empty($confirm)) {
        $error = "Please fill in all fields.";
    } elseif (strlen($username) < 3 || strlen($username) > 30) {
        $error = "Username must be between 3 and 30 characters.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        // Check if username already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "Username is already taken.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $account_type = "user";
            $team = "grey";

            $insert = $conn->prepare("INSERT INTO users (username, password, account_type, team) VALUES (?, ?, ?, ?)");
            $insert->bind_param("ssss", $username, $hashed, $account_type, $team);

            if ($insert->execute()) {
                $success = "Account created! You've been placed on Grey Team. A moderator will assign you to a team shortly.";
            } else {
                $error = "Registration failed. Please try again.";
            }
            $insert->close();
        }
        $stmt->close();
    }
}

$username = "Please Login";
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
	<meta charset="utf-8">
	<title>Black Legion Clan | Register</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link href="<?= BASE_URL ?>assets/css/vendor.min.css" rel="stylesheet">
	<link href="<?= BASE_URL ?>assets/css/app.min.css" rel="stylesheet">
</head>
<body class="pace-top">
	<div id="app" class="app app-full-height app-without-header">
		<!-- BEGIN login -->
		<div class="login">
			<div class="login-content">
				<form action="register.php" method="POST">
					<h1 class="text-center">Sign Up</h1>
					<div class="text-inverse text-opacity-50 text-center mb-4">
						Create your account to get started.
					</div>
					<?php if ($error): ?>
						<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
					<?php endif; ?>
					<?php if ($success): ?>
						<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
					<?php endif; ?>
					<div class="mb-3">
						<label class="form-label">Username <span class="text-danger">*</span></label>
						<input type="text" class="form-control form-control-lg bg-inverse bg-opacity-5" name="username" minlength="3" maxlength="30" required autofocus>
					</div>
					<div class="mb-3">
						<label class="form-label">Password <span class="text-danger">*</span></label>
						<input type="password" class="form-control form-control-lg bg-inverse bg-opacity-5" name="password" minlength="6" required>
					</div>
					<div class="mb-3">
						<label class="form-label">Confirm Password <span class="text-danger">*</span></label>
						<input type="password" class="form-control form-control-lg bg-inverse bg-opacity-5" name="confirm_password" minlength="6" required>
					</div>
					<button type="submit" class="btn btn-outline-theme btn-lg d-block w-100 fw-500 mb-3">Sign Up</button>
					<div class="text-center text-inverse text-opacity-50">
						Already have an account? <a href="login.php">Sign in</a>.
					</div>
				</form>
			</div>
		</div>
		<!-- END login -->
<?php
//include __DIR__ . "/../files/themepanel.php";
include __DIR__ . "/../files/scripts.php";
?>	
