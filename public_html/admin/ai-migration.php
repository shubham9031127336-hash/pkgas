<?php
/**
 * AI System — Idempotent Database Migration
 * Creates all AI support tables. Safe to run multiple times.
 * Follows the same try-catch pattern as inventory-utils.php
 */

if (!function_exists('runAIMigrations')) {
    function runAIMigrations($pdo) {
        $migrations = [];

        // 1. ai_config
        $migrations[] = "CREATE TABLE IF NOT EXISTS `ai_config` (
            `id` INT PRIMARY KEY DEFAULT 1,
            `provider` VARCHAR(50) NOT NULL DEFAULT 'openrouter',
            `api_key` TEXT NOT NULL DEFAULT '',
            `model` VARCHAR(100) NOT NULL DEFAULT 'openai/gpt-4o-mini',
            `max_tokens` INT NOT NULL DEFAULT 2048,
            `temperature` DECIMAL(3,2) NOT NULL DEFAULT 0.70,
            `cache_ttl` INT NOT NULL DEFAULT 3600,
            `system_prompt` TEXT DEFAULT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $migrations[] = "INSERT IGNORE INTO ai_config (id) VALUES (1)";

        // 2. ai_user_memory
        $migrations[] = "CREATE TABLE IF NOT EXISTS `ai_user_memory` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `memory_key` VARCHAR(100) NOT NULL,
            `memory_value` TEXT NOT NULL,
            `confidence` DECIMAL(3,2) NOT NULL DEFAULT 0.30,
            `context_tags` VARCHAR(255) DEFAULT NULL,
            `last_accessed` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_user_key` (`user_id`, `memory_key`),
            INDEX `idx_confidence` (`confidence`),
            INDEX `idx_accessed` (`last_accessed`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        // Column migrations for existing installations
        try { $pdo->query("SELECT 1 FROM ai_user_memory WHERE 1=0 GROUP BY user_id, memory_key HAVING COUNT(*) > 1 LIMIT 1"); }
        catch (PDOException $e) {}
        try { $pdo->exec("ALTER TABLE `ai_user_memory` ADD UNIQUE KEY `uk_user_key` (`user_id`, `memory_key`)"); }
        catch (PDOException $e) {}

        // 3. ai_role_memory
        $migrations[] = "CREATE TABLE IF NOT EXISTS `ai_role_memory` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `role` VARCHAR(50) NOT NULL,
            `memory_key` VARCHAR(100) NOT NULL,
            `memory_value` TEXT NOT NULL,
            `priority` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `role_key` (`role`, `memory_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 4. ai_session_context
        $migrations[] = "CREATE TABLE IF NOT EXISTS `ai_session_context` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `session_id` VARCHAR(128) NOT NULL,
            `user_id` INT NOT NULL,
            `context_data` JSON NOT NULL,
            `expires_at` TIMESTAMP NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_session` (`session_id`),
            INDEX `idx_user` (`user_id`),
            INDEX `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try { $pdo->exec("ALTER TABLE `ai_session_context` ADD UNIQUE KEY `uk_session` (`session_id`)"); }
        catch (PDOException $e) {}

        // 5. ai_conversations
        $migrations[] = "CREATE TABLE IF NOT EXISTS `ai_conversations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `session_id` VARCHAR(128) NOT NULL,
            `user_id` INT NOT NULL,
            `role` VARCHAR(50) NOT NULL,
            `message` TEXT NOT NULL,
            `intent` VARCHAR(100) DEFAULT NULL,
            `confidence` DECIMAL(3,2) DEFAULT NULL,
            `response_time_ms` INT DEFAULT NULL,
            `tokens_used` INT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_session` (`session_id`),
            INDEX `idx_user` (`user_id`),
            INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 6. ai_workflow_memory
        $migrations[] = "CREATE TABLE IF NOT EXISTS `ai_workflow_memory` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `workflow_name` VARCHAR(100) NOT NULL,
            `trigger_pattern` TEXT DEFAULT NULL,
            `frequency` INT NOT NULL DEFAULT 1,
            `last_executed` TIMESTAMP DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `workflow` (`workflow_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 7. ai_feedback
        $migrations[] = "CREATE TABLE IF NOT EXISTS `ai_feedback` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `conversation_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `rating` TINYINT DEFAULT NULL,
            `feedback_text` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_conversation` (`conversation_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // Seed role memory defaults
        $migrations[] = "INSERT IGNORE INTO `ai_role_memory` (`role`, `memory_key`, `memory_value`, `priority`) VALUES
            ('super_admin', 'focus', 'Strategic insights, analytics, staff management, full system access', 10),
            ('super_admin', 'permission_scope', 'Can access all modules and data without restriction', 10),
            ('billing_clerk', 'focus', 'Customer-facing operations: orders, payments, customer queries', 10),
            ('billing_clerk', 'permission_scope', 'Limited to customers, orders, payments, and exchange modules', 10),
            ('warehouse_supervisor', 'focus', 'Inventory management, cylinder tracking, vendor dispatch, partner exchange', 10),
            ('warehouse_supervisor', 'permission_scope', 'Limited to cylinders, inventory, vendors, gas types, and partners', 10)";

        foreach ($migrations as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                error_log("ai-migration: " . $e->getMessage());
            }
        }
    }
}
