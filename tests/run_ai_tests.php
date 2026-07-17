<?php
/**
 * Nutan Gases — AI Assistant Test Suite (Phase 3)
 * Tests: migrations, config, permissions, SQL validation, entity detection,
 * memory, actions, schema explorer, analytics, forecasting, trends, chat API
 */

// ─── Bootstrap ───
$_SERVER['DOCUMENT_ROOT'] = 'C:\\xampp\\htdocs';
require_once __DIR__ . '/../public_html/admin/db.php';

// Need a session for permission gate / memory functions
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'super_admin';
$_SESSION['username'] = 'admin';

$pass = 0; $fail = 0; $total = 0;
function section($name) { echo "\n─── $name ───\n"; }
function test($label, $cond, $detail = null) {
    global $pass, $fail, $total;
    $total++;
    if ($cond) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label" . ($detail ? " — $detail" : '') . "\n"; }
}

// ═══════════════════════════════════════
// 1. AI MIGRATIONS & CONFIG
// ═══════════════════════════════════════
section('1. AI Migrations & Config');

require_once __DIR__ . '/../public_html/admin/ai-migration.php';
runAIMigrations($pdo);

$aiTables = ['ai_config', 'ai_user_memory', 'ai_role_memory', 'ai_session_context', 'ai_conversations', 'ai_workflow_memory', 'ai_feedback'];
foreach ($aiTables as $t) {
    try { $pdo->query("SELECT 1 FROM $t LIMIT 1"); test("AI table `$t` exists", true); }
    catch (Exception $e) { test("AI table `$t` exists", false, $e->getMessage()); }
}

// Config migration + retrieval
require_once __DIR__ . '/../public_html/admin/ai/ai-config.php';
runAIConfigMigration($pdo);
$config = getAIConfig($pdo);
test('ai_config has data', !empty($config));
test('ai_config has provider key', isset($config['provider']));
test('ai_config has model key', isset($config['model']));

// Role memory seeded
$stmt = $pdo->query("SELECT COUNT(*) FROM ai_role_memory");
test('ai_role_memory has seeded records', $stmt->fetchColumn() > 0);

// ═══════════════════════════════════════
// 2. PERMISSION GATE
// ═══════════════════════════════════════
section('2. Permission Gate');

require_once __DIR__ . '/../public_html/admin/ai/security/permission-gate.php';

test('aiUserAllowed(super_admin) true', aiUserAllowed('super_admin') === true);
test('aiUserAllowed(billing_clerk) true', aiUserAllowed('billing_clerk') === true);
test('aiUserAllowed(warehouse_supervisor) true', aiUserAllowed('warehouse_supervisor') === true);
test('aiUserAllowed(delivery_driver) false', aiUserAllowed('delivery_driver') === false);
test('aiUserAllowed(viewer) false', aiUserAllowed('viewer') === false);
test('aiUserAllowed(empty) false', aiUserAllowed('') === false);

// Rate limit: fresh session should have remaining > 0
$remaining = aiRateLimitRemaining(30, 60);
test('aiRateLimitRemaining returns > 0 for super_admin', $remaining > 0);

// Rate limit check should pass
test('aiRateLimitCheck passes for super_admin', aiRateLimitCheck(30, 60) === true);

// ═══════════════════════════════════════
// 3. SQL VALIDATOR
// ═══════════════════════════════════════
section('3. SQL Validator');

require_once __DIR__ . '/../public_html/admin/ai/security/sql-validator.php';

