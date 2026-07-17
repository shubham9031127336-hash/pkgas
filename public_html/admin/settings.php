<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/db.php';
require_login();
$page_title = __('settings.title');
$active_menu = "settings";

// Handle real DB backup — MUST be before layout.php to send headers before any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'db_backup') {
    validateCsrfToken();
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename=prem_gas_solution_backup_' . date('Ymd_His') . '.sql');

    echo "-- PREM GAS SOLUTION DATABASE BACKUP\n";
    echo "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Host: $db_host\n";
    echo "-- Database: $db_name\n\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "SET AUTOCOMMIT = 0;\n";
    echo "START TRANSACTION;\n";
    echo "SET time_zone = \"+00:00\";\n\n";
    echo "CREATE DATABASE IF NOT EXISTS `$db_name`;\n";
    echo "USE `$db_name`;\n\n";

    try {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            echo "--\n-- Table structure for table `$table`\n--\n\n";
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch();
            echo $row['Create Table'] . ";\n\n";

            $rowCount = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            if ($rowCount == 0) continue;

            echo "--\n-- Dumping data for table `$table` ($rowCount rows)\n--\n\n";

            $colStmt = $pdo->query("DESCRIBE `$table`");
            $cols = [];
            while ($c = $colStmt->fetch()) {
                $cols[] = '`' . $c['Field'] . '`';
            }
            $colList = implode(', ', $cols);

            $dataStmt = $pdo->query("SELECT * FROM `$table`");
            $r = 0;
            while ($data = $dataStmt->fetch(PDO::FETCH_NUM)) {
                if ($r % 200 == 0) {
                    if ($r > 0) echo ";\n";
                    echo "INSERT INTO `$table` ($colList) VALUES\n";
                } else {
                    echo ",\n";
                }
                $vals = [];
                foreach ($data as $val) {
                    $vals[] = ($val === null) ? 'NULL' : $pdo->quote($val);
                }
                echo '(' . implode(', ', $vals) . ')';
                $r++;
            }
            if ($r > 0) echo ";\n\n";
        }

        echo "COMMIT;\n";
        echo "--\n-- Backup completed — " . count($tables) . " tables, " . date('Y-m-d H:i:s') . "\n--\n";
    } catch (Exception $e) {
        echo "--\n-- BACKUP ERROR: " . $e->getMessage() . "\n--\n";
    }
    exit();
}

require_once __DIR__ . '/layout.php';
require_role('super_admin');

require_once __DIR__ . '/inventory-utils.php';

$message = '';
$error = '';

// Handle DB Sync request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'db_sync') {
    $ok = syncInventory($pdo);
    if ($ok) {
        $message = __('settings.stock_synced');
    } else {
        $error = __('settings.sync_failed');
    }
}

// Handle simulated roles changes
$simulated_role = 'Administrator';
if (isset($_POST['action']) && $_POST['action'] === 'change_role') {
    $new_role = $_POST['role_selection'] ?? 'Administrator';
    $simulated_role = $new_role;
    $message = __('settings.role_switched');
}

// Count tables for status indicators
$tables_count = 0;
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables_count = count($stmt->fetchAll());
} catch (PDOException $e) {}
?>
<div style="margin-bottom: 2rem;">
    <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;"><?php echo __('settings.heading'); ?></h2>
    <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;"><?php echo __('settings.subtitle'); ?></p>
</div>

