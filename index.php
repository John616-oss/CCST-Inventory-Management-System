<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
redirect(role_home());