test('isSelectOnly(SELECT) true', isSelectOnly('SELECT * FROM cylinders') === true);
test('isSelectOnly(SELECT with WHERE) true', isSelectOnly('SELECT id, name FROM customers WHERE id = 1') === true);
test('isSelectOnly(SELECT with JOIN) true', isSelectOnly('SELECT c.*, g.name FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id') === true);
test('isSelectOnly(INSERT) false', isSelectOnly("INSERT INTO customers (name) VALUES ('test')") === false);
test('isSelectOnly(UPDATE) false', isSelectOnly("UPDATE customers SET name='x' WHERE id=1") === false);
test('isSelectOnly(DELETE) false', isSelectOnly("DELETE FROM customers WHERE id=1") === false);
test('isSelectOnly(DROP) false', isSelectOnly('DROP TABLE customers') === false);
test('isSelectOnly(ALTER) false', isSelectOnly('ALTER TABLE customers ADD COLUMN x INT') === false);
test('isSelectOnly(TRUNCATE) false', isSelectOnly('TRUNCATE TABLE customers') === false);
test('isSelectOnly(empty) false', isSelectOnly('') === false);
test('isSelectOnly(EXECUTE) false', isSelectOnly('EXECUTE some_proc') === false);
test('isSelectOnly(WITH) true', isSelectOnly('WITH cte AS (SELECT * FROM cylinders) SELECT * FROM cte') === true);

$v = validateSQL('SELECT * FROM cylinders');
test('validateSQL(valid SELECT) valid=true', $v['valid'] === true);
test('validateSQL(valid SELECT) no error', $v['error'] === null);

$v2 = validateSQL('DROP TABLE cylinders');
test('validateSQL(DROP) valid=false', $v2['valid'] === false);
test('validateSQL(DROP) has error', $v2['error'] !== null);

$v3 = validateSQL('SELECT * FROM mysql.user');
test('validateSQL(mysql. prefix) blocked', $v3['valid'] === false);

$v4 = validateSQL('');
test('validateSQL(empty) valid=false', $v4['valid'] === false);

test('sanitizeSQLIdentifier(valid) unchanged', sanitizeSQLIdentifier('customer_name') === 'customer_name');
test('sanitizeSQLIdentifier(removes special chars)', sanitizeSQLIdentifier('table; DROP') === 'tableDROP');

// execute allowed query
$r = validateAndExecuteRawSQL($pdo, 'SELECT COUNT(*) AS cnt FROM gas_types');
test('validateAndExecuteRawSQL valid SELECT', $r['success'] === true);
test('validateAndExecuteRawSQL returns data', isset($r['data']));

$r2 = validateAndExecuteRawSQL($pdo, 'DELETE FROM gas_types');
test('validateAndExecuteRawSQL rejects DELETE', $r2['success'] === false);

// ═══════════════════════════════════════
// 4. ENTITY DETECTION
// ═══════════════════════════════════════
section('4. Entity Detection');

require_once __DIR__ . '/../public_html/admin/ai/detection/entity-detector.php';

// Cylinder serial detection
$entities = detectEntities($pdo, 'Check cylinder OX-001');
$serials = array_filter($entities, fn($e) => $e['type'] === 'cylinder_serial');
test('detectEntities finds cylinder serial OX-001', count($serials) > 0);

$entities2 = detectEntities($pdo, 'Where is ABC123?');
$serials2 = array_filter($entities2, fn($e) => $e['type'] === 'cylinder_serial');
test('detectEntities finds serial ABC123', count($serials2) > 0);

// Customer mobile detection
$entities3 = detectEntities($pdo, 'Find customer 9999999900');
$mobiles = array_filter($entities3, fn($e) => $e['type'] === 'customer_mobile');
test('detectEntities finds mobile 9999999900', count($mobiles) > 0);

// Invoice detection
$entities4 = detectEntities($pdo, 'Show invoice INV-2026-001');
$invs = array_filter($entities4, fn($e) => $e['type'] === 'invoice_number');
test('detectEntities finds invoice number', count($invs) > 0);

// Date expressions
$entities5 = detectEntities($pdo, 'Sales from today');
$dates = array_filter($entities5, fn($e) => $e['type'] === 'date_expression');
test('detectEntities finds date (today)', count($dates) > 0);

$entities6 = detectEntities($pdo, 'Last month sales');
$dates2 = array_filter($entities6, fn($e) => $e['type'] === 'date_expression');
test('detectEntities finds date (last month)', count($dates2) > 0);

