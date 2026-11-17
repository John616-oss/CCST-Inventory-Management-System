<?php
require_once __DIR__ . '/functions.php';
require_role(['staff']);
flash('Borrowing features are disabled. Existing borrowing history has been preserved.', 'error');
redirect(role_home());
