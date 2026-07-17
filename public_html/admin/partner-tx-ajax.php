<?php
/**
 * AJAX endpoints for partner/vendor cylinder transactions.
 * Called by partner-transaction-create.php via fetch().
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json');

// ── 1. Outstanding borrowed cylinders (partner_owned) for a partner ──
if (isset($_GET['ajax_partner_borrowed']) && intval($_GET['ajax_partner_borrowed']) > 0) {
    $pid = intval($_GET['ajax_partner_borrowed']);
    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT c.id, c.serial_number, c.size_capacity, c.borrow_date, c.daily_rent_rate, c.free_days,
                   g.name AS gas_name, g.id AS gas_type_id,
                   p.company_name AS partner_name
            FROM cylinders c
            JOIN gas_types g ON c.gas_type_id = g.id
            LEFT JOIN partners p ON c.current_partner_id = p.id
            WHERE c.current_partner_id = ?
              AND c.ownership_type = 'partner_owned'
              AND c.status != 'returned_to_partner'
            ORDER BY c.borrow_date ASC, c.serial_number ASC
        ");
        $stmt->execute([$pid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = new DateTime();
        foreach ($rows as &$r) {
            $bdate = $r['borrow_date'] ?: date('Y-m-d');
            try { $bd = new DateTime($bdate); } catch (Exception $e) { $bd = $today; }
            $free  = (int)($r['free_days'] ?? 0);
            $days  = max(0, (int)$today->diff($bd)->days);
            $chargeable = max(0, $days - $free);
            $rate  = (float)$r['daily_rent_rate'];
            $r['days_held']       = $days;
            $r['free_days']       = $free;
            $r['chargeable_days'] = $chargeable;
            $r['rent_accrued']    = round($chargeable * $rate, 2);
        }
        unset($r);
    } catch (Exception $e) {}
    echo json_encode($rows);
    exit();
}

// ── 2. Lent cylinders for a partner (company-owned, lent_to_partner) ──
if (isset($_GET['ajax_partner_lent']) && intval($_GET['ajax_partner_lent']) > 0) {
    $pid = intval($_GET['ajax_partner_lent']);
    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT c.id, c.serial_number, c.size_capacity, c.borrow_date, c.daily_rent_rate,
                   g.name AS gas_name, g.id AS gas_type_id
            FROM cylinders c
            JOIN gas_types g ON c.gas_type_id = g.id
            WHERE c.current_partner_id = ?
              AND c.ownership_type = 'owned'
              AND c.status = 'lent_to_partner'
            ORDER BY c.borrow_date ASC, c.serial_number ASC
        ");
        $stmt->execute([$pid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = new DateTime();
        foreach ($rows as &$r) {
            $bdate = $r['borrow_date'] ?: date('Y-m-d');
            try { $bd = new DateTime($bdate); } catch (Exception $e) { $bd = $today; }
            $days  = max(0, (int)$today->diff($bd)->days);
            $rate  = (float)$r['daily_rent_rate'];
            $r['days_held']    = $days;
            $r['rent_accrued'] = round($days * $rate, 2);
        }
        unset($r);
    } catch (Exception $e) {}
    echo json_encode($rows);
    exit();
}

// ── 3. Lendable cylinders (empty + in_maintenance + owned) ──
if (isset($_GET['ajax_lendable']) && intval($_GET['ajax_lendable']) > 0) {
    $pid = intval($_GET['ajax_lendable']);
    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT c.id, c.serial_number, c.size_capacity, c.daily_rent_rate,
                   g.name AS gas_name, g.id AS gas_type_id
            FROM cylinders c
            JOIN gas_types g ON c.gas_type_id = g.id
            WHERE c.current_partner_id IS NULL
              AND c.ownership_type = 'owned'
              AND c.status IN ('empty', 'in_maintenance')
            ORDER BY c.gas_type_id ASC, c.serial_number ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    echo json_encode($rows);
    exit();
}

// ── 4. Outstanding borrowed cylinders (vendor_owned) for a vendor ──
if (isset($_GET['ajax_vendor_borrowed']) && intval($_GET['ajax_vendor_borrowed']) > 0) {
    $vid = intval($_GET['ajax_vendor_borrowed']);
    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT c.id, c.serial_number, c.size_capacity, c.borrow_date, c.daily_rent_rate, c.free_days,
                   g.name AS gas_name, g.id AS gas_type_id,
                   v.name AS vendor_name
            FROM cylinders c
            JOIN gas_types g ON c.gas_type_id = g.id
            LEFT JOIN vendors v ON c.current_vendor_id = v.id
            WHERE c.current_vendor_id = ?
              AND c.ownership_type = 'vendor_owned'
              AND c.status != 'returned_to_vendor'
            ORDER BY c.borrow_date ASC, c.serial_number ASC
        ");
        $stmt->execute([$vid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = new DateTime();
        foreach ($rows as &$r) {
            $bdate = $r['borrow_date'] ?: date('Y-m-d');
            try { $bd = new DateTime($bdate); } catch (Exception $e) { $bd = $today; }
            $free  = (int)($r['free_days'] ?? 0);
            $days  = max(0, (int)$today->diff($bd)->days);
            $chargeable = max(0, $days - $free);
            $rate  = (float)$r['daily_rent_rate'];
            $r['days_held']       = $days;
            $r['free_days']       = $free;
            $r['chargeable_days'] = $chargeable;
            $r['rent_accrued']    = round($chargeable * $rate, 2);
        }
        unset($r);
    } catch (Exception $e) {}
    echo json_encode($rows);
    exit();
}

http_response_code(400);
echo json_encode(['error' => 'Invalid AJAX request']);
