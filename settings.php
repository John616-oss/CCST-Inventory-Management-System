<?php
require_once __DIR__ . '/includes/functions.php';
require_role(['admin']);
ensure_system_settings_table($conn);
$adminId = (int) current_user()['id'];
$categoryRows = $conn->query('SELECT DISTINCT category FROM inventory_items ORDER BY category');
$existingCategories = [];
while ($categoryRow = $categoryRows->fetch_assoc()) {
    $existingCategories[] = $categoryRow['category'];
}
$storedCategories = json_decode(get_system_setting($conn, 'equipment_categories', ''), true);
$categoryText = is_array($storedCategories) ? implode(PHP_EOL, $storedCategories) : implode(PHP_EOL, $existingCategories);

$settings = [
    'school_name' => get_system_setting($conn, 'school_name', 'Clark College of Science and Technology'),
    'system_name' => get_system_setting($conn, 'system_name', APP_NAME),
    'school_email' => get_system_setting($conn, 'school_email', ''),
    'school_phone' => get_system_setting($conn, 'school_phone', ''),
    'low_stock_threshold' => get_system_setting($conn, 'low_stock_threshold', '2'),
    'equipment_categories' => $categoryText,
    'school_logo' => get_system_setting($conn, 'school_logo', 'assets/725698283_122272196210063653_4714758265439461367_n.jpg'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $settings['school_name'] = trim($_POST['school_name'] ?? '');
    $settings['system_name'] = trim($_POST['system_name'] ?? '');
    $settings['school_email'] = trim($_POST['school_email'] ?? '');
    $settings['school_phone'] = trim($_POST['school_phone'] ?? '');
    $categoryLines = preg_split('/[\r\n,]+/', trim($_POST['equipment_categories'] ?? '')) ?: [];
    $categories = array_values(array_unique(array_filter(array_map('trim', $categoryLines), static fn (string $category): bool => $category !== '')));
    $threshold = filter_var($_POST['low_stock_threshold'] ?? null, FILTER_VALIDATE_INT);

    if ($settings['school_name'] === '' || strlen($settings['school_name']) > 150 || $settings['system_name'] === '' || strlen($settings['system_name']) > 150 || ($settings['school_email'] !== '' && !filter_var($settings['school_email'], FILTER_VALIDATE_EMAIL)) || strlen($settings['school_email']) > 190 || strlen($settings['school_phone']) > 40 || count($categories) > 50 || array_filter($categories, static fn (string $category): bool => strlen($category) > 100) || $threshold === false || $threshold < 0 || $threshold > 10000) {
        flash('Check the school details and low-stock threshold.', 'error');
        redirect('settings.php');
    }

    if (!empty($_FILES['school_logo']['name'])) {
        $upload = $_FILES['school_logo'];
        $mime = $upload['error'] === UPLOAD_ERR_OK && $upload['size'] <= 3 * 1024 * 1024 ? (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']) : '';
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $dimensions = $mime !== '' ? @getimagesize($upload['tmp_name']) : false;
        if (!isset($extensions[$mime]) || !$dimensions || $dimensions[0] > 4000 || $dimensions[1] > 4000) {
            flash('Upload a JPG, PNG, or WebP logo up to 3 MB.', 'error');
            redirect('settings.php');
        }
        if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
            flash('The logo upload directory is unavailable.', 'error');
            redirect('settings.php');
        }
        $fileName = 'school-logo-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($upload['tmp_name'], UPLOAD_DIR . $fileName)) {
            flash('Could not save the uploaded logo.', 'error');
            redirect('settings.php');
        }
        $settings['school_logo'] = rtrim(UPLOAD_URL, '/') . '/' . $fileName;
    }

    $settings['equipment_categories'] = json_encode($categories, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $save = $conn->prepare('INSERT INTO system_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
    foreach (['school_name', 'system_name', 'school_email', 'school_phone', 'school_logo', 'equipment_categories'] as $key) {
        $value = $settings[$key];
        $save->bind_param('ssi', $key, $value, $adminId);
        $save->execute();
    }
    $settings['low_stock_threshold'] = (string) $threshold;
    $key = 'low_stock_threshold';
    $save->bind_param('ssi', $key, $settings[$key], $adminId);
    $save->execute();
    log_action('UPDATE SYSTEM SETTINGS', 'Updated school branding, contact details, and low-stock threshold');
    flash('System settings saved.');
    redirect('settings.php');
}

include __DIR__ . '/includes/header.php';
?>
<div class="admin-dashboard admin-settings-page">
    <header class="admin-dashboard-intro"><div><p class="dashboard-eyebrow">SYSTEM CONFIGURATION</p><h1>Settings</h1><p class="dashboard-subtitle">Configure school branding, contact details, and inventory alerts.</p></div></header>
    <section class="admin-panel admin-settings-panel">
        <form method="post" enctype="multipart/form-data" class="admin-settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="admin-settings-brand-row">
                <img src="<?= e(BASE_URL . '/' . ltrim($settings['school_logo'], '/')) ?>" alt="Current school logo">
                <div><strong>School logo</strong><small>JPG, PNG, or WebP · maximum 3 MB</small></div>
                <label class="btn btn-outline-primary">Choose logo<input class="visually-hidden" type="file" name="school_logo" accept="image/jpeg,image/png,image/webp"></label>
            </div>
            <div class="admin-settings-fields">
                <label>School name<input class="form-control" name="school_name" value="<?= e($settings['school_name']) ?>" maxlength="150" required></label>
                <label>System name<input class="form-control" name="system_name" value="<?= e($settings['system_name']) ?>" maxlength="150" required></label>
                <label>Contact email<input class="form-control" name="school_email" type="email" value="<?= e($settings['school_email']) ?>" maxlength="190"></label>
                <label>Contact phone<input class="form-control" name="school_phone" value="<?= e($settings['school_phone']) ?>" maxlength="40"></label>
                <label>Low-stock threshold<input class="form-control" name="low_stock_threshold" type="number" min="0" max="10000" value="<?= e($settings['low_stock_threshold']) ?>" required><small>Active equipment with this many available units or fewer is flagged.</small></label>
                <label>Equipment categories<textarea class="form-control" name="equipment_categories" rows="4" maxlength="5000" placeholder="Computer&#10;Audio-visual&#10;Furniture"><?= e($settings['equipment_categories']) ?></textarea><small>Enter one category per line; existing inventory categories are retained in the list.</small></label>
            </div>
            <div class="admin-settings-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save settings</button></div>
        </form>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>