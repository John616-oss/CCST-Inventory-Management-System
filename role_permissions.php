<?php
require_once __DIR__ . '/includes/functions.php';
require_role(['admin']);
$roles = [
    'admin' => [
        'Manage user accounts and role assignment',
        'Add and update inventory and QR labels',
        'Approve or reject borrowing requests',
        'Review maintenance, reports, and activity logs',
        'Configure system settings and backups',
    ],
    'staff' => [
        'View inventory and scan equipment QR codes',
        'Submit and review borrowing requests',
        'Release approved equipment and process returns',
        'Report damaged equipment and view transactions',
        'Cannot approve or reject borrowing requests',
    ],
    'maintenance' => [
        'View active repair work and scan equipment QR codes',
        'Start and complete repair tasks',
        'Record repair descriptions and replaced parts',
        'Update own profile and password',
        'Cannot manage inventory, users, or borrowing approvals',
    ],
];
include __DIR__ . '/includes/header.php';
?>
<div class="admin-dashboard admin-permissions-page">
    <header class="admin-dashboard-intro">
        <div>
            <p class="dashboard-eyebrow">SYSTEM ADMINISTRATION</p>
            <h1>Role &amp; Permissions</h1>
            <p class="dashboard-subtitle">Review the access enforced by the system's server-side role checks.</p>
        </div>
        <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/user_management.php"><i class="bi bi-people" aria-hidden="true"></i> Manage users</a>
    </header>

    <section class="admin-role-grid" aria-label="Role permissions">
        <?php foreach ($roles as $role => $permissions): ?>
            <article class="admin-panel admin-role-card role-<?= e($role) ?>">
                <div class="admin-panel-heading">
                    <div>
                        <p class="admin-role-eyebrow">SYSTEM ROLE</p>
                        <h2><?= e(ucfirst($role)) ?></h2>
                    </div>
                    <span class="admin-role-icon"><i class="bi <?= $role === 'admin' ? 'bi-shield-lock' : ($role === 'staff' ? 'bi-person-badge' : 'bi-wrench-adjustable') ?>" aria-hidden="true"></i></span>
                </div>
                <ul>
                    <?php foreach ($permissions as $permission): ?>
                        <li><i class="bi bi-check2" aria-hidden="true"></i><span><?= e($permission) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </article>
        <?php endforeach; ?>
    </section>
    <p class="admin-permissions-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Permissions are fixed by route-level <code>require_role()</code> checks; this page does not grant ad-hoc capabilities.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
