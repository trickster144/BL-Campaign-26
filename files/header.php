<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
	<meta charset="utf-8">
	<title>Black Legion Clan</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="description" content="">
	<meta name="author" content="">
	
	<!-- ================== BEGIN core-css ================== -->
	<link href="<?= BASE_URL ?>assets/css/vendor.min.css" rel="stylesheet">
	<link href="<?= BASE_URL ?>assets/css/app.min.css" rel="stylesheet">
	<!-- ================== END core-css ================== -->
	
</head>
<body>
	<!-- BEGIN #app -->
	<div id="app" class="app">
		<!-- BEGIN #header -->
		<div id="header" class="app-header">
			<!-- BEGIN desktop-toggler -->
			<div class="desktop-toggler">
				<button type="button" class="menu-toggler" data-toggle-class="app-sidebar-collapsed" data-dismiss-class="app-sidebar-toggled" data-toggle-target=".app">
					<span class="bar"></span>
					<span class="bar"></span>
					<span class="bar"></span>
				</button>
			</div>
			<!-- BEGIN desktop-toggler -->
			
			<!-- BEGIN mobile-toggler -->
			<div class="mobile-toggler">
				<button type="button" class="menu-toggler" data-toggle-class="app-sidebar-mobile-toggled" data-toggle-target=".app">
					<span class="bar"></span>
					<span class="bar"></span>
					<span class="bar"></span>
				</button>
			</div>
			<!-- END mobile-toggler -->

					<!-- BEGIN brand -->
			<div class="brand">
				<a href="#" class="brand-logo">
					<span class="brand-img">
						<span class="brand-img-text text-theme">BL</span>
					</span>
					<span class="brand-text">Black Legion Campaign</span>
				</a>
			</div>
			<!-- END brand -->
			 			<!-- BEGIN menu -->
			<div class="menu">
				<div class="menu-item dropdown dropdown-mobile-full">
					<a href="#" data-bs-toggle="dropdown" data-bs-display="static" class="menu-link">
						<div class="menu-img online">
							<div class="d-flex align-items-center justify-content-center w-100 h-100 bg-inverse bg-opacity-25 text-inverse text-opacity-50 rounded-circle overflow-hidden">
								<i class="bi bi-person-fill fs-32px mb-n3"></i>
							</div>
						</div>
						<div class="menu-text d-sm-block d-none w-170px"><?= htmlspecialchars($username) ?></div>
					</a>
					<div class="dropdown-menu dropdown-menu-end me-lg-3 fs-11px mt-1">
						<?php if (isset($_SESSION['user_id'])): ?>
							<a class="dropdown-item d-flex align-items-center" href="#"><?= htmlspecialchars($_SESSION['account_type']) ?> <i class="bi bi-shield-check ms-auto text-theme fs-16px my-n1"></i></a>
							<div class="dropdown-divider"></div>
							<a class="dropdown-item d-flex align-items-center" href="<?= BASE_URL ?>auth/logout.php">LOGOUT <i class="bi bi-toggle-on ms-auto text-theme fs-16px my-n1"></i></a>
						<?php else: ?>
							<a class="dropdown-item d-flex align-items-center" href="<?= BASE_URL ?>auth/login.php">LOGIN <i class="bi bi-toggle-off ms-auto text-theme fs-16px my-n1"></i></a>
							<a class="dropdown-item d-flex align-items-center" href="<?= BASE_URL ?>auth/register.php">REGISTER <i class="bi bi-person-plus ms-auto text-theme fs-16px my-n1"></i></a>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<!-- END menu -->
		</div>
		<!-- END #header -->