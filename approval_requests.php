<?php
require_once __DIR__ . '/includes/functions.php';
require_role(['admin']);
flash('Borrowing features are disabled. Existing borrowing history has been preserved.', 'error');
redirect(role_home());
