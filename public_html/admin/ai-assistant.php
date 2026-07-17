<?php
require_once __DIR__ . '/lang_init.php';
$page_title = 'AI Assistant';
$active_menu = 'ai_assistant';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai/ai-config.php';
runAIConfigMigration($pdo);
$config = getAIConfig($pdo);
$ai_configured = !empty($config['api_key']);
$default_lang = $config['language_mode'] ?? 'hinglish';
$azure_tts_available = !empty($config['azure_tts_key']) && !empty($config['azure_tts_region']);
?>
<link rel="stylesheet" href="ai-assistant.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
window.AI_ASSISTANT_I18N = {
    confidence: '<?= __('ai_assistant.confidence') ?>',
    rate_title: '<?= __('ai_assistant.rate_title') ?>',
    error: '<?= __('ai_assistant.error') ?>',
    voice_not_supported: '<?= __('ai_assistant.voice_not_supported') ?>',
    feedback_thanks: '<?= __('ai_assistant.feedback_thanks') ?>',
    suggestion4: '<?= __('ai_assistant.suggestion4') ?>'
};
window.exportConversation = function(format) {
    var sid = sessionStorage.getItem('ai_session_id') || '';
    if (!sid) { alert('No conversation session found.'); return; }
    window.open('ai-export.php?session_id=' + encodeURIComponent(sid) + '&format=' + format, '_blank');
};
</script>

<div class="ai-assistant-container">
    <?php if (!$ai_configured): ?>
    <div class="alert-box alert-warning" style="margin-bottom:20px;">
        <strong><?= __('ai_assistant.no_config') ?></strong>
    </div>
    <?php else: ?>
    <div class="chat-header">
        <div class="chat-header-avatar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a4 4 0 0 1 4 4v2a4 4 0 0 1-8 0V6a4 4 0 0 1 4-4z"/><path d="M2 22c0-4 4-7 10-7s10 3 10 7"/></svg>
        </div>
        <div class="chat-header-info">
            <div class="chat-header-title">AI Assistant</div>
            <div class="chat-header-sub">Prem Gas Solution Business Intelligence</div>
        </div>
        <div class="chat-header-status">
            <span class="status-dot"></span> Online
            <div class="chat-header-actions">
                <button class="export-btn" onclick="exportConversation('csv')" title="Export as CSV">CSV</button>
                <button class="export-btn" onclick="exportConversation('pdf')" title="Export as PDF (print)">PDF</button>
            </div>
        </div>
    </div>
    <div id="chatMessages" class="chat-messages">
        <div class="chat-message assistant" style="animation-delay: 0.1s;">
            <div class="message-bubble">
                <?= htmlspecialchars(__('ai_assistant.greeting')) ?>
            </div>
        </div>
    </div>

    <div class="quick-actions">
        <span class="quick-action-chip" data-action="inventory">📦 <?= __('ai_assistant.qa_inventory') ?></span>
        <span class="quick-action-chip" data-action="sales">💰 <?= __('ai_assistant.qa_sales') ?></span>
        <span class="quick-action-chip" data-action="customers">👥 <?= __('ai_assistant.qa_customers') ?></span>
        <span class="quick-action-chip" data-action="cylinders">🛢️ <?= __('ai_assistant.qa_cylinders') ?></span>
        <span class="quick-action-chip" data-action="dashboard">📊 <?= __('ai_assistant.qa_dashboard') ?></span>
    </div>

    <div id="suggestionChips" class="suggestion-chips">
        <span class="suggestion-chip" data-text="<?= htmlspecialchars(__('ai_assistant.suggestion1')) ?>" data-index="1">
            <?= htmlspecialchars(__('ai_assistant.suggestion1')) ?>
        </span>
        <span class="suggestion-chip" data-text="<?= htmlspecialchars(__('ai_assistant.suggestion2')) ?>" data-index="2">
            <?= htmlspecialchars(__('ai_assistant.suggestion2')) ?>
        </span>
        <span class="suggestion-chip" data-text="<?= htmlspecialchars(__('ai_assistant.suggestion3')) ?>" data-index="3">
            <?= htmlspecialchars(__('ai_assistant.suggestion3')) ?>
        </span>
        <span class="suggestion-chip" data-text="<?= htmlspecialchars(__('ai_assistant.suggestion4')) ?>" data-index="4">
            <?= htmlspecialchars(__('ai_assistant.suggestion4')) ?>
        </span>
    </div>

    <div class="chat-input-area">
        <button id="voiceBtn" class="voice-btn" title="<?= __('ai_assistant.speak_now') ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
        </button>
        <button id="voiceOutputToggle" class="voice-output-btn active" title="Voice output on">&#x1F50A;</button>
        <select id="langToggle" class="lang-toggle" title="Response language" aria-label="Response language">
            <option value="hinglish" <?= $default_lang === 'hinglish' ? 'selected' : '' ?>>Hinglish</option>
            <option value="english" <?= $default_lang === 'english' ? 'selected' : '' ?>>English</option>
            <option value="hindi" <?= $default_lang === 'hindi' ? 'selected' : '' ?>>Hindi</option>
        </select>
        <?php if ($azure_tts_available): ?>
        <button id="azureTtsToggle" class="azure-tts-btn" title="High quality neural voice (Azure)">&#x1F3A4;</button>
        <?php endif; ?>
        <input type="text" id="chatInput" class="chat-input" placeholder="<?= htmlspecialchars(__('ai_assistant.placeholder')) ?>" autofocus aria-label="Chat message">
        <button id="sendBtn" class="send-btn" title="<?= __('common.send') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </button>
    </div>

    <div id="voiceStatus" class="voice-status" style="display:none;"><?= __('ai_assistant.listen') ?></div>
    <div id="voiceInterim" class="voice-interim"></div>
    <?php endif; ?>
</div>

<div id="feedbackModal" class="feedback-modal" style="display:none;">
    <div class="feedback-modal-content">
        <h3><?= __('ai_assistant.rate_title') ?></h3>
        <div class="star-rating">
            <span class="star" data-value="1">&#9733;</span>
            <span class="star" data-value="2">&#9733;</span>
            <span class="star" data-value="3">&#9733;</span>
            <span class="star" data-value="4">&#9733;</span>
            <span class="star" data-value="5">&#9733;</span>
        </div>
        <textarea id="feedbackText" class="feedback-text" placeholder="<?= htmlspecialchars(__('ai_assistant.feedback_placeholder')) ?>" rows="3" aria-label="Feedback description"></textarea>
        <div class="feedback-actions">
            <button id="submitFeedback" class="btn-primary"><?= __('ai_assistant.submit_feedback') ?></button>
            <button id="cancelFeedback" class="btn-secondary"><?= __('common.cancel') ?></button>
        </div>
    </div>
</div>




<script src="ai-assistant.min.js"></script>

<?php require_once 'layout_footer.php'; ?>
