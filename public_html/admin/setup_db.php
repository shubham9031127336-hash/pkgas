<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/db.php';

try {
    echo __('setup_db.starting') . "<br>\n";
    $sql_file = 'db_expand.sql';
    if (!file_exists($sql_file)) {
        die(__('setup_db.not_found'));
    }

    $sql = file_get_contents($sql_file);
    $sql = preg_replace('/--.*\n/', '', $sql);
    $statements = explode(';', $sql);

    $count = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            $pdo->exec($statement);
            $count++;
        }
    }

    echo __('setup_db.completed') . " $count " . __('setup_db.statements') . "<br>\n";
    
    $chk_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($chk_users == 0) {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['admin', password_hash('admin123', PASSWORD_BCRYPT), 'Super Admin', 'super_admin', 'active']);
        $stmt->execute(['clerk', password_hash('clerk123', PASSWORD_BCRYPT), 'Billing Clerk', 'billing_clerk', 'active']);
        $stmt->execute(['warehouse', password_hash('warehouse123', PASSWORD_BCRYPT), 'Warehouse Supervisor', 'warehouse_supervisor', 'active']);
        echo __('setup_db.seeded') . "<br>\n";
    }
} catch (PDOException $e) {
    die(__('setup_db.failed') . " " . $e->getMessage());
}
?>
