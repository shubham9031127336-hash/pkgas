<?php
/**
 * Business Configuration — Multi-Brand
 * Reads from business_config DB table.
 * getBrandConfig() returns hardcoded fallbacks if DB empty.
 * getBusinesses() returns [] if no DB rows (prevents phantom billing entities).
 */

function loadAllBusinessConfigs() {
    try {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $stmt = $GLOBALS['pdo']->query("SELECT * FROM business_config ORDER BY is_default DESC, id ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) return $rows;
        }
    } catch (PDOException $e) {}
    return null;
}

function getBusinesses() {
    $rows = loadAllBusinessConfigs();
    if ($rows) {
        $result = [];
        foreach ($rows as $r) {
            $result[$r['business_key']] = [
                'label'       => $r['label'] ?? 'Business',
                'name'        => $r['business_name'] ?? strtoupper($r['label'] ?? 'BUSINESS'),
                'tagline'     => $r['tagline'] ?? '',
                'address'     => $r['address'] ?? '',
                'gstin'       => $r['gstin'] ?? '',
                'phone'       => $r['phone'] ?? '',
                'email'       => $r['email'] ?? '',
                'website'     => $r['website'] ?? '',
                'logo_path'   => $r['logo_path'] ?? '',
                'logo_white_path' => $r['logo_white_path'] ?? '',
                'bank_details' => $r['bank_details'] ?? '',
                'invoice_terms' => $r['invoice_terms'] ?? '',
            ];
        }
        return $result;
    }
    return [];
}

function getBusiness($key) {
    $businesses = getBusinesses();
    return $businesses[$key] ?? getDefaultBusiness();
}

function getBusinessLabel($key) {
    $biz = getBusiness($key);
    return $biz['label'] ?? $key;
}

function getDefaultBusiness() {
    $rows = loadAllBusinessConfigs();
    if ($rows) {
        foreach ($rows as $r) {
            if (!empty($r['is_default'])) {
                return getBusiness($r['business_key']);
            }
        }
        return getBusiness($rows[0]['business_key']);
    }
    $all = getBusinesses();
    return reset($all) ?: [];
}

function getBrandConfig($business_key = null) {
    if ($business_key) {
        $biz = getBusiness($business_key);
    } else {
        $biz = getDefaultBusiness();
    }
    $rows = loadAllBusinessConfigs();
    $row = null;
    if ($rows) {
        $target_key = $business_key ?: ($biz['business_key'] ?? '');
        if (!$target_key) {
            foreach ($rows as $r) {
                if (!empty($r['is_default'])) { $target_key = $r['business_key']; break; }
            }
        }
        if (!$target_key) $target_key = $rows[0]['business_key'] ?? '';
        foreach ($rows as $r) {
            if ($r['business_key'] === $target_key) {
                $row = $r;
                break;
            }
        }
        if (!$row && $business_key) {
            foreach ($rows as $r) {
                if (!empty($r['is_default'])) { $row = $r; break; }
            }
        }
        if (!$row) $row = $rows[0];
    }
    if (!$row) $row = [];
    return [
        'label'       => $row['label'] ?? $biz['label'] ?? 'Prem Gas Solution',
        'business_name' => $row['business_name'] ?? $biz['name'] ?? 'PREM GAS SOLUTION',
        'business_key' => $row['business_key'] ?? 'prem_gas_solution',
        'tagline'     => $row['tagline'] ?? $biz['tagline'] ?? '',
        'address'     => $row['address'] ?? $biz['address'] ?? '',
        'gstin'       => $row['gstin'] ?? $biz['gstin'] ?? '',
        'phone'       => $row['phone'] ?? $biz['phone'] ?? '',
        'email'       => $row['email'] ?? $biz['email'] ?? '',
        'website'     => $row['website'] ?? $biz['website'] ?? '',
        'logo_path'   => $row['logo_path'] ?? $biz['logo_path'] ?? '',
        'logo_white_path' => $row['logo_white_path'] ?? $biz['logo_white_path'] ?? '',
        'bank_details' => $row['bank_details'] ?? $biz['bank_details'] ?? '',
        'invoice_terms' => $row['invoice_terms'] ?? $biz['invoice_terms'] ?? '',
        'smtp_host'   => $row['smtp_host'] ?? '',
        'smtp_port'   => intval($row['smtp_port'] ?? 587),
        'smtp_username' => $row['smtp_username'] ?? '',
        'smtp_password' => $row['smtp_password'] ?? '',
        'smtp_encryption' => $row['smtp_encryption'] ?? 'tls',
        'email_from_name' => $row['email_from_name'] ?? ($biz['name'] ?? ''),
        'email_from_address' => $row['email_from_address'] ?? ($biz['email'] ?? ''),
    ];
}

