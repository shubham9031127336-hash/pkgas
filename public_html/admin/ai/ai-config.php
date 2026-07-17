<?php
/**
 * AI Configuration Read/Write Layer
 * Reads from and writes to the `ai_config` database table.
 */

if (!function_exists("runAIConfigMigration")) {
    function runAIConfigMigration($pdo) {
        try {
            $pdo->query("SELECT id FROM ai_config LIMIT 0");
        } catch (PDOException $e) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_config` (
                `id` INT PRIMARY KEY DEFAULT 1,
                `provider` VARCHAR(50) NOT NULL DEFAULT 'openrouter',
                `api_key` TEXT NOT NULL DEFAULT '',
                `model` VARCHAR(100) NOT NULL DEFAULT 'openai/gpt-4o-mini',
                `max_tokens` INT NOT NULL DEFAULT 2048,
                `temperature` DECIMAL(3,2) NOT NULL DEFAULT 0.70,
                `cache_ttl` INT NOT NULL DEFAULT 3600,
                `system_prompt` TEXT DEFAULT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("INSERT IGNORE INTO ai_config (id) VALUES (1)");
        }

        $newCols = [
            'language_mode VARCHAR(20) NOT NULL DEFAULT \'hinglish\'',
            'azure_tts_enabled TINYINT(1) NOT NULL DEFAULT 0',
            'azure_tts_key TEXT NOT NULL DEFAULT \'\'',
            'azure_tts_region VARCHAR(100) NOT NULL DEFAULT \'\'',
            'azure_tts_voice VARCHAR(100) NOT NULL DEFAULT \'hi-IN-SwaraNeural\'',
            'base_url VARCHAR(255) NOT NULL DEFAULT \'\'',
            'openai_api_key TEXT NOT NULL DEFAULT \'\'',
            'gemini_api_key TEXT NOT NULL DEFAULT \'\'',
            'ollama_api_key TEXT NOT NULL DEFAULT \'\'',
            'openrouter_api_key TEXT NOT NULL DEFAULT \'\'',
            'groq_api_key TEXT NOT NULL DEFAULT \'\'',
        ];
        foreach ($newCols as $colDef) {
            $parts = explode(' ', $colDef);
            $colName = $parts[0];
            try {
                $pdo->query("SELECT `$colName` FROM ai_config LIMIT 0");
            } catch (PDOException $e) {
                try {
                    $pdo->exec("ALTER TABLE ai_config ADD COLUMN $colDef");
                } catch (PDOException $e2) {
                    error_log("runAIConfigMigration: could not add column $colName: " . $e2->getMessage());
                }
            }
        }
    }
}

if (!function_exists("getAIConfig")) {
    function getAIConfig($pdo) {
    $defaults = [
        "provider" => "openrouter",
        "api_key" => "",
        "model" => "openai/gpt-4o-mini",
        "max_tokens" => 2048,
        "temperature" => 0.70,
        "cache_ttl" => 3600,
        "system_prompt" => "",
        "language_mode" => "hinglish",
        "azure_tts_enabled" => 0,
        "azure_tts_key" => "",
        "azure_tts_region" => "",
        "azure_tts_voice" => "hi-IN-SwaraNeural",
        "base_url" => "",
        "openai_api_key" => "",
        "gemini_api_key" => "",
        "ollama_api_key" => "",
        "openrouter_api_key" => "",
        "groq_api_key" => "",
    ];
        try {
            $stmt = $pdo->query("SELECT * FROM ai_config WHERE id = 1");
            $config = $stmt->fetch();
            if ($config) {
                return array_merge($defaults, $config);
            }
        } catch (PDOException $e) {
            error_log("getAIConfig failed: " . $e->getMessage());
        }
        return $defaults;
    }
}

if (!function_exists("saveAIConfig")) {
    function saveAIConfig($pdo, $data) {
        try {
            $columns = ['id', 'provider', 'api_key', 'model', 'max_tokens', 'temperature', 'cache_ttl', 'system_prompt',
                         'language_mode', 'azure_tts_enabled', 'azure_tts_key', 'azure_tts_region', 'azure_tts_voice',
                         'base_url', 'openai_api_key', 'gemini_api_key', 'ollama_api_key', 'openrouter_api_key', 'groq_api_key'];
            $placeholders = array_fill(0, count($columns), '?');
            $updateParts = [];
            foreach ($columns as $col) {
                if ($col === 'id') continue;
                $updateParts[] = "$col = VALUES($col)";
            }

            $sql = "INSERT INTO ai_config (" . implode(', ', $columns) . ")
                    VALUES (" . implode(', ', $placeholders) . ")
                    ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                1,
                $data["provider"] ?? "openrouter",
                $data["api_key"] ?? "",
                $data["model"] ?? "openai/gpt-4o-mini",
                intval($data["max_tokens"] ?? 2048),
                floatval($data["temperature"] ?? 0.70),
                intval($data["cache_ttl"] ?? 3600),
                $data["system_prompt"] ?? "",
                $data["language_mode"] ?? "hinglish",
                intval($data["azure_tts_enabled"] ?? 0),
                $data["azure_tts_key"] ?? "",
                $data["azure_tts_region"] ?? "",
                $data["azure_tts_voice"] ?? "hi-IN-SwaraNeural",
                $data["base_url"] ?? "",
                $data["openai_api_key"] ?? "",
                $data["gemini_api_key"] ?? "",
                $data["ollama_api_key"] ?? "",
                $data["openrouter_api_key"] ?? "",
                $data["groq_api_key"] ?? "",
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("saveAIConfig failed: " . $e->getMessage());
            return false;
        }
    }
}
