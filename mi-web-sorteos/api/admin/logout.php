<?php
require_once __DIR__ . '/../lib/store.php';
handleOptions();
startAdminSession();
session_destroy();
jsonResponse(['ok' => true]);
