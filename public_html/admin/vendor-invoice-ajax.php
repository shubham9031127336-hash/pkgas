<?php
/**
 * Vendor Invoice AJAX handlers
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';

require_login();
validateCsrfToken();
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Invalid request'];
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

try {
    switch ($action) {

        // ─── Mark invoice as paid ───
        case 'mark_paid':
            $inv_id = intval($_POST['invoice_id'] ?? 0);
            if ($inv_id <= 0) throw new Exception("Invalid invoice ID");

            $stmt = $pdo->prepare("SELECT * FROM vendor_invoices WHERE id = ?");
            $stmt->execute([$inv_id]);
            $inv = $stmt->fetch();
            if (!$inv) throw new Exception("Invoice not found");

            $pdo->prepare("UPDATE vendor_invoices SET payment_status = 'paid', paid_amount = grand_total, balance = 0 WHERE id = ?")->execute([$inv_id]);

            updateVendorInvoicePaymentStatus($pdo, $inv_id);
            if (function_exists('syncGSTFromVendorInvoice')) {
                syncGSTFromVendorInvoice($pdo, $inv_id);
            }

            // Sync the associated dispatch lot
            if (!empty($inv['lot_id']) && function_exists('recalcLotFinancials')) {
                recalcLotFinancials($pdo, $inv['lot_id']);
            }

            $response = ['success' => true, 'message' => 'Invoice marked as paid.'];
            break;

        // ─── Mark invoice as unpaid ───
        case 'mark_unpaid':
            $inv_id = intval($_POST['invoice_id'] ?? 0);
            if ($inv_id <= 0) throw new Exception("Invalid invoice ID");

            $pdo->prepare("UPDATE vendor_invoices SET payment_status = 'unpaid', paid_amount = 0, balance = grand_total WHERE id = ?")->execute([$inv_id]);

            updateVendorInvoicePaymentStatus($pdo, $inv_id);

            // Sync the associated dispatch lot
            if (!empty($inv['lot_id']) && function_exists('recalcLotFinancials')) {
                recalcLotFinancials($pdo, $inv['lot_id']);
            }

            $response = ['success' => true, 'message' => 'Invoice marked as unpaid.'];
            break;

        // ─── Delete invoice ───
        case 'delete':
            $inv_id = intval($_POST['invoice_id'] ?? 0);
            if ($inv_id <= 0) throw new Exception("Invalid invoice ID");

            $pdo->prepare("DELETE FROM gst_ledger WHERE reference_type = 'vendor_invoice' AND reference_id = ?")->execute([$inv_id]);
            $pdo->prepare("DELETE FROM vendor_invoice_items WHERE invoice_id = ?")->execute([$inv_id]);
            $pdo->prepare("DELETE FROM vendor_invoices WHERE id = ?")->execute([$inv_id]);

            $response = ['success' => true, 'message' => 'Invoice deleted successfully.'];
            break;

        // ─── Get lot details (preview) ───
        case 'get_lot_details':
            $lot_id = intval($_GET['lot_id'] ?? 0);
            if ($lot_id <= 0) throw new Exception("Invalid lot ID");

            $stmt = $pdo->prepare("
                SELECT dl.*, v.name AS vendor_name, v.gst_number AS vendor_gstin
                FROM dispatch_lots dl
                JOIN vendors v ON dl.vendor_id = v.id
                WHERE dl.id = ?
            ");
            $stmt->execute([$lot_id]);
            $lot = $stmt->fetch();
            if (!$lot) throw new Exception("Lot not found");

            $stmt = $pdo->prepare("
                SELECT dli.*, g.name AS gas_name
                FROM dispatch_lot_items dli
                JOIN gas_types g ON dli.gas_type_id = g.id
                WHERE dli.lot_id = ? AND dli.dispatch_status = 'received'
            ");
            $stmt->execute([$lot_id]);
            $items = $stmt->fetchAll();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendor_invoices WHERE lot_id = ?");
            $stmt->execute([$lot_id]);
            $has_invoice = intval($stmt->fetchColumn()) > 0;

            $response = [
                'success' => true,
                'lot' => $lot,
                'items' => $items,
                'has_invoice' => $has_invoice,
                'next_number' => function_exists('getNextVendorInvoiceNumber') ? getNextVendorInvoiceNumber($pdo) : 'PINV-' . date('Y') . '-0001',
            ];
            break;

        default:
            throw new Exception("Unknown action: $action");
    }
} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($response);
exit();
