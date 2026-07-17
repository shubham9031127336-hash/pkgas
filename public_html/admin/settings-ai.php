<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/lang_init.php';
require_login();
$page_title = __('settings_ai.title');
$active_menu = "settings_ai";

require_once __DIR__ . '/layout.php';
require_role('super_admin');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai/ai-config.php';
require_once __DIR__ . '/ai-helper.php';
runAIConfigMigration($pdo);

$message = '';
$error = '';

// Handle AI config save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_ai_config') {
    $provider = $_POST['ai_provider'] ?? 'openrouter';
    $api_key = $_POST['ai_api_key'] ?? '';
    $existing_key = $provider_keys[$provider] ?? '';
    if ($api_key === substr($existing_key, 0, 6) . '...' . substr($existing_key, -4)) {
        $api_key = $existing_key;
    }
    $provider_key_field = $provider . '_api_key';
    $config = array(
        'provider' => $provider,
        'api_key' => $api_key,
        $provider_key_field => $api_key,
        'base_url' => $_POST['ai_base_url'] ?? '',
        'model' => $_POST['ai_model'] ?? 'openai/gpt-4o-mini',
        'max_tokens' => intval($_POST['ai_max_tokens'] ?? 2048),
        'temperature' => floatval($_POST['ai_temperature'] ?? 0.70),
        'cache_ttl' => intval($_POST['ai_cache_ttl'] ?? 3600),
        'system_prompt' => $_POST['ai_system_prompt'] ?? '',
        'language_mode' => $_POST['ai_language_mode'] ?? 'hinglish',
        'azure_tts_enabled' => intval($_POST['ai_azure_tts_enabled'] ?? 0),
        'azure_tts_key' => $_POST['ai_azure_tts_key'] ?? '',
        'azure_tts_region' => $_POST['ai_azure_tts_region'] ?? '',
        'azure_tts_voice' => $_POST['ai_azure_tts_voice'] ?? 'hi-IN-SwaraNeural',
    );
    $ok = saveAIConfig($pdo, $config);
    if ($ok) {
        $message = __('ai.config_saved');
    } else {
        $error = __('ai.config_failed');
    }
}

$ai_config = getAIConfig($pdo);

$provider_keys = array(
    'openrouter' => $ai_config['openrouter_api_key'] ?? '',
    'openai' => $ai_config['openai_api_key'] ?? '',
    'gemini' => $ai_config['gemini_api_key'] ?? '',
    'groq' => $ai_config['groq_api_key'] ?? '',
    'ollama' => $ai_config['ollama_api_key'] ?? '',
);
$current_provider = $ai_config['provider'] ?? 'openrouter';
if (empty($provider_keys[$current_provider]) && !empty($ai_config['api_key'])) {
    $provider_keys[$current_provider] = $ai_config['api_key'];
}

$current_api_key = $provider_keys[$current_provider] ?? '';
$masked_api_key = $current_api_key ? substr($current_api_key, 0, 6) . '...' . substr($current_api_key, -4) : '';

$provider_presets = array(
    'openrouter' => array('label' => __('ai.provider_openrouter'), 'model_hint' => 'openai/gpt-4o-mini', 'base_url' => '', 'base_url_visible' => false, 'key_required' => true,),
    'openai' => array('label' => __('ai.provider_openai'), 'model_hint' => 'gpt-4o-mini', 'base_url' => '', 'base_url_visible' => false, 'key_required' => true,),
    'gemini' => array('label' => __('ai.provider_gemini'), 'model_hint' => 'gemini-2.0-flash', 'base_url' => '', 'base_url_visible' => false, 'key_required' => true,),
    'groq' => array('label' => __('ai.provider_groq'), 'model_hint' => 'llama-3.3-70b-versatile', 'base_url' => '', 'base_url_visible' => false, 'key_required' => true,),
    'ollama' => array('label' => __('ai.provider_ollama'), 'model_hint' => 'llama3.2', 'base_url' => 'http://localhost:11434/v1', 'base_url_visible' => true, 'key_required' => false,),
    'custom' => array('label' => __('ai.provider_custom'), 'model_hint' => 'gpt-4o-mini', 'base_url' => '', 'base_url_visible' => true, 'key_required' => false,),
);
$preset = $provider_presets[$current_provider] ?? $provider_presets['openrouter'];
?>
<div style="margin-bottom: 2rem;">
    <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;"><?php echo __('settings_ai.heading'); ?></h2>
    <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;"><?php echo __('settings_ai.subtitle'); ?></p>
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

