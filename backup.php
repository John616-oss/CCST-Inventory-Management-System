<?php
require_once __DIR__ . '/includes/functions.php';
require_role(['admin']);
ensure_system_backups_table($conn);
$adminId = (int) current_user()['id'];
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: realpath(__DIR__);
$backupDirectory = dirname($documentRoot) . DIRECTORY_SEPARATOR . 'ccst_inventory_backups';
$historyQuery = $conn->query('SELECT b.id,b.file_name,b.file_size,b.created_at,b.restored_at,creator.full_name AS creator_name,restorer.full_name AS restorer_name FROM system_backups b LEFT JOIN users creator ON creator.id=b.created_by LEFT JOIN users restorer ON restorer.id=b.restored_by ORDER BY b.id DESC LIMIT 100');

if (isset($_GET['download'])) {
    $backupId = filter_var($_GET['download'], FILTER_VALIDATE_INT);
    if (!$backupId || !preg_match('/^ccst-[0-9]{8}-[0-9]{6}-[a-f0-9]{16}\.json\.gz$/', (string) ($_GET['file'] ?? ''))) {
        http_response_code(404);
        exit('Backup not found.');
    }
    $downloadQuery = $conn->prepare('SELECT file_name FROM system_backups WHERE id=?');
    $downloadQuery->bind_param('i', $backupId);
    $downloadQuery->execute();
    $backup = $downloadQuery->get_result()->fetch_assoc();
    if (!$backup || !hash_equals($backup['file_name'], $_GET['file'])) {
        http_response_code(404);
        exit('Backup not found.');
    }
    $path = $backupDirectory . DIRECTORY_SEPARATOR . $backup['file_name'];
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit('Backup file is missing.');
    }
    header('Content-Type: application/gzip');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename($backup['file_name']) . '"');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            if (!extension_loaded('zlib')) {
                throw new RuntimeException('PHP zlib support is required for compressed backups.');
            }
            if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
                throw new RuntimeException('The private backup directory could not be created.');
            }
            $realBackupDirectory = realpath($backupDirectory);
            $realDocumentRoot = realpath($documentRoot);
            if (!$realBackupDirectory || !$realDocumentRoot || str_starts_with(strtolower($realBackupDirectory . DIRECTORY_SEPARATOR), strtolower($realDocumentRoot . DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('The backup directory must be outside the public web root.');
            }

            $tableResult = $conn->query('SHOW FULL TABLES');
            $tables = [];
            while ($tableRow = $tableResult->fetch_row()) {
                if (($tableRow[1] ?? '') !== 'BASE TABLE') {
                    continue;
                }
                $tableName = (string) $tableRow[0];
                if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
                    continue;
                }
                $columnResult = $conn->query('SHOW COLUMNS FROM `' . $tableName . '`');
                $columns = [];
                while ($column = $columnResult->fetch_assoc()) {
                    $columns[] = $column['Field'];
                }
                $rows = [];
                $dataResult = $conn->query('SELECT * FROM `' . $tableName . '`');
                while ($row = $dataResult->fetch_assoc()) {
                    $rows[] = $row;
                }
                $tables[$tableName] = ['columns' => $columns, 'rows' => $rows];
            }

            $payload = [
                'format' => 'ccst-db-backup-v1',
                'database' => DB_NAME,
                'created_at' => date(DATE_ATOM),
                'tables' => $tables,
            ];
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $compressed = gzencode($json, 6);
            if ($compressed === false) {
                throw new RuntimeException('Could not compress the database snapshot.');
            }
            $fileName = 'ccst-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.json.gz';
            $finalPath = $realBackupDirectory . DIRECTORY_SEPARATOR . $fileName;
            $tempPath = $finalPath . '.tmp';
            if (file_put_contents($tempPath, $compressed, LOCK_EX) === false || !rename($tempPath, $finalPath)) {
                @unlink($tempPath);
                throw new RuntimeException('Could not write the private database backup.');
            }
            @chmod($finalPath, 0600);
            $fileSize = strlen($compressed);
            $insert = $conn->prepare('INSERT INTO system_backups(file_name,file_size,created_by) VALUES(?,?,?)');
            $insert->bind_param('sii', $fileName, $fileSize, $adminId);
            $insert->execute();
            log_action('CREATE DATABASE BACKUP', $fileName . ' · ' . $fileSize . ' bytes');
            flash('Database backup created. Store the downloaded file securely.');
        } elseif ($action === 'restore') {
            $backupId = (int) ($_POST['backup_id'] ?? 0);
            $confirmation = trim($_POST['confirmation'] ?? '');
            if ($backupId < 1 || $confirmation !== 'RESTORE DATABASE') {
                throw new DomainException('Enter RESTORE DATABASE exactly to confirm the restore.');
            }
            $backupQuery = $conn->prepare('SELECT file_name FROM system_backups WHERE id=?');
            $backupQuery->bind_param('i', $backupId);
            $backupQuery->execute();
            $backup = $backupQuery->get_result()->fetch_assoc();
            if (!$backup || !preg_match('/^ccst-[0-9]{8}-[0-9]{6}-[a-f0-9]{16}\.json\.gz$/', $backup['file_name'])) {
                throw new DomainException('Select a valid stored backup.');
            }
            $backupPath = $backupDirectory . DIRECTORY_SEPARATOR . $backup['file_name'];
            if (!is_file($backupPath) || filesize($backupPath) > 250 * 1024 * 1024) {
                throw new DomainException('The backup file is missing or exceeds the restore size limit.');
            }
            $json = gzdecode((string) file_get_contents($backupPath));
            if ($json === false) {
                throw new DomainException('The backup file is not a valid compressed snapshot.');
            }
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (($payload['format'] ?? '') !== 'ccst-db-backup-v1' || ($payload['database'] ?? '') !== DB_NAME || !is_array($payload['tables'] ?? null)) {
                throw new DomainException('The backup format or database name does not match this system.');
            }

            $liveTables = [];
            $tableResult = $conn->query('SHOW FULL TABLES');
            while ($tableRow = $tableResult->fetch_row()) {
                if (($tableRow[1] ?? '') === 'BASE TABLE') {
                    $liveTables[(string) $tableRow[0]] = true;
                }
            }
            foreach ($payload['tables'] as $tableName => $tableData) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $tableName) || !isset($liveTables[$tableName]) || !is_array($tableData) || !is_array($tableData['columns'] ?? null) || !is_array($tableData['rows'] ?? null)) {
                    throw new DomainException('The backup contains an invalid table structure.');
                }
                $columnResult = $conn->query('SHOW COLUMNS FROM `' . $tableName . '`');
                $liveColumns = [];
                while ($column = $columnResult->fetch_assoc()) {
                    $liveColumns[$column['Field']] = true;
                }
                foreach ($tableData['columns'] as $columnName) {
                    if (!is_string($columnName) || !isset($liveColumns[$columnName])) {
                        throw new DomainException('The backup schema is not compatible with this database.');
                    }
                }
                foreach ($tableData['rows'] as $row) {
                    if (!is_array($row) || array_diff(array_keys($row), $tableData['columns'])) {
                        throw new DomainException('A backup row does not match its table schema.');
                    }
                }
            }

            $conn->query('SET FOREIGN_KEY_CHECKS=0');
            try {
                $conn->begin_transaction();
                foreach (array_keys($payload['tables']) as $tableName) {
                    if ($tableName !== 'system_backups') {
                        $conn->query('DELETE FROM `' . $tableName . '`');
                    }
                }
                foreach ($payload['tables'] as $tableName => $tableData) {
                    if ($tableName === 'system_backups' || !$tableData['columns']) {
                        continue;
                    }
                    $columnsSql = implode(',', array_map(static fn (string $column): string => '`' . $column . '`', $tableData['columns']));
                    $placeholders = implode(',', array_fill(0, count($tableData['columns']), '?'));
                    $insert = $conn->prepare('INSERT INTO `' . $tableName . '` (' . $columnsSql . ') VALUES (' . $placeholders . ')');
                    $bindTypes = str_repeat('s', count($tableData['columns']));
                    foreach ($tableData['rows'] as $row) {
                        $values = [];
                        foreach ($tableData['columns'] as $column) {
                            $values[] = $row[$column] ?? null;
                        }
                        stmt_bind($insert, $bindTypes, $values);
                        $insert->execute();
                    }
                }
                $conn->commit();
            } catch (Throwable $restoreError) {
                $conn->rollback();
                throw $restoreError;
            } finally {
                $conn->query('SET FOREIGN_KEY_CHECKS=1');
            }
            $restoreLog = $conn->prepare('UPDATE system_backups SET restored_by=?,restored_at=NOW() WHERE id=?');
            $restoreLog->bind_param('ii', $adminId, $backupId);
            $restoreLog->execute();
            log_action('RESTORE DATABASE BACKUP', $backup['file_name']);
            flash('Database restored from the selected snapshot.');
        } else {
            throw new DomainException('Invalid backup action.');
        }
    } catch (DomainException $error) {
        flash($error->getMessage(), 'error');
    } catch (Throwable $error) {
        error_log('Database backup operation failed: ' . $error->getMessage());
        flash('The backup operation failed. Check the server error log.', 'error');
    }
    redirect('backup.php');
}