// Gas type detection
$entities7 = detectEntities($pdo, 'How many oxygen cylinders?');
$gases = array_filter($entities7, fn($e) => $e['type'] === 'gas_type');
test('detectEntities finds gas type (oxygen)', count($gases) > 0);

// Contextual entities
$entities8 = detectEntities($pdo, 'Show filled cylinders');
$statuses = array_filter($entities8, fn($e) => $e['type'] === 'cylinder_status');
test('detectEntities finds cylinder status (filled)', count($statuses) > 0);

$entities9 = detectEntities($pdo, 'Show history for customer');
$reqs = array_filter($entities9, fn($e) => $e['type'] === 'request_type');
test('detectEntities finds request_type (history)', count($reqs) > 0);

// Helper function tests
test('mapDateExpression(today)', mapDateExpression('today') === 'today');
test('mapDateExpression(yesterday)', mapDateExpression('yesterday') === 'yesterday');
test('mapDateExpression(this month)', mapDateExpression('this month') === 'this_month');
test('mapDateExpression(last month)', mapDateExpression('last month') === 'last_month');
test('mapDateExpression(unknown)', mapDateExpression('random') === null);
test('mapDateExpression(आज) Hindi', mapDateExpression('आज') === 'today');
test('mapDateExpression(is mahine) Hinglish', mapDateExpression('is mahine') === 'this_month');

test('isEntityPresent found', isEntityPresent([['type'=>'test','value'=>'x']], 'test', 'x') === true);
test('isEntityPresent not found', isEntityPresent([['type'=>'test','value'=>'x']], 'test', 'y') === false);

$dedup = deduplicateEntities([
    ['type'=>'test','value'=>'a'],
    ['type'=>'test','value'=>'a'],
    ['type'=>'test','value'=>'b'],
]);
test('deduplicateEntities removes duplicates', count($dedup) === 2);

// ═══════════════════════════════════════
// 5. INTENT CLASSIFIER
// ═══════════════════════════════════════
section('5. Intent Classification');

require_once __DIR__ . '/../public_html/admin/ai/planning/intent-classifier.php';

$intent1 = classifyIntent('How many oxygen cylinders are in stock?', []);
test('classifyIntent returns array with intent key', isset($intent1['intent']));
test('classifyIntent has confidence', isset($intent1['confidence']));

$intent2 = classifyIntent('Show me customer 9999999900 details', [['type'=>'customer_mobile','value'=>'9999999900']]);
test('classifyIntent customer_lookup with mobile', $intent2['intent'] === 'customer_lookup');

$intent3 = classifyIntent('What were sales yesterday?', [['type'=>'date_expression','value'=>'yesterday']]);
test('classifyIntent sales_analytics', isset($intent3['intent']));

$intent4 = classifyIntent('Where is cylinder ABC123?', [['type'=>'cylinder_serial','value'=>'ABC123']]);
test('classifyIntent cylinder_tracking with serial', $intent4['intent'] === 'cylinder_tracking');

$intent5 = classifyIntent('Hello', []);
test('classifyIntent general fallback', $intent5['intent'] === 'general');

test('scoreKeywords counts matches', scoreKeywords('show me oxygen stock', ['oxygen','stock','cylinder']) === 2);

// ═══════════════════════════════════════
// 6. MEMORY (store, retrieve, compress)
// ═══════════════════════════════════════
section('6. Memory System');

require_once __DIR__ . '/../public_html/admin/ai/memory/memory-store.php';
require_once __DIR__ . '/../public_html/admin/ai/memory/memory-retriever.php';
require_once __DIR__ . '/../public_html/admin/ai/memory/memory-compressor.php';

$session_id = 'test-session-' . time();
$user_id = 1;

// Save conversation
$convId = saveConversation($pdo, $session_id, $user_id, 'super_admin', 'How many oxygen cylinders?', 'stock_inquiry', 0.9, 500, 150);
test('saveConversation returns conversation ID', $convId !== false && $convId > 0);