<?php if ($message): ?>
    <div class="alert-banner" style="background: var(--success-soft); color: var(--success); border-color: #a7f3d0;">
        <strong>Success:</strong> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert-banner" style="background: var(--danger-soft); color: var(--danger); border-color: #fca5a5;">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="split-layout">
    <!-- Left Column: Database Status and manual sync -->
    <div class="admin-card" style="margin: 0;">
        <h3 class="card-title"><?php echo __('settings.db_status'); ?></h3>
        <p style="color: var(--admin-muted); font-size: 0.85rem; margin-top: 0.25rem; margin-bottom: 1.5rem;">
            <?php echo __('settings.db_desc'); ?>
        </p>
        
        <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: #fafafb; border: 1px solid var(--admin-border); border-radius: 10px;">
                <span style="font-weight: 700; font-size: 0.9rem;"><?php echo __('settings.connection_status'); ?></span>
                <span class="badge badge-filled" style="font-size: 0.7rem; padding: 4px 10px;"><?php echo __('settings.connected'); ?></span>
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: #fafafb; border: 1px solid var(--admin-border); border-radius: 10px;">
                <span style="font-weight: 700; font-size: 0.9rem;"><?php echo __('settings.tables_count'); ?></span>
                <strong style="color: var(--admin-accent);"><?php echo $tables_count; ?> <?php echo __('settings.active_tables'); ?></strong>
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: #fafafb; border: 1px solid var(--admin-border); border-radius: 10px;">
                <span style="font-weight: 700; font-size: 0.9rem;"><?php echo __('settings.stock_status'); ?></span>
                <span class="badge badge-rental" style="font-size: 0.7rem; padding: 4px 10px;"><?php echo __('settings.synchronized'); ?></span>
            </div>
        </div>
        
        <div style="display: flex; gap: 1rem;">
            <form method="POST" style="flex: 1;"><?php csrfField(); ?>
                <input type="hidden" name="action" value="db_sync">
                <button type="submit" class="btn-secondary" style="width: 100%; justify-content: center; height: 42px; border-radius: 8px;">
                    &#x1f504; <?php echo __('settings.recalculate'); ?>
                </button>
            </form>
            
            <form method="POST" style="flex: 1;"><?php csrfField(); ?>
                <input type="hidden" name="action" value="db_backup">
                <button type="submit" class="btn-primary" style="width: 100%; justify-content: center; height: 42px; border-radius: 8px;">
                    &#x1f4e5; <?php echo __('settings.download_backup'); ?>
                </button>
            </form>
        </div>
        
        <hr style="border: 0; border-top: 1px solid var(--admin-border); margin: 1.5rem 0;">

        <!-- Cylinder Audit Log Link -->
        <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 0.75rem;">Cylinder Tracking</h4>
        <a href="cylinder-audit-log.php" class="btn-secondary" style="width: 100%; justify-content: center; height: 42px; border-radius: 8px; text-decoration: none; display: flex; align-items: center; gap: 8px;">
            View Complete Cylinder Audit Log
        </a>
        <p style="font-size: 0.75rem; color: var(--admin-muted); margin-top: 0.5rem; text-align: center;">
            Searchable history of every cylinder movement, exchange, and settlement event.
        </p>
        
        <hr style="border: 0; border-top: 1px solid var(--admin-border); margin: 2rem 0;">
        
        <!-- Simulate restore box -->
        <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 0.75rem;"><?php echo __('settings.simulate_restore'); ?></h4>
        <div style="padding: 1.5rem; background: #fafafb; border: 1px dashed var(--admin-border); border-radius: 12px; text-align: center;">
            <p style="font-size: 0.8rem; color: var(--admin-muted); margin-bottom: 1rem;"><?php echo __('settings.simulate_desc'); ?></p>
            <input type="file" id="simulatedFile" style="font-size: 0.8rem; margin-bottom: 1rem; color: var(--admin-muted);">
            <button type="button" class="btn-secondary" style="padding: 0.5rem 1.5rem; font-size: 0.8rem; margin: 0 auto; border-radius: 6px;" onclick="simulateRestore()">
                <?php echo __('settings.upload_restore'); ?>
            </button>
        </div>
    </div>
    
    <!-- Right Column: Staff role simulation -->
    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
        <!-- Simulations Card -->
        <div class="admin-card" style="margin: 0;">
            <h3 class="card-title"><?php echo __('settings.simulations'); ?></h3>
            <p style="color: var(--admin-muted); font-size: 0.85rem; margin-top: 0.25rem; margin-bottom: 1.5rem;">
                <?php echo __('settings.simulations_desc'); ?>
            </p>
            
            <form method="POST"><?php csrfField(); ?>
                <input type="hidden" name="action" value="change_role">
                
                <div class="form-group">
                    <label class="form-label"><?php echo __('settings.simulate_label'); ?></label>
                    <select name="role_selection" class="form-control" onchange="this.form.submit()">
                        <option value="Administrator" <?php echo $simulated_role === 'Administrator' ? 'selected' : ''; ?>><?php echo __('settings.super_admin'); ?></option>
                        <option value="Warehouse Manager" <?php echo $simulated_role === 'Warehouse Manager' ? 'selected' : ''; ?>><?php echo __('settings.warehouse_manager'); ?></option>
                        <option value="Counter Executive" <?php echo $simulated_role === 'Counter Executive' ? 'selected' : ''; ?>><?php echo __('settings.billing_executive'); ?></option>
                    </select>
                </div>
            </form>
            
            <div style="background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 1.25rem; border-radius: 12px; margin-top: 2rem;">
                <h5 style="font-weight: 700; font-size: 0.9rem; margin-bottom: 0.5rem;">&#x1f6e1; <?php echo __('settings.session_info'); ?></h5>
                <ul style="font-size: 0.8rem; line-height: 1.6; padding-left: 1.25rem; font-weight: 500;">
                    <li><?php echo __('settings.logged_user'); ?> <strong>admin</strong></li>
                    <li><?php echo __('settings.account_email'); ?> <strong>info@pkgas.com</strong></li>
                    <li><?php echo __('settings.simulated_persona'); ?> <strong><?php echo $simulated_role; ?></strong></li>
                    <li><?php echo __('settings.security_check'); ?> <strong>Enforced via auth.php</strong></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
    function simulateRestore() {
        var file = document.getElementById('simulatedFile').value;
        if (!file) {
            alert('<?php echo __('settings.select_backup_first'); ?>');
            return;
        }
        alert('<?php echo __('settings.restore_success'); ?>');
    }
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
