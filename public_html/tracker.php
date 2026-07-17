<?php
require_once __DIR__ . '/translations.php';

$pageTitle = __p('tracker.title') . ' - Prem Gas Solution';
$pageDesc = __p('tracker.desc');
$canonical = getSiteUrl('tracker.php');
include __DIR__ . '/header.php';
require_once __DIR__ . '/admin/db.php';

$mobile_query = isset($_GET['mobile']) ? preg_replace('/[^0-9]/', '', $_GET['mobile']) : '';
if (strlen($mobile_query) > 15) {
    $mobile_query = substr($mobile_query, 0, 15);
}
$customer = null;
$error_search = '';
$ledger = [];
$active_cylinders = [];
$pending_dues = 0;
$total_invoiced = 0;
$total_paid = 0;
$total_refunded = 0;

if (!empty($mobile_query)) {
    if (strlen($mobile_query) !== 10) {
        $error_search = __p('tracker.error_mobile');
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE mobile = ? LIMIT 1");
            $stmt->execute([$mobile_query]);
            $customer = $stmt->fetch();
            
            if ($customer) {
                $id = $customer['id'];
                
                $stmt_cyl = $pdo->prepare("
                    SELECT c.*, g.name as gas_name 
                    FROM cylinders c 
                    JOIN gas_types g ON c.gas_type_id = g.id 
                    WHERE c.current_customer_id = ? AND c.status = 'with_customer'
                    ORDER BY c.serial_number ASC
                ");
                $stmt_cyl->execute([$id]);
                $active_cylinders = $stmt_cyl->fetchAll();
                
                $stmt_inv = $pdo->prepare("SELECT SUM(grand_total) FROM refill_orders WHERE customer_id = ?");
                $stmt_inv->execute([$id]);
                $total_invoiced = floatval($stmt_inv->fetchColumn() ?: 0);
                
                $stmt_paid = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE customer_id = ? AND payment_type IN ('refill_payment', 'deposit_added')");
                $stmt_paid->execute([$id]);
                $total_paid = floatval($stmt_paid->fetchColumn() ?: 0);
                
                $stmt_ref = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE customer_id = ? AND payment_type = 'deposit_refunded'");
                $stmt_ref->execute([$id]);
                $total_refunded = floatval($stmt_ref->fetchColumn() ?: 0);
                
                $pending_dues = ($total_invoiced + $total_refunded) - $total_paid;
                
                $stmt_ord = $pdo->prepare("SELECT id, order_date as event_date, grand_total, deposit_amount, notes FROM refill_orders WHERE customer_id = ?");
                $stmt_ord->execute([$id]);
                while ($row = $stmt_ord->fetch()) {
                    $ledger[] = [
                        'date' => $row['event_date'],
                        'type' => 'Refill Order Placed',
                        'ref' => 'ORD-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                        'detail' => __p('tracker.refill_invoice') . ($row['deposit_amount'] > 0 ? " (" . __p('tracker.includes_deposit') . ")" : ""),
                        'debit' => $row['grand_total'],
                        'credit' => 0,
                        'notes' => $row['notes']
                    ];
                }
                
                $stmt_pay = $pdo->prepare("SELECT id, payment_date as event_date, amount, payment_type, payment_method, notes FROM payments WHERE customer_id = ?");
                $stmt_pay->execute([$id]);
                while ($row = $stmt_pay->fetch()) {
                    $debit = 0;
                    $credit = 0;
                    $label = __p('tracker.payment_received');
                    
                    if ($row['payment_type'] === 'refill_payment') {
                        $label = __p('tracker.payment_refill');
                        $credit = $row['amount'];
                    } elseif ($row['payment_type'] === 'deposit_added') {
                        $label = __p('tracker.deposit_added');
                        $credit = $row['amount'];
                    } elseif ($row['payment_type'] === 'deposit_refunded') {
                        $label = __p('tracker.deposit_refunded');
                        $debit = $row['amount'];
                    }
                    
                    $ledger[] = [
                        'date' => $row['event_date'],
                        'type' => $label,
                        'ref' => 'PAY-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                        'detail' => __p('tracker.processed_via') . " " . $row['payment_method'],
                        'debit' => $debit,
                        'credit' => $credit,
                        'notes' => $row['notes']
                    ];
                }
                
                $stmt_mv = $pdo->prepare("
                    SELECT ct.transaction_date as event_date, ct.transaction_type, cy.serial_number, cy.size_capacity, ct.notes 
                    FROM cylinder_transactions ct 
                    JOIN cylinders cy ON ct.cylinder_id = cy.id 
                    WHERE ct.customer_id = ?
                ");
                $stmt_mv->execute([$id]);
                while ($row = $stmt_mv->fetch()) {
                    $is_return = ($row['transaction_type'] === 'return_from_customer');
                    $ledger[] = [
                        'date' => $row['event_date'],
                        'type' => $is_return ? __p('tracker.cylinder_returned') : __p('tracker.cylinder_issued'),
                        'ref' => $is_return ? 'RET-IN' : 'ISS-OUT',
                        'detail' => htmlspecialchars($row['serial_number']) . " (" . htmlspecialchars($row['size_capacity']) . ")",
                        'debit' => 0,
                        'credit' => 0,
                        'notes' => $row['notes']
                    ];
                }
                
                usort($ledger, function($a, $b) {
                    return strtotime($b['date']) - strtotime($a['date']);
                });
            } else {
                $error_search = __p('tracker.error_not_found') . " '$mobile_query'.";
            }
        } catch (PDOException $e) {
            $error_search = __p('tracker.error_system');
        }
    }
}
?>

<div style="background: linear-gradient(135deg, #091225 0%, #030712 100%); color: #f8fafc; min-height: 100vh; padding-top: 120px; padding-bottom: 80px; font-family: 'Outfit', sans-serif;">
    <div style="max-width: 1200px; margin: 0 auto; padding: 0 1.5rem;">
        
        <div style="background: rgba(30, 41, 59, 0.4); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.08); border-radius: 24px; padding: 3rem 2rem; text-align: center; margin-bottom: 3rem; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
            <span style="background: #1e40ff; color: #fff; font-size: 0.75rem; font-weight: 800; padding: 6px 16px; border-radius: 99px; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; margin-bottom: 1.5rem;">
                <?php echo __p('tracker.title'); ?>
            </span>
            <h1 style="font-size: 2.25rem; font-weight: 800; color: #fff; margin-bottom: 0.5rem; letter-spacing: -0.02em;"><?php echo __p('tracker.heading'); ?></h1>
            <p style="color: #94a3b8; font-size: 1.05rem; max-width: 600px; margin: 0 auto 2rem; line-height: 1.6;">
                <?php echo __p('tracker.desc'); ?>
            </p>
            
            <form method="GET" action="tracker.php" style="max-width: 500px; margin: 0 auto; display: flex; gap: 0.75rem; background: rgba(15, 23, 42, 0.6); padding: 6px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.12);">
                <div style="flex-grow: 1; display: flex; align-items: center; padding-left: 1rem; color: #64748b;">
                    <span style="font-size: 1.1rem; font-weight: 700; margin-right: 0.5rem; color: #3b82f6;"><?php echo __p('tracker.country_code'); ?></span>
                    <input type="tel" name="mobile" maxlength="10" placeholder="<?php echo __p('tracker.placeholder'); ?>" required 
                           value="<?php echo htmlspecialchars($mobile_query); ?>"
                           style="background: transparent; border: none; outline: none; width: 100%; color: #fff; font-size: 1.1rem; font-weight: 600; font-family: inherit;">
                </div>
                <button type="submit" style="background: #1e40ff; color: #fff; border: none; padding: 0.75rem 1.75rem; border-radius: 12px; font-weight: 700; cursor: pointer; transition: all 0.3s ease; font-size: 1rem;">
                    <?php echo __p('tracker.search_btn'); ?>
                </button>
            </form>
            
            <?php if ($error_search): ?>
                <div style="background: rgba(239, 68, 68, 0.1); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 12px; padding: 1rem; margin-top: 1.5rem; max-width: 500px; margin-left: auto; margin-right: auto; font-weight: 600; font-size: 0.95rem;">
                    ⚠️ <?php echo htmlspecialchars($error_search); ?>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($customer): ?>
            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 20px; padding: 2rem; display: flex; flex-direction: column; md-flex-direction: row; justify-content: space-between; align-items: flex-start; gap: 1.5rem; margin-bottom: 2.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
                <div>
                    <h2 style="font-size: 1.75rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.25rem;">
                        <?php echo htmlspecialchars($customer['name']); ?>
                        <span style="font-size: 0.75rem; font-weight: 700; background: rgba(59, 130, 246, 0.15); color: #3b82f6; padding: 4px 12px; border-radius: 99px; text-transform: uppercase;">
                            <?php echo htmlspecialchars($customer['customer_type']); ?>
                        </span>
                    </h2>
                    <p style="color: #64748b; font-weight: 500; font-size: 0.95rem; margin-bottom: 0.5rem;">
                        📞 +91-<?php echo htmlspecialchars($customer['mobile']); ?> 
                        <?php if ($customer['gst_number']): ?>
                            | GSTIN: <strong style="color: #94a3b8;"><?php echo htmlspecialchars($customer['gst_number']); ?></strong>
                        <?php endif; ?>
                    </p>
                    <p style="color: #94a3b8; font-size: 0.9rem; margin-bottom: 0; line-height: 1.5;">
                        📍 <?php echo htmlspecialchars($customer['address'] ?: __p('tracker.no_address')); ?>
                    </p>
                </div>
                <div style="text-align: right; font-size: 0.85rem; color: #64748b;">
                    <?php echo __p('tracker.account_status'); ?> <span style="color: #10b981; font-weight: 700; text-transform: uppercase;">● <?php echo __p('tracker.active'); ?></span><br>
                    <?php echo __p('tracker.registry_id'); ?> #CUST-<?php echo str_pad($customer['id'], 4, '0', STR_PAD_LEFT); ?>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 3rem;">
                
                <div style="background: <?php echo $pending_dues > 0 ? 'rgba(239, 68, 68, 0.05)' : 'rgba(16, 185, 129, 0.05)'; ?>; border: 1px solid <?php echo $pending_dues > 0 ? 'rgba(239, 68, 68, 0.15)' : 'rgba(16, 185, 129, 0.15)'; ?>; border-radius: 20px; padding: 2rem; display: flex; align-items: center; gap: 1.5rem; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
                    <div style="width: 54px; height: 54px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; background: <?php echo $pending_dues > 0 ? '#ef4444' : '#10b981'; ?>; color: #fff;">
                        ₹
                    </div>
                    <div>
                        <h4 style="font-size: 1.85rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">
                            ₹<?php echo number_format($pending_dues, 2); ?>
                        </h4>
                        <p style="color: #94a3b8; font-size: 0.9rem; margin-bottom: 0;">
                            <?php echo $pending_dues > 0 ? '⚠️ ' . __p('tracker.outstanding_dues') : '✅ ' . __p('tracker.balance_paid'); ?>
                        </p>
                    </div>
                </div>

                <div style="background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.15); border-radius: 20px; padding: 2rem; display: flex; align-items: center; gap: 1.5rem; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
                    <div style="width: 54px; height: 54px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; background: #3b82f6; color: #fff;">
                        📦
                    </div>
                    <div>
                        <h4 style="font-size: 1.85rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">
                            <?php echo count($active_cylinders); ?> <?php echo __p('tracker.cylinders_label'); ?>
                        </h4>
                        <p style="color: #94a3b8; font-size: 0.9rem; margin-bottom: 0;">
                            <?php echo __p('tracker.leased_cylinders'); ?>
                        </p>
                    </div>
                </div>

                <div style="background: rgba(139, 92, 246, 0.05); border: 1px solid rgba(139, 92, 246, 0.15); border-radius: 20px; padding: 2rem; display: flex; align-items: center; gap: 1.5rem; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
                    <div style="width: 54px; height: 54px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; background: #8b5cf6; color: #fff;">
                        🛡️
                    </div>
                    <div>
                        <h4 style="font-size: 1.85rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">
                            ₹<?php echo number_format($customer['deposit_balance'], 2); ?>
                        </h4>
                        <p style="color: #94a3b8; font-size: 0.9rem; margin-bottom: 0;">
                            <?php echo __p('tracker.security_deposits'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 1rem; border-bottom: 2px solid rgba(255,255,255,0.06); padding-bottom: 1rem; margin-bottom: 2rem;">
                <button id="btnTabLedger" class="tab-button active-tab" onclick="switchTab('ledger')" 
                        style="background: transparent; border: none; color: #3b82f6; font-size: 1.1rem; font-weight: 700; cursor: pointer; padding-bottom: 12px; border-bottom: 3px solid #3b82f6; transition: all 0.3s;">
                    📜 <?php echo __p('tracker.tab_ledger'); ?>
                </button>
                <button id="btnTabCylinders" class="tab-button" onclick="switchTab('cylinders')" 
                        style="background: transparent; border: none; color: #94a3b8; font-size: 1.1rem; font-weight: 700; cursor: pointer; padding-bottom: 12px; border-bottom: 3px solid transparent; transition: all 0.3s;">
                    🔋 <?php echo __p('tracker.tab_cylinders'); ?>
                </button>
            </div>

            <div id="tabContentLedger" class="tab-content" style="display: block;">
                <div style="background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255,255,255,0.06); border-radius: 20px; overflow-x: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 800px;">
                        <thead>
                            <tr style="background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.08);">
                                <th style="padding: 1.25rem; color: #94a3b8; font-weight: 700; font-size: 0.9rem;"><?php echo __p('tracker.date'); ?></th>
                                <th style="padding: 1.25rem; color: #94a3b8; font-weight: 700; font-size: 0.9rem;"><?php echo __p('tracker.ref_no'); ?></th>
                                <th style="padding: 1.25rem; color: #94a3b8; font-weight: 700; font-size: 0.9rem;"><?php echo __p('tracker.tx_type'); ?></th>
                                <th style="padding: 1.25rem; color: #94a3b8; font-weight: 700; font-size: 0.9rem;"><?php echo __p('tracker.description'); ?></th>
                                <th style="padding: 1.25rem; color: #94a3b8; font-weight: 700; font-size: 0.9rem; text-align: right;"><?php echo __p('tracker.debit'); ?></th>
                                <th style="padding: 1.25rem; color: #94a3b8; font-weight: 700; font-size: 0.9rem; text-align: right;"><?php echo __p('tracker.credit'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ledger as $item): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.01)'" onmouseout="this.style.background='transparent'">
                                <td style="padding: 1.25rem; font-weight: 600; color: #cbd5e1;"><?php echo date('M d, Y h:i A', strtotime($item['date'])); ?></td>
                                <td style="padding: 1.25rem;">
                                    <span style="font-family: monospace; font-size: 0.85rem; background: rgba(255,255,255,0.04); padding: 4px 10px; border-radius: 6px; color: #3b82f6; font-weight: 700;">
                                        #<?php echo $item['ref']; ?>
                                    </span>
                                </td>
                                <td style="padding: 1.25rem;">
                                    <?php
                                    $color = '#cbd5e1';
                                    if (strpos($item['type'], __p('tracker.payment_received')) !== false || strpos($item['type'], __p('tracker.deposit_added')) !== false) $color = '#34d399';
                                    elseif (strpos($item['type'], __p('tracker.deposit_refunded')) !== false) $color = '#fbbf24';
                                    elseif (strpos($item['type'], 'Order') !== false) $color = '#f87171';
                                    ?>
                                    <strong style="color: <?php echo $color; ?>; font-size: 0.9rem;"><?php echo $item['type']; ?></strong>
                                </td>
                                <td style="padding: 1.25rem; color: #94a3b8; font-size: 0.9rem; font-weight: 500;">
                                    <?php echo htmlspecialchars($item['detail']); ?>
                                    <?php if ($item['notes']): ?>
                                        <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;"><?php echo __p('common.notes'); ?>: <?php echo htmlspecialchars($item['notes']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1.25rem; text-align: right; font-weight: 700; color: #f87171;">
                                    <?php echo $item['debit'] > 0 ? '₹' . number_format($item['debit'], 2) : '-'; ?>
                                </td>
                                <td style="padding: 1.25rem; text-align: right; font-weight: 700; color: #34d399;">
                                    <?php echo $item['credit'] > 0 ? '₹' . number_format($item['credit'], 2) : '-'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($ledger)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 4rem 0; color: #64748b;"><?php echo __p('tracker.no_logs'); ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="tabContentCylinders" class="tab-content" style="display: none;">
                <div style="background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255,255,255,0.06); border-radius: 20px; overflow-x: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 800px;">
                        <thead>
                            <tr style="background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.08);">
                                <th style="padding: 1.25rem; color: #94a3b8; font-weight: 700; font-size: 0.9rem;"><?php echo __p('tracker.serial'); ?></th>
                                <th style="padding: 1.25rem; color: #94a3b8; font-weight: 700; font-size: 0.9rem;"><?php echo __p('tracker.barcode'); ?></th>
                                <th style="padding: 1.25rem; color: #94a3b8; font-weight: 700; font-size: 0.9rem;"><?php echo __p('tracker.gas'); ?></th>
                                <th style="padding: 1.25rem; color: #94a3b8; font-weight: 700; font-size: 0.9rem;"><?php echo __p('tracker.capacity'); ?></th>
                                <th style="padding: 1.25rem; color: #94a3b8; font-weight: 700; font-size: 0.9rem;"><?php echo __p('tracker.hydro_test'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_cylinders as $cyl): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.01)'" onmouseout="this.style.background='transparent'">
                                <td style="padding: 1.25rem; font-weight: 800; color: #3b82f6;"><?php echo htmlspecialchars($cyl['serial_number']); ?></td>
                                <td style="padding: 1.25rem;">
                                    <code style="font-family: monospace; font-size: 0.85rem; background: rgba(255,255,255,0.04); padding: 4px 10px; border-radius: 6px; color: #cbd5e1;">
                                        [||] <?php echo htmlspecialchars($cyl['barcode'] ?: __p('tracker.na')); ?>
                                    </code>
                                </td>
                                <td style="padding: 1.25rem; font-weight: 700; color: #fff;"><?php echo htmlspecialchars($cyl['gas_name']); ?></td>
                                <td style="padding: 1.25rem; font-weight: 600; color: #cbd5e1;"><?php echo htmlspecialchars($cyl['size_capacity']); ?></td>
                                <td style="padding: 1.25rem; font-weight: 600;">
                                    <?php if ($cyl['expiry_date']): ?>
                                        <?php
                                        $exp_stamp = strtotime($cyl['expiry_date']);
                                        $is_exp = ($exp_stamp <= time());
                                        ?>
                                        <span style="color: <?php echo $is_exp ? '#ef4444' : '#10b981'; ?>;">
                                            📅 <?php echo __p('tracker.hydro_due'); ?> <?php echo date('M Y', $exp_stamp); ?>
                                            <?php echo $is_exp ? ' ⚠️ (' . __p('tracker.due_inspection') . ')' : ' (' . __p('tracker.safe') . ')'; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #64748b;"><?php echo __p('tracker.not_specified'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($active_cylinders)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 4rem 0; color: #64748b;"><?php echo __p('tracker.no_cylinders'); ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
        
    </div>
</div>

<script>
    function switchTab(tab) {
        const btnLedger = document.getElementById('btnTabLedger');
        const btnCylinders = document.getElementById('btnTabCylinders');
        const contentLedger = document.getElementById('tabContentLedger');
        const contentCylinders = document.getElementById('tabContentCylinders');
        
        if (tab === 'ledger') {
            btnLedger.style.color = '#3b82f6';
            btnLedger.style.borderBottom = '3px solid #3b82f6';
            btnCylinders.style.color = '#94a3b8';
            btnCylinders.style.borderBottom = '3px solid transparent';
            contentLedger.style.display = 'block';
            contentCylinders.style.display = 'none';
        } else {
            btnCylinders.style.color = '#3b82f6';
            btnCylinders.style.borderBottom = '3px solid #3b82f6';
            btnLedger.style.color = '#94a3b8';
            btnLedger.style.borderBottom = '3px solid transparent';
            contentLedger.style.display = 'none';
            contentCylinders.style.display = 'block';
        }
    }
</script>

<?php include 'footer.php'; ?>