// Retrieve
$history = getConversationHistory($pdo, $session_id, 10);
test('getConversationHistory returns array', is_array($history));
test('getConversationHistory has records', count($history) > 0);

// Save user memory
$memResult = saveUserMemory($pdo, $user_id, 'preferred_language', 'hinglish', 0.8, 'context:chat');
test('saveUserMemory succeeds', $memResult === true);

// Retrieve user memory
$memories = getUserMemories($pdo, $user_id, 0.5, 20);
test('getUserMemories returns array', is_array($memories));
test('getUserMemories has records', count($memories) > 0);

// Get user memory by key
$specificMemory = getUserMemoryByKey($pdo, $user_id, 'preferred_language');
test('getUserMemoryByKey returns memory', $specificMemory !== null);
test('getUserMemoryByKey correct value', $specificMemory && $specificMemory['memory_value'] === 'hinglish');

// Role memory
$roleMemories = getRoleMemories($pdo, 'super_admin');
test('getRoleMemories returns array', is_array($roleMemories));

$roleMemKeys = getRoleMemoriesByKeys($pdo, 'super_admin', ['role_definition']);
test('getRoleMemoriesByKeys returns array', is_array($roleMemKeys));

// Session context
$ctxResult = saveSessionContext($pdo, $session_id, $user_id, ['focus' => 'inventory'], 60);
test('saveSessionContext succeeds', $ctxResult === true);

$context = getSessionContext($pdo, $session_id);
test('getSessionContext returns array', is_array($context));

// Conversation stats
$stats = getConversationStats($pdo, $user_id);
test('getConversationStats returns array', is_array($stats));

// Save feedback
$fbResult = saveFeedback($pdo, $convId, $user_id, 5, 'Great response');
test('saveFeedback succeeds', $fbResult === true);

// Workflow memory
$wmResult = saveWorkflowMemory($pdo, 'stock_inquiry', 'How many X', 1);
test('saveWorkflowMemory succeeds', $wmResult === true);

$workflows = getWorkflowMemories($pdo, 0, 10);
test('getWorkflowMemories returns array', is_array($workflows));

// Compression
$messages = [
    ['role' => 'user', 'content' => 'How many oxygen cylinders?'],
    ['role' => 'assistant', 'content' => 'We have 50 oxygen cylinders in stock.'],
];
$summary = compressConversation($messages);
test('compressConversation returns string', is_string($summary) && strlen($summary) > 0);

// Test compression + store
$cmpResult = storeCompressedMemory($pdo, $session_id, $user_id, 'stock_inquiry');
test('storeCompressedMemory succeeds', $cmpResult === true);

// Prune old memories
$pruneResult = pruneOldMemories($pdo, $user_id, 9999); // max_age_days very high so it won't delete our data
test('pruneOldMemories returns array', is_array($pruneResult));
test('pruneOldMemories has expected keys', isset($pruneResult['conversations_deleted']));

// Cleanup expired sessions
$cleaned = cleanupExpiredSessions($pdo);
test('cleanupExpiredSessions returns int', is_int($cleaned));

// ═══════════════════════════════════════
// 7. ACTION REGISTRY
// ═══════════════════════════════════════
section('7. Action Registry');

require_once __DIR__ . '/../public_html/admin/ai/actions/action-registry.php';
require_once __DIR__ . '/../public_html/admin/ai/actions/action-executor.php';

$definitions = getActionDefinitions();
test('getActionDefinitions returns array', is_array($definitions));
test('getActionDefinitions has create_customer', isset($definitions['create_customer']));
test('getActionDefinitions has create_order', isset($definitions['create_order']));
test('getActionDefinitions has register_cylinder', isset($definitions['register_cylinder']));
test('getActionDefinitions has record_payment', isset($definitions['record_payment']));
test('getActionDefinitions has exchange_settlement', isset($definitions['exchange_settlement']));
test('getActionDefinitions has borrow_from_partner', isset($definitions['borrow_from_partner']));

