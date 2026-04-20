<?php
session_start();
include "files/config.php";
$username = isset($_SESSION['username']) ? $_SESSION['username'] : "Please Login";
include "files/header.php";
include "files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">Customs House Trading</h1>
			<p>
			Start build your page here
			</p>
		</div>
		<!-- END #content -->
<?php
//include "files/themepanel.php";
include "files/scripts.php";
?>	

