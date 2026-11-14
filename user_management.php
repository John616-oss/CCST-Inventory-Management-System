<?php
require_once __DIR__ . '/includes/functions.php';
require_role(['admin']);
$admin = current_user();
$adminId = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role = $_POST['role'] ?? '';
        $password = $_POST['password'] ?? '';
        if ($fullName === '' || strlen($fullName) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || !in_array($role, ['staff', 'maintenance'], true) || strlen($password) < 10 || strlen($password) > 255) {
            flash('Check the name, email, role, and password. Passwords must be at least 10 characters.', 'error');
        } else {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $insert = $conn->prepare("INSERT INTO users(full_name,email,password,role,is_verified,status) VALUES(?,?,?,?,1,'active')");
                $insert->bind_param('ssss', $fullName, $email, $passwordHash, $role);
                $insert->execute();
                log_action('CREATE USER', $email . ' · ' . ucfirst($role));
                flash('Account created and ready to sign in.');
            } catch (mysqli_sql_exception $error) {
                flash($error->getCode() === 1062 ? 'That email address is already registered.' : 'Could not create the account.', 'error');
            }
        }
        redirect('user_management.php');
    }

    $userId = (int) ($_POST['user_id'] ?? 0);
    if ($userId < 1) {
        flash('Select a valid account.', 'error');
        redirect('user_management.php');
    }

    if ($action === 'update') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role = $_POST['role'] ?? '';
        $status = $_POST['status'] ?? '';
        if ($userId === $adminId) {
            flash('Use Profile to update your own Admin account.', 'error');
        } elseif ($fullName === '' || strlen($fullName) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || !in_array($role, ['admin', 'staff', 'maintenance'], true) || !in_array($status, ['active', 'inactive'], true)) {
            flash('Check the account name, email, role, and status.', 'error');
        } else {
            try {
                $update = $conn->prepare('UPDATE users SET full_name=?,email=?,role=?,status=? WHERE id=? AND id<>?');
                $update->bind_param('ssssii', $fullName, $email, $role, $status, $userId, $adminId);
                $update->execute();
                if (!$update->affected_rows) {
                    $exists = $conn->prepare('SELECT id FROM users WHERE id=? AND id<>?');
                    $exists->bind_param('ii', $userId, $adminId);
                    $exists->execute();
                    if (!$exists->get_result()->num_rows) {
                        flash('Account not found.', 'error');
                        redirect('user_management.php');
                    }
                }
                log_action('UPDATE USER', 'User #' . $userId . ' · ' . $role . ' · ' . $status);
                flash('Account updated.');
            } catch (mysqli_sql_exception $error) {
                flash($error->getCode() === 1062 ? 'That email address is already registered.' : 'Could not update the account.', 'error');
            }
        }
        redirect('user_management.php');
    }

    if ($action === 'password') {
        $password = $_POST['new_password'] ?? '';
        if ($userId === $adminId) {
            flash('Use Profile to change your own Admin password.', 'error');
        } elseif (strlen($password) < 10 || strlen($password) > 255) {
            flash('Use a password between 10 and 255 characters.', 'error');
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $update = $conn->prepare('UPDATE users SET password=? WHERE id=? AND id<>?');
            $update->bind_param('sii', $passwordHash, $userId, $adminId);
            $update->execute();
            if ($update->affected_rows) {
                log_action('RESET USER PASSWORD', 'User #' . $userId);
                flash('Password reset successfully.');
            } else {
                flash('Account not found.', 'error');
            }
        }
        redirect('user_management.php');
    }

    flash('Invalid user management action.', 'error');
    redirect('user_management.php');
}