$descriptions = getActionDescriptionsForRole('super_admin');
test('getActionDescriptionsForRole returns array', is_array($descriptions));

$descClerk = getActionDescriptionsForRole('billing_clerk');
test('getActionDescriptionsForRole(billing_clerk) returns array', is_array($descClerk));

// Execute some actions (in transaction, rolled back)
$pdo->beginTransaction();
$actionTestError = null;
try {
    // actionCreateCustomer
    $r = actionCreateCustomer($pdo, [
        'name' => 'AI Test Customer',
        'mobile' => '9999999988',
        'address' => 'AI Test Address',
        'customer_type' => 'refill',
    ]);
    test('actionCreateCustomer success', $r['success'] === true);
    $customerId = $r['data']['customer_id'] ?? 0;
    test('actionCreateCustomer returns customer_id', $customerId > 0);

    // actionCreateGasType
    $r2 = actionCreateGasType($pdo, [
        'name' => 'AI Test Gas',
        'chemical_formula' => 'AIT',
        'sizes' => '10L,40L',
        'default_price_per_kg' => 100,
    ]);
    test('actionCreateGasType success', $r2['success'] === true);
    $gasTypeId = $r2['data']['gas_type_id'] ?? 0;
    test('actionCreateGasType returns gas_type_id', $gasTypeId > 0);
    
    // actionRegisterCylinder
    $r3 = actionRegisterCylinder($pdo, [
        'serial_number' => 'AI-TEST-' . time(),
        'gas_type_id' => $gasTypeId ?: 1,
        'size_capacity' => '40L',
        'status' => 'filled',
    ]);
    test('actionRegisterCylinder success', $r3['success'] === true);
    $cylinderId = $r3['data']['cylinder_id'] ?? 0;
    test('actionRegisterCylinder returns cylinder_id', $cylinderId > 0);

    // actionCreateVendor
    $r4 = actionCreateVendor($pdo, [
        'name' => 'AI Test Vendor',
        'mobile' => '9999999977',
    ]);
    test('actionCreateVendor success', $r4['success'] === true);

    // actionCreatePartner
    $r5 = actionCreatePartner($pdo, [
        'name' => 'AI Test Partner',
        'mobile' => '9999999966',
        'company_name' => 'AI Test Co',
        'contact_person' => 'AI Person',
    ]);
    test('actionCreatePartner success', $r5['success'] === true);

    // actionRecordPayment
    $r6 = actionRecordPayment($pdo, [
        'customer_id' => 2,
        'amount' => 500,
        'payment_type' => 'deposit_added',
        'payment_method' => 'cash',
    ]);
    test('actionRecordPayment success', $r6['success'] === true);

    // executeAction wrapper
    $r7 = executeAction($pdo, 'create_customer', ['name'=>'Exec Test','mobile'=>'9999999955'], 'super_admin');
    test('executeAction(create_customer) success', $r7['success'] === true);

    // Action with invalid role
    $r8 = executeAction($pdo, 'create_customer', ['name'=>'Fail Test','mobile'=>'9999999944'], 'viewer');
    test('executeAction with viewer role fails', $r8['success'] === false);

} catch (Exception $e) {
    $actionTestError = $e->getMessage();
}
if ($pdo->inTransaction()) { $pdo->rollBack(); }
test('AI action tests completed' . ($actionTestError ? ': ' . $actionTestError : ''), $actionTestError === null);

// ═══════════════════════════════════════
// 8. SCHEMA EXPLORER
// ═══════════════════════════════════════
section('8. Schema Explorer');

require_once __DIR__ . '/../public_html/admin/ai/schema/schema-explorer.php';

$schema = discoverSchema($pdo);
test('discoverSchema returns array', is_array($schema));
test('discoverSchema has tables', isset($schema['tables']) && count($schema['tables']) > 0);
test('discoverSchema has relationships', isset($schema['relationships']));