function getSiteUrl($path = '') {
    static $base = null;
    if ($base === null) {
        $config = getBrandConfig();
        $base = rtrim($config['website'] ?: 'https://pkgas.com', '/');
    }
    if ($path) {
        return $base . '/' . ltrim($path, '/');
    }
    return $base;
}

function saveBrandConfig($pdo, $data) {
    $sql = "INSERT INTO business_config (business_key, label, business_name, tagline, address, gstin, phone, email, website, logo_path, logo_white_path, bank_details, invoice_terms, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, email_from_name, email_from_address)
            VALUES (:business_key, :label, :business_name, :tagline, :address, :gstin, :phone, :email, :website, :logo_path, :logo_white_path, :bank_details, :invoice_terms, :smtp_host, :smtp_port, :smtp_username, :smtp_password, :smtp_encryption, :email_from_name, :email_from_address)
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                business_name = VALUES(business_name),
                tagline = VALUES(tagline),
                address = VALUES(address),
                gstin = VALUES(gstin),
                phone = VALUES(phone),
                email = VALUES(email),
                website = VALUES(website),
                logo_path = VALUES(logo_path),
                logo_white_path = VALUES(logo_white_path),
                bank_details = VALUES(bank_details),
                invoice_terms = VALUES(invoice_terms),
                smtp_host = VALUES(smtp_host),
                smtp_port = VALUES(smtp_port),
                smtp_username = VALUES(smtp_username),
                smtp_password = VALUES(smtp_password),
                smtp_encryption = VALUES(smtp_encryption),
                email_from_name = VALUES(email_from_name),
                email_from_address = VALUES(email_from_address)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':business_key' => $data['business_key'] ?? 'prem_gas_solution',
        ':label' => $data['label'] ?? 'Prem Gas Solution',
        ':business_name' => $data['business_name'] ?? 'PREM GAS SOLUTION',
        ':tagline' => $data['tagline'] ?? '',
        ':address' => $data['address'] ?? '',
        ':gstin' => $data['gstin'] ?? '',
        ':phone' => $data['phone'] ?? '',
        ':email' => $data['email'] ?? '',
        ':website' => $data['website'] ?? '',
        ':logo_path' => $data['logo_path'] ?? '',
        ':logo_white_path' => $data['logo_white_path'] ?? '',
        ':bank_details' => $data['bank_details'] ?? '',
        ':invoice_terms' => $data['invoice_terms'] ?? '',
        ':smtp_host' => $data['smtp_host'] ?? '',
        ':smtp_port' => intval($data['smtp_port'] ?? 587),
        ':smtp_username' => $data['smtp_username'] ?? '',
        ':smtp_password' => $data['smtp_password'] ?? '',
        ':smtp_encryption' => $data['smtp_encryption'] ?? 'tls',
        ':email_from_name' => $data['email_from_name'] ?? '',
        ':email_from_address' => $data['email_from_address'] ?? '',
    ]);
    return true;
}

function deleteBrand($pdo, $business_key) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM refill_orders WHERE business_name = ?");
    $stmt->execute([$business_key]);
    if ($stmt->fetchColumn() > 0) {
        return ['success' => false, 'error' => 'Cannot delete: orders exist for this brand.'];
    }
    $stmt = $pdo->prepare("DELETE FROM business_config WHERE business_key = ?");
    $stmt->execute([$business_key]);
    return ['success' => true];
}
