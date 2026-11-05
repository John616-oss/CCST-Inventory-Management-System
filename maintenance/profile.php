<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['maintenance']);
$userId = (int)current_user()['id'];
$profileQuery = $conn->prepare("SELECT full_name,email,password FROM users WHERE id=? AND role='maintenance'");
$profileQuery->bind_param('i', $userId);
$profileQuery->execute();
$profile = $profileQuery->get_result()->fetch_assoc();
if (!$profile) {
    $_SESSION = [];
    session_destroy();
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        if ($fullName === '') {
            flash('Enter your full name.', 'error');
        } else {
            $update = $conn->prepare("UPDATE users SET full_name=? WHERE id=? AND role='maintenance'");
            $update->bind_param('si', $fullName, $userId);
            $update->execute();
            $_SESSION['user']['full_name'] = $fullName;
            log_action('UPDATE MAINTENANCE PROFILE', 'Updated profile name');
            flash('Profile updated.');
        }
        redirect('maintenance/profile.php');
    }
    if ($action === 'password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        if (!password_verify($currentPassword, $profile['password'])) {
            flash('Current password is incorrect.', 'error');
        } elseif (strlen($newPassword) < 10) {
            flash('New password must be at least 10 characters.', 'error');
        } elseif ($newPassword !== $confirmPassword) {
            flash('New password confirmation does not match.', 'error');
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password=? WHERE id=? AND role='maintenance'");
            $update->bind_param('si', $passwordHash, $userId);
            $update->execute();
            session_regenerate_id(true);
            log_action('CHANGE MAINTENANCE PASSWORD', 'Password changed');
            flash('Password changed successfully.');
        }
        redirect('maintenance/profile.php');
    }
    flash('Invalid profile action.', 'error');
    redirect('maintenance/profile.php');
}
include __DIR__ . '/../includes/header.php';
?>
<div class="maintenance-profile-page">
    <header class="dashboard-titlebar">
        <div>
            <p class="dashboard-eyebrow">ACCOUNT SETTINGS</p>
            <h1>My Profile</h1>
            <p class="dashboard-subtitle">Manage your Maintenance account details.</p>
        </div>
    </header>
    <div class="maintenance-profile-grid">
        <section class="panel">
            <div class="profile-section-heading"><span class="profile-icon"><i class="bi bi-person-circle" aria-hidden="true"></i></span>
                <div>
                    <h2>Profile information</h2>
                    <p>Your account identity</p>
                </div>
            </div>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="profile">
                <div class="mb-3"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" value="<?= e($profile['full_name']) ?>" autocomplete="name" required></div>
                <div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" id="email" value="<?= e($profile['email']) ?>" readonly>
                    <div class="form-text">Contact an administrator if this email needs to change.</div>
                </div><button class="btn btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save profile</button>
            </form>
        </section>
        <section class="panel">
            <div class="profile-section-heading"><span class="profile-icon profile-icon-lock"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                <div>
                    <h2>Change password</h2>
                    <p>Use at least 10 characters</p>
                </div>
            </div>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="password">
                <div class="mb-3"><label class="form-label" for="current_password">Current password</label><input class="form-control" id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
                <div class="mb-3"><label class="form-label" for="new_password">New password</label><input class="form-control" id="new_password" name="new_password" type="password" minlength="10" autocomplete="new-password" required></div>
                <div class="mb-3"><label class="form-label" for="confirm_password">Confirm new password</label><input class="form-control" id="confirm_password" name="confirm_password" type="password" minlength="10" autocomplete="new-password" required></div><button class="btn btn-primary" type="submit"><i class="bi bi-key" aria-hidden="true"></i> Update password</button>
            </form>
        </section>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>