$tables = getSchemaTables($pdo);
test('getSchemaTables returns array', is_array($tables) && count($tables) > 0);
test('getSchemaTables includes cylinders', in_array('cylinders', $tables));
test('getSchemaTables includes customers', in_array('customers', $tables));

$columns = getSchemaColumns($pdo, 'cylinders');
test('getSchemaColumns(cylinders) returns array', is_array($columns) && count($columns) > 0);
$colNames = array_keys($columns);
test('cylinders has serial_number', in_array('serial_number', $colNames));

$searchCols = getSearchableColumns($pdo, 'customers');
test('getSearchableColumns(customers) returns array', is_array($searchCols));

$relations = getTableRelationships($pdo);
test('getTableRelationships returns array', is_array($relations));

$related = findRelatedTables($pdo, 'cylinders', 2);
test('findRelatedTables(cylinders) returns array', is_array($related));

$tablesForEntity = findTablesForEntity($pdo, 'cylinders');
test('findTablesForEntity(cylinders) returns array', is_array($tablesForEntity));

$softDelete = detectSoftDeleteColumns($pdo);
test('detectSoftDeleteColumns returns array', is_array($softDelete));

$schemaPrompt = formatSchemaForPrompt($pdo);
test('formatSchemaForPrompt returns string', is_string($schemaPrompt) && strlen($schemaPrompt) > 100);

$schemaPrompt2 = formatSchemaForPromptWithSoftDelete($pdo);
test('formatSchemaForPromptWithSoftDelete returns string', is_string($schemaPrompt2) && strlen($schemaPrompt2) > 100);

$entityIdCol = getEntityIdentifierColumn($pdo, 'customer', 'customers');
test('getEntityIdentifierColumn returns column', is_string($entityIdCol) && strlen($entityIdCol) > 0);

// ═══════════════════════════════════════
// 9. DATA AGGREGATOR
// ═══════════════════════════════════════
section('9. Data Aggregator');

require_once __DIR__ . '/../public_html/admin/ai/analytics/data-aggregator.php';

$sales = getSalesMetrics($pdo, 'today');
test('getSalesMetrics returns array', is_array($sales));
test('getSalesMetrics has order_count', isset($sales['order_count']));
test('getSalesMetrics has total_revenue', isset($sales['total_revenue']));

$cylMetrics = getCylinderMetrics($pdo);
test('getCylinderMetrics returns array', is_array($cylMetrics));
test('getCylinderMetrics has total_cylinders', isset($cylMetrics['total_cylinders']));
test('getCylinderMetrics has filled count', isset($cylMetrics['filled']));

$invSummary = getInventorySummary($pdo);
test('getInventorySummary returns array', is_array($invSummary));

$lowStock = getLowStockAlerts($pdo);
test('getLowStockAlerts returns array', is_array($lowStock));

$custMetrics = getCustomerMetrics($pdo);
test('getCustomerMetrics returns array', is_array($custMetrics));
test('getCustomerMetrics has total_customers', isset($custMetrics['total_customers']));

$topGases = getTopSellingGasTypes($pdo, 5);
test('getTopSellingGasTypes returns array', is_array($topGases));

$pmBreakdown = getPaymentMethodBreakdown($pdo, 'month');
test('getPaymentMethodBreakdown returns array', is_array($pmBreakdown));

$partnerMetrics = getPartnerExchangeMetrics($pdo);
test('getPartnerExchangeMetrics returns array', is_array($partnerMetrics));

$snapshot = getBusinessSnapshot($pdo);
test('getBusinessSnapshot returns array', is_array($snapshot));
test('getBusinessSnapshot has sales', isset($snapshot['sales']));
test('getBusinessSnapshot has cylinders', isset($snapshot['cylinders']));
test('getBusinessSnapshot has customers', isset($snapshot['customers']));

$formatted = formatMetricsForPrompt($snapshot);
test('formatMetricsForPrompt returns string', is_string($formatted) && strlen($formatted) > 50);