<div class="admin-card">
    <h3 class="card-title"><?php echo __('ai.config_title'); ?></h3>
    <p style="color: var(--admin-muted); font-size: 0.85rem; margin-top: 0.25rem; margin-bottom: 1.5rem;">
        <?php echo __('ai.config_desc'); ?>
    </p>

    <form method="POST" id="aiConfigForm"><?php csrfField(); ?>
        <input type="hidden" name="action" value="save_ai_config">

        <div class="form-group">
            <label class="form-label"><?php echo __('ai.provider'); ?></label>
            <select name="ai_provider" class="form-control" id="aiProvider">
                <?php foreach ($provider_presets as $val => $p): ?>
                    <option value="<?php echo $val; ?>" data-base-url="<?php echo $p['base_url']; ?>" data-base-url-visible="<?php echo $p['base_url_visible'] ? '1' : '0'; ?>" data-model-hint="<?php echo $p['model_hint']; ?>" data-key-required="<?php echo $p['key_required'] ? '1' : '0'; ?>" <?php echo $current_provider === $val ? 'selected' : ''; ?>><?php echo $p['label']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" id="baseUrlGroup" style="display: <?php echo $preset['base_url_visible'] ? 'block' : 'none'; ?>;">
            <label class="form-label"><?php echo __('ai.base_url'); ?></label>
            <input type="text" name="ai_base_url" id="aiBaseUrl" class="form-control" value="<?php echo htmlspecialchars($ai_config['base_url'] ?: $preset['base_url']); ?>" placeholder="<?php echo __('ai.base_url_placeholder'); ?>">
            <span style="font-size: 0.7rem; color: var(--admin-muted);" id="baseUrlHint"><?php echo __('ai.base_url_hint'); ?></span>
        </div>

        <div class="form-group">
            <label class="form-label" id="apiKeyLabel"><?php echo __('ai.api_key'); ?></label>
            <div style="position: relative;">
                <input type="password" name="ai_api_key" id="ai_api_key" class="form-control" value="<?php echo htmlspecialchars($masked_api_key); ?>" placeholder="sk-... or your API key" style="padding-right: 40px;">
                <button type="button" id="toggleApiKey" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; padding: 4px; color: #94a3b8;" title="Show API key">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <span style="font-size: 0.7rem; color: var(--admin-muted);" id="apiKeyHint"><?php echo $preset['key_required'] ? '' : __('ai.api_key_optional'); ?></span>
        </div>

        <div class="form-group">
            <label class="form-label"><?php echo __('ai.model'); ?></label>
            <input type="text" name="ai_model" id="aiModel" class="form-control" value="<?php echo htmlspecialchars($ai_config['model']); ?>" placeholder="<?php echo $preset['model_hint']; ?>">
            <span style="font-size: 0.7rem; color: var(--admin-muted);" id="modelHint"><?php echo __('ai.model_hint_prefix'); ?> <strong id="modelSuggestion"><?php echo $preset['model_hint']; ?></strong></span>
        </div>

        <div class="form-group">
            <label class="form-label"><?php echo __('ai.max_tokens'); ?></label>
            <input type="number" name="ai_max_tokens" class="form-control" value="<?php echo intval($ai_config['max_tokens']); ?>" min="256" max="16384" step="256">
        </div>

        <div class="form-group">
            <label class="form-label"><?php echo __('ai.temperature'); ?> (0.0 - 2.0)</label>
            <input type="range" name="ai_temperature" class="form-control" value="<?php echo floatval($ai_config['temperature']); ?>" min="0" max="2" step="0.1" oninput="this.nextElementSibling.textContent = this.value" style="padding: 0;">
            <span style="font-size: 0.8rem; font-weight: 700; color: var(--admin-accent);"><?php echo floatval($ai_config['temperature']); ?></span>
        </div>

        <div class="form-group">
            <label class="form-label"><?php echo __('ai.cache_ttl'); ?></label>
            <input type="number" name="ai_cache_ttl" class="form-control" value="<?php echo intval($ai_config['cache_ttl']); ?>" min="0" max="86400">
            <span style="font-size: 0.7rem; color: var(--admin-muted);"><?php echo __('ai.cache_ttl_hint'); ?></span>
        </div>

        <div class="form-group">
            <label class="form-label"><?php echo __('ai.language_mode'); ?></label>
            <select name="ai_language_mode" class="form-control">
                <option value="hinglish" <?php echo ($ai_config['language_mode'] ?? 'hinglish') === 'hinglish' ? 'selected' : ''; ?>>Hinglish (Hindi + English)</option>
                <option value="hindi" <?php echo ($ai_config['language_mode'] ?? '') === 'hindi' ? 'selected' : ''; ?>>Hindi</option>
                <option value="english" <?php echo ($ai_config['language_mode'] ?? '') === 'english' ? 'selected' : ''; ?>>English</option>
            </select>
            <span style="font-size: 0.7rem; color: var(--admin-muted);"><?php echo __('ai.language_mode_hint'); ?></span>
        </div>

        <hr style="border: none; border-top: 1px solid var(--admin-border); margin: 1.25rem 0;">

        <h4 style="font-weight: 700; font-size: 0.9rem; margin-bottom: 0.75rem;">Azure Neural TTS (Optional)</h4>
        <p style="color: var(--admin-muted); font-size: 0.8rem; margin-bottom: 1rem;">
            Configure Microsoft Azure Text-to-Speech for high-quality neural voice output.
            Requires an <a href="https://portal.azure.com/#view/Microsoft_Azure_ProjectOxford/CognitiveServicesHub/~/SpeechServices" target="_blank" rel="noopener">Azure Speech Services</a> subscription.
        </p>

        <div class="form-group">
            <label class="form-label">
                <input type="checkbox" name="ai_azure_tts_enabled" value="1" <?php echo !empty($ai_config['azure_tts_enabled']) ? 'checked' : ''; ?>>
                Enable Azure Neural TTS
            </label>
        </div>

        <div class="form-group">
            <label class="form-label">API Key</label>
            <input type="password" name="ai_azure_tts_key" class="form-control" value="<?php echo htmlspecialchars($ai_config['azure_tts_key'] ?? ''); ?>" placeholder="Your Azure Speech Services key">
        </div>

        <div class="form-group">
            <label class="form-label">Region</label>
            <input type="text" name="ai_azure_tts_region" class="form-control" value="<?php echo htmlspecialchars($ai_config['azure_tts_region'] ?? ''); ?>" placeholder="eastus, westus, southeastasia, etc.">
            <span style="font-size: 0.7rem; color: var(--admin-muted);">The Azure region where your Speech resource is deployed.</span>
        </div>

        <div class="form-group">
            <label class="form-label">Voice</label>
            <select name="ai_azure_tts_voice" class="form-control">
                <option value="hi-IN-SwaraNeural" <?php echo ($ai_config['azure_tts_voice'] ?? 'hi-IN-SwaraNeural') === 'hi-IN-SwaraNeural' ? 'selected' : ''; ?>>hi-IN-SwaraNeural (Hindi, Female)</option>
                <option value="hi-IN-MadhurNeural" <?php echo ($ai_config['azure_tts_voice'] ?? '') === 'hi-IN-MadhurNeural' ? 'selected' : ''; ?>>hi-IN-MadhurNeural (Hindi, Male)</option>
                <option value="en-IN-NeerjaNeural" <?php echo ($ai_config['azure_tts_voice'] ?? '') === 'en-IN-NeerjaNeural' ? 'selected' : ''; ?>>en-IN-NeerjaNeural (English, Female)</option>
                <option value="en-US-JennyNeural" <?php echo ($ai_config['azure_tts_voice'] ?? '') === 'en-US-JennyNeural' ? 'selected' : ''; ?>>en-US-JennyNeural (English US, Female)</option>
            </select>
        </div>

        <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
            <button type="submit" class="btn-primary" style="flex: 1; justify-content: center;">
                <?php echo __('ai.save_config'); ?>
            </button>
            <button type="button" class="btn-secondary" onclick="testAIConnection()" style="flex: 1; justify-content: center;">
                <?php echo __('ai.test_connection'); ?>
            </button>
        </div>
    </form>

    <div id="aiTestResult" style="display: none; margin-top: 1rem; padding: 0.75rem 1rem; border-radius: 10px; font-weight: 600; font-size: 0.85rem;"></div>
