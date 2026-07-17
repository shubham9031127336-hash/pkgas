<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai/ai-config.php';
require_once __DIR__ . '/ai-migration.php';

echo "<h2>AI System — Database Setup</h2>";
echo "<pre>";

// Step 1: Create/update ai_config table
echo "Running AI config migration...\n";
runAIConfigMigration($pdo);
echo "  ✓ ai_config table ready\n";

// Step 2: Create all 7 AI tables
echo "Running AI tables migration...\n";
runAIMigrations($pdo);
echo "  ✓ ai_user_memory table ready\n";
echo "  ✓ ai_role_memory table ready\n";
echo "  ✓ ai_session_context table ready\n";
echo "  ✓ ai_conversations table ready\n";
echo "  ✓ ai_workflow_memory table ready\n";
echo "  ✓ ai_feedback table ready\n";

// Step 3: Verify tables exist
echo "\nVerifying tables...\n";
$tables = ['ai_config', 'ai_user_memory', 'ai_role_memory', 'ai_session_context', 'ai_conversations', 'ai_workflow_memory', 'ai_feedback'];
$all_ok = true;
foreach ($tables as $table) {
    try {
        $pdo->query("SELECT 1 FROM $table LIMIT 0");
        echo "  ✓ $table exists\n";
    } catch (PDOException $e) {
        echo "  ✗ $table MISSING\n";
        $all_ok = false;
    }
}

// Step 4: Check seed data in ai_role_memory
$count = $pdo->query("SELECT COUNT(*) FROM ai_role_memory")->fetchColumn();
echo "\nRole memory seed records: $count\n";

if ($all_ok) {
    echo "\n✅ All AI tables installed successfully!\n";
    echo "\nNext steps:\n";
    echo "  1. Go to Settings → AI Configuration to enter your API key\n";
    echo "  2. Click Test Connection to verify\n";
    echo "  3. Visit AI Assistant from the sidebar to start chatting\n";
} else {
    echo "\n❌ Some tables failed to create. Check error logs.\n";
}

echo "\n⚠️  DELETE THIS FILE (ai-setup.php) after setup is complete!\n";
echo "</pre>";
