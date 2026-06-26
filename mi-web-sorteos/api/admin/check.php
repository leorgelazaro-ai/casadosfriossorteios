<?php
require_once __DIR__ . '/../lib/store.php';
handleOptions();
startAdminSession();
jsonResponse(['logged_in' => !empty($_SESSION['admin_logged_in'])]);