</div>

<script>
    var providerPresets = <?php echo json_encode($provider_presets); ?>;
    var providerKeys = <?php echo json_encode($provider_keys); ?>;

    document.getElementById('toggleApiKey')?.addEventListener('click', function() {
        var input = document.getElementById('ai_api_key');
        var svg = this.querySelector('svg');
        if (input.type === 'password') {
            input.type = 'text';
            svg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
        } else {
            input.type = 'password';
            svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        }
    });

    document.getElementById('aiProvider')?.addEventListener('change', function() {
        var val = this.value;
        var preset = providerPresets[val] || providerPresets['openrouter'];
        var keyEl = document.getElementById('ai_api_key');
        keyEl.value = providerKeys[val] || '';
        var modelEl = document.getElementById('aiModel');
        modelEl.placeholder = preset.model_hint;
        document.getElementById('modelSuggestion').textContent = preset.model_hint;
        var baseUrlGroup = document.getElementById('baseUrlGroup');
        var baseUrlEl = document.getElementById('aiBaseUrl');
        if (preset.base_url_visible) {
            baseUrlGroup.style.display = 'block';
            if (preset.base_url && !baseUrlEl.value) {
                baseUrlEl.value = preset.base_url;
            }
            baseUrlEl.placeholder = preset.base_url || 'https://your-api.example.com/v1';
        } else {
            baseUrlGroup.style.display = 'none';
        }
        var hintEl = document.getElementById('apiKeyHint');
        if (preset.key_required) {
            hintEl.textContent = '';
        } else {
            hintEl.textContent = '<?php echo __('ai.api_key_optional'); ?>';
        }
        var baseUrlHintEl = document.getElementById('baseUrlHint');
        if (val === 'ollama') {
            baseUrlHintEl.textContent = '<?php echo __('ai.ollama_hint'); ?>';
        } else if (val === 'custom') {
            baseUrlHintEl.textContent = '<?php echo __('ai.custom_hint'); ?>';
        } else {
            baseUrlHintEl.textContent = '<?php echo __('ai.base_url_hint'); ?>';
        }
    });

    function testAIConnection() {
        var resultDiv = document.getElementById('aiTestResult');
        resultDiv.style.display = 'block';
        resultDiv.style.background = 'var(--info-soft)';
        resultDiv.style.color = 'var(--info)';
        resultDiv.style.border = '1px solid #bae6fd';
        resultDiv.textContent = '<?php echo __('ai.testing'); ?>';
        var apiKey = document.querySelector('[name="ai_api_key"]').value;
        var model = document.querySelector('[name="ai_model"]').value;
        var provider = document.querySelector('[name="ai_provider"]').value;
        var baseUrl = document.querySelector('[name="ai_base_url"]')?.value || '';
        fetch('ai-helper.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'test', api_key: apiKey, model: model, provider: provider, base_url: baseUrl })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                resultDiv.style.background = 'var(--success-soft)';
                resultDiv.style.color = 'var(--success)';
                resultDiv.style.borderColor = '#a7f3d0';
                resultDiv.textContent = '<?php echo __('ai.test_success'); ?>'.replace('%s', data.response);
            } else {
                resultDiv.style.background = 'var(--danger-soft)';
                resultDiv.style.color = 'var(--danger)';
                resultDiv.style.borderColor = '#fca5a5';
                resultDiv.textContent = '<?php echo __('ai.test_failed'); ?>'.replace('%s', data.error || 'Unknown error');
            }
        })
        .catch(function(err) {
            resultDiv.style.background = 'var(--danger-soft)';
            resultDiv.style.color = 'var(--danger)';
            resultDiv.style.borderColor = '#fca5a5';
            resultDiv.textContent = '<?php echo __('ai.test_failed'); ?>'.replace('%s', err.message);
        });
    }
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