$search = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';
if (!in_array($roleFilter, ['', 'admin', 'staff', 'maintenance'], true)) {
    $roleFilter = '';
}
if (!in_array($statusFilter, ['', 'active', 'inactive'], true)) {
    $statusFilter = '';
}
$conditions = [];
$types = '';
$values = [];
if ($search !== '') {
    $conditions[] = '(full_name LIKE ? OR email LIKE ?)';
    $pattern = '%' . $search . '%';
    $types .= 'ss';
    $values[] = $pattern;
    $values[] = $pattern;
}
if ($roleFilter !== '') {
    $conditions[] = 'role = ?';
    $types .= 's';
    $values[] = $roleFilter;
}
if ($statusFilter !== '') {
    $conditions[] = 'status = ?';
    $types .= 's';
    $values[] = $statusFilter;
}
$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$usersQuery = $conn->prepare('SELECT id,full_name,email,role,status,is_verified,created_at FROM users' . $whereSql . ' ORDER BY FIELD(role,\'admin\',\'staff\',\'maintenance\'),full_name LIMIT 300');
if ($values) {
    stmt_bind($usersQuery, $types, $values);
}
$usersQuery->execute();
$users = $usersQuery->get_result();
$counts = $conn->query("SELECT COUNT(*) AS total, SUM(role='staff' AND status='active') AS staff, SUM(role='maintenance' AND status='active') AS maintenance, SUM(status='inactive') AS inactive FROM users")->fetch_assoc();
include __DIR__ . '/includes/header.php';
?>
<div class="admin-dashboard admin-users-page">
    <header class="admin-dashboard-intro">
        <div>
            <p class="dashboard-eyebrow">SYSTEM ADMINISTRATION</p>
            <h1>User Management</h1>
            <p class="dashboard-subtitle">Manage Staff and Maintenance accounts, access, and credentials.</p>
        </div>
    </header>

    <section class="admin-metric-grid admin-user-metrics" aria-label="User summary">
        <article class="admin-metric metric-blue"><div class="admin-metric-icon"><i class="bi bi-people-fill" aria-hidden="true"></i></div><div><strong><?= number_format((int) $counts['total']) ?></strong><span>Registered users</span></div></article>
        <article class="admin-metric metric-green"><div class="admin-metric-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></div><div><strong><?= number_format((int) $counts['staff']) ?></strong><span>Active Staff</span></div></article>
        <article class="admin-metric metric-amber"><div class="admin-metric-icon"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i></div><div><strong><?= number_format((int) $counts['maintenance']) ?></strong><span>Active Maintenance</span></div></article>
        <article class="admin-metric metric-red"><div class="admin-metric-icon"><i class="bi bi-person-slash" aria-hidden="true"></i></div><div><strong><?= number_format((int) $counts['inactive']) ?></strong><span>Inactive accounts</span></div></article>
    </section>

    <section class="admin-users-layout">
        <article class="admin-panel admin-user-create-panel">
            <div class="admin-panel-heading"><div><h2>Add account</h2><p>Only Staff and Maintenance roles can be created here.</p></div></div>
            <form method="post" class="admin-user-create-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">
                <label>Full name<input class="form-control" name="full_name" maxlength="150" autocomplete="name" required></label>
                <label>Email address<input class="form-control" name="email" type="email" maxlength="190" autocomplete="email" required></label>
                <label>Role<select class="form-select" name="role" required><option value="staff">Staff</option><option value="maintenance">Maintenance</option></select></label>
                <label>Temporary password<input class="form-control" name="password" type="password" minlength="10" maxlength="255" autocomplete="new-password" required><small>At least 10 characters</small></label>
                <button class="btn btn-primary" type="submit"><i class="bi bi-person-plus" aria-hidden="true"></i> Create account</button>
            </form>
        </article>

        <article class="admin-panel admin-user-list-panel">
            <div class="admin-panel-heading"><div><h2>Registered users</h2><p><?= number_format($users->num_rows) ?> shown, up to 300</p></div></div>
            <form class="admin-user-filters" method="get" role="search">
                <label class="admin-user-search"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search users</span><input class="form-control" name="q" value="<?= e($search) ?>" placeholder="Search name or email"></label>
                <label><span class="visually-hidden">Filter role</span><select class="form-select" name="role" aria-label="Filter by role"><option value="">All roles</option><?php foreach (['admin','staff','maintenance'] as $role): ?><option value="<?= e($role) ?>" <?= $roleFilter === $role ? 'selected' : '' ?>><?= e(ucfirst($role)) ?></option><?php endforeach; ?></select></label>
                <label><span class="visually-hidden">Filter status</span><select class="form-select" name="status" aria-label="Filter by account status"><option value="">All statuses</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
                <button class="btn btn-primary" type="submit">Filter</button>
                <?php if ($search !== '' || $roleFilter !== '' || $statusFilter !== ''): ?><a class="btn btn-outline-secondary" href="user_management.php" aria-label="Clear user filters"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></a><?php endif; ?>
            </form>
            <div class="table-responsive">
                <table class="table table-hover align-middle admin-user-table">
                    <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Email verified</th><th>Created</th><th>Manage</th></tr></thead>
                    <tbody>
                        <?php if ($users->num_rows): ?>
                            <?php while ($account = $users->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?= e($account['full_name']) ?></strong><small class="d-block text-secondary"><?= e($account['email']) ?></small></td>
                                    <td><?= e(ucfirst($account['role'])) ?></td>
                                    <td><span class="user-account-status status-<?= e($account['status'] ?? 'active') ?>"><?= e(ucfirst($account['status'] ?? 'active')) ?></span></td>
                                    <td><?= (int) $account['is_verified'] ? 'Verified' : 'Not verified' ?></td>
                                    <td><?= e(date('M j, Y', strtotime($account['created_at']))) ?></td>
                                    <td>
                                        <?php if ((int) $account['id'] !== $adminId): ?>
                                            <details class="admin-user-manage">
                                                <summary class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square" aria-hidden="true"></i> Manage</summary>
                                                <div class="admin-user-manage-menu">
                                                    <form method="post">
                                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                                        <label>Full name<input class="form-control form-control-sm" name="full_name" value="<?= e($account['full_name']) ?>" maxlength="150" required></label>
                                                        <label>Email<input class="form-control form-control-sm" name="email" type="email" value="<?= e($account['email']) ?>" maxlength="190" required></label>
                                                        <label>Role<select class="form-select form-select-sm" name="role"><?php if ($account['role'] === 'admin'): ?><option value="admin" selected>Admin</option><?php else: ?><option value="staff" <?= $account['role'] === 'staff' ? 'selected' : '' ?>>Staff</option><option value="maintenance" <?= $account['role'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option><?php endif; ?></select></label>
                                                        <label>Status<select class="form-select form-select-sm" name="status"><option value="active" <?= ($account['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= ($account['status'] ?? 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
                                                        <button class="btn btn-sm btn-primary" type="submit">Save account</button>
                                                    </form>
                                                    <form method="post" class="admin-user-password-form">
                                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="password"><input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                                        <label>Reset password<input class="form-control form-control-sm" name="new_password" type="password" minlength="10" maxlength="255" autocomplete="new-password" placeholder="New password" required></label>
                                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Reset password</button>
                                                    </form>
                                                </div>
                                            </details>
                                        <?php else: ?>
                                            <span class="text-secondary" title="Manage your account through Profile">Self-managed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-5"><strong>No users found.</strong></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
