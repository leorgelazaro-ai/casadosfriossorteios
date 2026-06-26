<?php
require_once __DIR__ . '/../lib/store.php';
handleOptions();
requireAdmin();
jsonResponse(getUsuariosAdmin());