$historyQuery = $conn->query('SELECT b.id,b.file_name,b.file_size,b.created_at,b.restored_at,creator.full_name AS creator_name,restorer.full_name AS restorer_name FROM system_backups b LEFT JOIN users creator ON creator.id=b.created_by LEFT JOIN users restorer ON restorer.id=b.restored_by ORDER BY b.id DESC LIMIT 100');
include __DIR__ . '/includes/header.php';
?>
<div class="admin-dashboard admin-backup-page">
    <header class="admin-dashboard-intro">
        <div>
            <p class="dashboard-eyebrow">SYSTEM PROTECTION</p>
            <h1>Backup &amp; Restore</h1>
            <p class="dashboard-subtitle">Create private database snapshots and restore a verified snapshot when needed.</p>
        </div>
    </header>

    <section class="admin-backup-actions">
        <article class="admin-panel">
            <div class="admin-panel-heading"><div><h2>Create a backup</h2><p>Compressed JSON snapshot stored outside the public web root.</p></div><i class="bi bi-database-down" aria-hidden="true"></i></div>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create"><button class="btn btn-primary" type="submit"><i class="bi bi-cloud-arrow-down" aria-hidden="true"></i> Create database backup</button></form>
        </article>
        <aside class="admin-backup-warning"><i class="bi bi-shield-exclamation" aria-hidden="true"></i><p><strong>Restore replaces database records.</strong> Only snapshots created by this system can be restored. Keep a separate copy of downloaded backups.</p></aside>
    </section>

    <section class="admin-panel admin-backup-history-panel">
        <div class="admin-panel-heading"><div><h2>Backup history</h2><p><?= number_format($historyQuery->num_rows) ?> snapshots shown, up to 100</p></div></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle admin-backup-table">
                <thead><tr><th>Backup file</th><th>Created</th><th>Admin</th><th>Size</th><th>Last restored</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if ($historyQuery->num_rows): ?>
                        <?php while ($backup = $historyQuery->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= e($backup['file_name']) ?></strong></td>
                                <td><?= e(date('M j, Y g:i A', strtotime($backup['created_at']))) ?></td>
                                <td><?= e($backup['creator_name'] ?? 'Deleted user') ?></td>
                                <td><?= e(number_format((int) $backup['file_size'] / 1024, 1)) ?> KB</td>
                                <td><?= $backup['restored_at'] ? e(date('M j, Y g:i A', strtotime($backup['restored_at'])) . ' · ' . ($backup['restorer_name'] ?? 'Admin')) : '—' ?></td>
                                <td class="admin-backup-actions-cell">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/backup.php?download=<?= (int) $backup['id'] ?>&amp;file=<?= e(rawurlencode($backup['file_name'])) ?>"><i class="bi bi-download" aria-hidden="true"></i> Download</a>
                                    <details class="admin-restore-details">
                                        <summary class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore</summary>
                                        <form method="post" onsubmit="return confirm('This will replace current database records. Continue only if you have a recent backup.');">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="backup_id" value="<?= (int) $backup['id'] ?>">
                                            <label>Type RESTORE DATABASE to confirm<input class="form-control form-control-sm" name="confirmation" autocomplete="off" required></label>
                                            <button class="btn btn-sm btn-danger" type="submit">Confirm restore</button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-5"><strong>No backups have been created.</strong></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