// ═══════════════════════════════════════
// 10. FORECASTER
// ═══════════════════════════════════════
section('10. Forecaster');

require_once __DIR__ . '/../public_html/admin/ai/analytics/forecaster.php';

$dataPoints = [100, 110, 105, 115, 120, 118, 125];
$regression = linearRegression($dataPoints);
test('linearRegression returns array with slope', $regression !== null && isset($regression['slope']));
test('linearRegression has intercept', isset($regression['intercept']));
if ($regression) {
    test('linearRegression slope is numeric', is_numeric($regression['slope']));
}

$predictions = predictNextValues($dataPoints, 3);
test('predictNextValues returns array', is_array($predictions));
test('predictNextValues has correct count', count($predictions) === 3);

// These queries may return empty data but should not crash
$forecast = getSalesForecast($pdo, 7, 3);
test('getSalesForecast returns array', is_array($forecast));
test('getSalesForecast has forecast key', isset($forecast['forecast']));

$orderForecast = getOrderVolumeForecast($pdo, 7, 3);
test('getOrderVolumeForecast returns array', is_array($orderForecast));

$depletion = getInventoryDepletionRate($pdo, 30);
test('getInventoryDepletionRate returns array', is_array($depletion));

$forecastPrompt = formatForecastForPrompt($pdo);
test('formatForecastForPrompt returns string', is_string($forecastPrompt));

// ═══════════════════════════════════════
// 11. TREND ANALYZER
// ═══════════════════════════════════════
section('11. Trend Analyzer');

require_once __DIR__ . '/../public_html/admin/ai/analytics/trend-analyzer.php';

$salesTrend = getSalesTrend($pdo, 'daily', 7);
test('getSalesTrend returns array', is_array($salesTrend));

$wow = compareWeekOverWeek($pdo);
test('compareWeekOverWeek returns array', is_array($wow));
test('compareWeekOverWeek has current_week_revenue', isset($wow['current_week_revenue']));
test('compareWeekOverWeek has direction', isset($wow['direction']));

$mom = compareMonthOverMonth($pdo);
test('compareMonthOverMonth returns array', is_array($mom));

$util = getCylinderUtilizationTrend($pdo, 30);
test('getCylinderUtilizationTrend returns array', is_array($util));

$yoy = compareYearOverYear($pdo);
test('compareYearOverYear returns array', is_array($yoy));

$custom = comparePeriodCustom($pdo, date('Y-m-d', strtotime('-30 days')), date('Y-m-d'), date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('-31 days')));
test('comparePeriodCustom returns array', is_array($custom));

$acq = getCustomerAcquisitionTrend($pdo, 30);
test('getCustomerAcquisitionTrend returns array', is_array($acq));

$topCust = getTopCustomersTrend($pdo, 5);
test('getTopCustomersTrend returns array', is_array($topCust));

$trendPrompt = formatTrendForPrompt($pdo);
test('formatTrendForPrompt returns string', is_string($trendPrompt));

// ═══════════════════════════════════════
// 12. AI SETUP (migration idempotence)
// ═══════════════════════════════════════
section('12. AI Setup Idempotent');

// Running again should not error
runAIConfigMigration($pdo);
test('runAIConfigMigration idempotent (second call no error)', true);

runAIMigrations($pdo);
test('runAIMigrations idempotent (second call no error)', true);

// ═══════════════════════════════════════
// RESULTS
// ═══════════════════════════════════════
echo "\n════════════════════════════════════════════════════════════\n";
echo "  RESULTS:  $pass / $total passed";
if ($fail > 0) echo "  —  $fail FAILED";
else echo "  —  ALL TESTS PASSED ✓";
echo "\n════════════════════════════════════════════════════════════\n\n";

file_put_contents(__DIR__ . '/../docs/testing/ai_test_results_latest.json', json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'total' => $total,
    'passed' => $pass,
    'failed' => $fail,
], JSON_PRETTY_PRINT));

exit($fail > 0 ? 1 : 0);
