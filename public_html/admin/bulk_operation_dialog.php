<?php
if (!function_exists('renderBulkOperationDialogs')) {
    function renderBulkOperationDialogs() {
        $lang = __('bulk_op.confirm_operation');
        ?>
<!-- ===== Bulk Operation Analysis Modal ===== -->
<div class="modal" id="bulkOpAnalyzeModal">
    <div class="modal-content bulk-modal-content">
        <div class="bulk-modal-header">
            <span class="bulk-header-icon" id="bulkOpHeaderIcon">&#9888;</span>
            <div class="bulk-header-text">
                <h3 id="bulkOpHeaderTitle"><?php echo __('bulk_op.confirm_operation'); ?></h3>
                <p class="bulk-header-sub" id="bulkOpHeaderSub">Review the impact before proceeding</p>
            </div>
            <button class="modal-close" onclick="closeModal('bulkOpAnalyzeModal')" aria-label="Close">&times;</button>
        </div>

        <div class="bulk-modal-body" id="bulkOpAnalysisBody">
            <!-- Loading skeleton -->
            <div class="bulk-loading" id="bulkOpLoading">
                <div class="bulk-skeleton">
                    <div class="bulk-skel-row"><div class="bulk-skel-block w-60"></div><div class="bulk-skel-block w-30"></div></div>
                    <div class="bulk-skel-row"><div class="bulk-skel-block w-45"></div><div class="bulk-skel-block w-35"></div></div>
                    <div class="bulk-skel-row"><div class="bulk-skel-block w-70"></div><div class="bulk-skel-block w-20"></div></div>
                    <div class="bulk-skel-row bulk-skel-full"><div class="bulk-skel-block w-90"></div></div>
                    <div class="bulk-skel-row bulk-skel-full"><div class="bulk-skel-block w-75"></div></div>
                </div>
                <div class="bulk-spinner-text" style="margin-top:0.75rem;"><?php echo __('bulk_op.analyzing'); ?></div>
            </div>

            <!-- Impact Report -->
            <div class="bulk-report" id="bulkOpReport" style="display:none;"></div>

            <!-- Error state -->
            <div class="bulk-error-box" id="bulkOpError" style="display:none;"></div>
        </div>

        <!-- Footer -->
        <div class="bulk-modal-footer" id="bulkOpFooter" style="display:none;">
            <div class="bulk-footer-left">
                <button type="button" class="btn-secondary bulk-cancel-btn" onclick="closeModal('bulkOpAnalyzeModal')">
                    &#10005; <?php echo __('bulk_op.cancel'); ?>
                </button>
            </div>
            <div class="bulk-footer-right">
                <button type="button" class="btn-secondary bulk-detail-btn" onclick="bulkOp.showRawReport()" title="View full JSON report">
                    &#128196; <?php echo __('bulk_op.review_details'); ?>
                </button>
                <button type="button" id="bulkOpConfirmBtn" class="btn-primary bulk-confirm-btn" onclick="bulkOp.confirm()" disabled>
                    &#10003; <?php echo __('bulk_op.confirm_execute'); ?>
                </button>
            </div>
            <div class="bulk-footer-hints">
                <kbd>Esc</kbd> to cancel &middot; <kbd>Enter</kbd> to confirm
            </div>
        </div>
    </div>
</div>

<!-- ===== Bulk Operation Execution Report Modal ===== -->
<div class="modal" id="bulkOpExecReportModal">
    <div class="modal-content" style="max-width:520px;border-radius:16px;">
        <div id="bulkOpExecReportBody"></div>
    </div>
</div>

<!-- ===== Raw Details Modal ===== -->
<div class="modal" id="bulkOpRawModal">
    <div class="modal-content" style="max-width:700px;border-radius:16px;">
        <div class="bulk-modal-header">
            <h3 style="margin:0;font-size:1rem;"><?php echo __('bulk_op.detailed_breakdown'); ?></h3>
            <button class="modal-close" onclick="closeModal('bulkOpRawModal')">&times;</button>
        </div>
        <div style="padding:1.25rem;max-height:50vh;overflow-y:auto;font-size:0.8rem;font-family:monospace;white-space:pre-wrap;" id="bulkOpRawBody"></div>
        <div class="bulk-modal-footer">
            <button class="btn-secondary" onclick="closeModal('bulkOpRawModal')" style="padding:0.4rem 1rem;border-radius:8px;"><?php echo __('bulk_op.close'); ?></button>
        </div>
    </div>
</div>

<style>
/* ── Bulk Modal Overrides ── */
#bulkOpAnalyzeModal .modal-content {
    max-width: 640px;
    padding: 0;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

/* ── Header ── */
.bulk-modal-header {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e2e8f0;
    position: sticky;
    top: 0;
    z-index: 2;
}

.bulk-header-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
    background: #dbeafe;
    color: #2563eb;
}

.bulk-header-text { flex: 1; min-width: 0; }
.bulk-header-text h3 { margin: 0; font-size: 1.05rem; font-weight: 800; line-height: 1.3; }
.bulk-header-sub { margin: 0; font-size: 0.78rem; color: #64748b; margin-top: 0.1rem; }

/* ── Body ── */
.bulk-modal-body {
    padding: 1.25rem 1.5rem;
    max-height: 55vh;
    overflow-y: auto;
}

.bulk-modal-body::-webkit-scrollbar { width: 5px; }
.bulk-modal-body::-webkit-scrollbar-track { background: transparent; }
.bulk-modal-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

/* ── Skeleton Loading ── */
.bulk-skeleton { display: flex; flex-direction: column; gap: 0.65rem; padding: 0.5rem 0; }
.bulk-skel-row { display: flex; gap: 0.75rem; }
.bulk-skel-full { width: 100%; }
.bulk-skel-block {
    height: 14px;
    border-radius: 6px;
    background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
    background-size: 200% 100%;
    animation: bulkShimmer 1.5s ease-in-out infinite;
}
.w-20 { width: 20%; } .w-30 { width: 30%; } .w-35 { width: 35%; }
.w-45 { width: 45%; } .w-60 { width: 60%; } .w-70 { width: 70%; }
.w-75 { width: 75%; } .w-90 { width: 90%; }

@keyframes bulkShimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* ── Report Title ── */
.bulk-report-title {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    margin-bottom: 1rem;
    padding-bottom: 0.85rem;
    border-bottom: 1px solid #e2e8f0;
}

.bulk-report-title strong {
    font-size: 1rem;
    font-weight: 800;
    color: #0f172a;
}

.bulk-count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 24px;
    padding: 0 0.55rem;
    border-radius: 20px;
    background: #eff6ff;
    color: #2563eb;
    font-size: 0.75rem;
    font-weight: 700;
    white-space: nowrap;
}

/* ── Sections (accordion) ── */
.bulk-section {
    margin-bottom: 0.5rem;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
    animation: bulkFadeSlide 0.35s ease-out both;
}

.bulk-section:nth-child(2) { animation-delay: 0.05s; }
.bulk-section:nth-child(3) { animation-delay: 0.10s; }
.bulk-section:nth-child(4) { animation-delay: 0.15s; }
.bulk-section:nth-child(5) { animation-delay: 0.20s; }
.bulk-section:nth-child(6) { animation-delay: 0.25s; }
.bulk-section:nth-child(7) { animation-delay: 0.30s; }
.bulk-section:nth-child(8) { animation-delay: 0.35s; }

@keyframes bulkFadeSlide {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

.bulk-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.65rem 1rem;
    cursor: pointer;
    user-select: none;
    transition: background 0.15s;
    font-size: 0.82rem;
    font-weight: 700;
    color: #334155;
    background: #fafbfc;
}

.bulk-section-header:hover { background: #f1f5f9; }

.bulk-section-icon {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.bulk-section-icon span:first-child {
    width: 22px;
    height: 22px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    background: #f1f5f9;
    color: #475569;
}

.bulk-section-chevron {
    font-size: 0.55rem;
    color: #94a3b8;
    transition: transform 0.2s ease;
}

.bulk-section-body {
    padding: 0.75rem 1rem;
    border-top: 1px solid #e2e8f0;
    animation: bulkFadeIn 0.2s ease-out;
}

@keyframes bulkFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* ── Stat Grid (cylinder impact) ── */
.bulk-stat-grid {
    display: flex;
    gap: 0.5rem;
}

.bulk-stat-box {
    flex: 1;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.6rem 0.5rem;
    text-align: center;
}

.bulk-stat-value {
    font-size: 1.25rem;
    font-weight: 800;
    line-height: 1.2;
}

.bulk-stat-label {
    font-size: 0.68rem;
    color: #64748b;
    margin-top: 0.15rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

/* ── Impact Table ── */
.bulk-impact-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}

.bulk-impact-table tr + tr { border-top: 1px solid #f1f5f9; }

.bulk-impact-table td {
    padding: 0.35rem 0.5rem;
}

.bulk-impact-table td:first-child {
    color: #64748b;
    font-weight: 500;
}

.bulk-impact-table td:last-child {
    text-align: right;
    font-weight: 600;
    color: #0f172a;
    font-variant-numeric: tabular-nums;
}

/* ── Delta List (warehouse stock) ── */
.bulk-delta-list { margin-top: 0.4rem; }
.bulk-delta-item {
    display: flex;
    justify-content: space-between;
    padding: 0.25rem 0.5rem;
    font-size: 0.78rem;
    border-radius: 6px;
    background: #f8fafc;
    margin-bottom: 0.2rem;
}
.bulk-delta-item .bulk-delta-val { font-weight: 700; font-variant-numeric: tabular-nums; }
.bulk-delta-val.pos { color: #16a34a; }
.bulk-delta-val.neg { color: #dc2626; }
.bulk-delta-val.zero { color: #94a3b8; }

/* ── Error / Warning Boxes ── */
.bulk-error-box {
    padding: 0.85rem 1rem;
    border-radius: 10px;
    font-size: 0.82rem;
    line-height: 1.5;
}

.bulk-error-box[style*="block"] {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.bulk-error-box strong { display: flex; align-items: center; gap: 0.35rem; margin-bottom: 0.25rem; }

/* Warnings rendered inside report */
.bulk-warning-box {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 10px;
    padding: 0.75rem 1rem;
    margin-bottom: 0.75rem;
    animation: bulkFadeIn 0.3s ease-out;
}

/* ── Risk Analysis ── */
.bulk-risk-box {
    padding: 0.75rem 1rem;
    border-radius: 10px;
    margin-top: 0.75rem;
    font-size: 0.82rem;
    border: 1px solid;
    animation: bulkFadeIn 0.35s ease-out;
}

.bulk-risk-box strong {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    margin-bottom: 0.25rem;
    font-size: 0.85rem;
}

.bulk-risk-box.risk-warning {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.bulk-risk-box.risk-danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #991b1b;
}

.bulk-risk-item {
    padding: 0.2rem 0;
    padding-left: 0.5rem;
    font-size: 0.8rem;
}

.bulk-risk-item::before {
    content: '→ ';
    opacity: 0.6;
}

/* ── Tag list (reports affected) ── */
.bulk-tag-list { display: flex; flex-wrap: wrap; gap: 0.35rem; }
.bulk-tag {
    display: inline-block;
    padding: 0.2rem 0.55rem;
    font-size: 0.72rem;
    font-weight: 600;
    border-radius: 6px;
    background: #f1f5f9;
    color: #475569;
}

/* ── Footer ── */
.bulk-modal-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 1rem 1.5rem;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    position: sticky;
    bottom: 0;
    z-index: 2;
}

.bulk-footer-left,
.bulk-footer-right {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.bulk-cancel-btn,
.bulk-detail-btn {
    padding: 0.5rem 1.1rem !important;
    border-radius: 10px !important;
    font-size: 0.82rem !important;
    font-weight: 600 !important;
    gap: 0.35rem;
}

.bulk-confirm-btn {
    padding: 0.5rem 1.4rem !important;
    border-radius: 10px !important;
    font-size: 0.85rem !important;
    font-weight: 700 !important;
    gap: 0.35rem;
    transition: all 0.15s ease !important;
}

.bulk-confirm-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.bulk-footer-hints {
    width: 100%;
    text-align: center;
    font-size: 0.68rem;
    color: #94a3b8;
    margin-top: 0.15rem;
}

.bulk-footer-hints kbd {
    display: inline-block;
    padding: 1px 5px;
    font-size: 0.65rem;
    font-family: inherit;
    background: #e2e8f0;
    border-radius: 4px;
    border: 1px solid #cbd5e1;
    border-bottom-width: 2px;
    color: #475569;
}

/* ── Responsive ── */
@media (max-width: 600px) {
    #bulkOpAnalyzeModal .modal-content {
        max-width: calc(100% - 0.5rem);
        margin: 0.25rem;
        border-radius: 16px;
        max-height: 90vh;
    }
    .bulk-modal-header { padding: 1rem 1.15rem; }
    .bulk-modal-body { padding: 1rem 1.15rem; max-height: 50vh; }
    .bulk-modal-footer {
        flex-direction: column;
        padding: 0.85rem 1.15rem;
    }
    .bulk-footer-left,
    .bulk-footer-right { width: 100%; }
    .bulk-footer-right { justify-content: flex-end; }
    .bulk-stat-grid { flex-wrap: wrap; }
    .bulk-stat-box { min-width: calc(33.33% - 0.35rem); }
    .bulk-header-text h3 { font-size: 0.95rem; }
}

@media (max-width: 400px) {
    .bulk-stat-box { min-width: calc(50% - 0.25rem); }
    .bulk-footer-right { flex-wrap: wrap; }
    .bulk-confirm-btn { width: 100%; justify-content: center; }
}
</style>

<script>
window.bulkOp = {
    report: null,
    context: null,
    action: null,
    ids: [],
    confirmCallback: null,

    // Operation icon/color map
    opMeta: {
        dispatch:         { icon: '&#128666;', label: 'Dispatch', color: '#2563eb', bg: '#dbeafe' },
        send_to_vendor:   { icon: '&#128666;', label: 'Dispatch', color: '#2563eb', bg: '#dbeafe' },
        receive:          { icon: '&#128230;', label: 'Receive', color: '#059669', bg: '#d1fae5' },
        receive_from_vendor: { icon: '&#128230;', label: 'Receive', color: '#059669', bg: '#d1fae5' },
        pay:              { icon: '&#128176;', label: 'Payment', color: '#d97706', bg: '#fef3c7' },
        close:            { icon: '&#128274;', label: 'Close', color: '#7c3aed', bg: '#ede9fe' },
        'delete':         { icon: '&#128465;', label: 'Delete', color: '#dc2626', bg: '#fef2f2' },
        status_update:    { icon: '&#9881;', label: 'Update', color: '#0891b2', bg: '#cffafe' },
        customer_settle:  { icon: '&#129297;', label: 'Settle', color: '#2563eb', bg: '#dbeafe' },
    },

    getOpMeta: function(op) {
        return this.opMeta[op] || { icon: '&#9888;', label: op, color: '#64748b', bg: '#f1f5f9' };
    },

    analyze: function(ids, action, context, url) {
        this.ids = ids;
        this.action = action;
        this.context = context || {};
        url = url || window.location.href;

        if (!ids || ids.length === 0) {
            alert('<?php echo __('bulk_op.no_records_selected'); ?>');
            return;
        }

        // Update header icon for this operation
        var meta = this.getOpMeta(action);
        var iconEl = document.getElementById('bulkOpHeaderIcon');
        iconEl.innerHTML = meta.icon;
        iconEl.style.background = meta.bg;
        iconEl.style.color = meta.color;

        var titleEl = document.getElementById('bulkOpHeaderTitle');
        titleEl.textContent = meta.label + ' — ' + ids.length + ' record' + (ids.length !== 1 ? 's' : '');

        var subEl = document.getElementById('bulkOpHeaderSub');
        subEl.textContent = action === 'dispatch' || action === 'send_to_vendor'
            ? 'Review the dispatch impact before sending to vendor'
            : 'Review the impact before proceeding';

        var loading = document.getElementById('bulkOpLoading');
        var report = document.getElementById('bulkOpReport');
        var error = document.getElementById('bulkOpError');
        var footer = document.getElementById('bulkOpFooter');
        var confirmBtn = document.getElementById('bulkOpConfirmBtn');

        loading.style.display = 'block';
        report.style.display = 'none';
        error.style.display = 'none';
        footer.style.display = 'none';
        confirmBtn.disabled = true;
        openModal('bulkOpAnalyzeModal');

        var formData = new FormData();
        formData.append('action', 'analyze_' + action);
        formData.append('ids', JSON.stringify(ids));
        formData.append('_csrf_token', document.querySelector('input[name="_csrf_token"]')?.value || '');
        for (var key in context) {
            if (context.hasOwnProperty(key)) {
                formData.append(key, context[key]);
            }
        }

        fetch(url, {
            method: 'POST',
            body: formData
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            loading.style.display = 'none';
            if (data.success && data.report) {
                bulkOp.report = data.report;
                bulkOp.renderReport(data.report);
            } else {
                bulkOp.showError(data.error || '<?php echo __('bulk_op.analysis_failed'); ?>');
            }
        })
        .catch(function(err) {
            loading.style.display = 'none';
            bulkOp.showError('<?php echo __('bulk_op.analysis_error'); ?> ' + err.message);
        });
    },

    renderReport: function(report) {
        var html = '';
        var hasErrors = report.validation && report.validation.errors && report.validation.errors.length > 0;
        var hasWarnings = report.validation && report.validation.warnings && report.validation.warnings.length > 0;

        // ── Operation title with count badge ──
        var meta = this.getOpMeta(report.operation);
        html += '<div class="bulk-report-title">';
        html += '<span class="bulk-header-icon" style="width:32px;height:32px;border-radius:8px;font-size:0.95rem;background:' + meta.bg + ';color:' + meta.color + ';display:inline-flex;align-items:center;justify-content:center;">' + meta.icon + '</span>';
        html += '<strong>' + this.getOperationLabel(report.operation) + '</strong>';
        html += '<span class="bulk-count-badge">' + report.records_selected + ' <?php echo __('bulk_op.records'); ?></span>';
        html += '</div>';

        // ── Errors (blocking) ──
        if (hasErrors) {
            html += '<div class="bulk-error-box" style="margin-bottom:0.75rem;display:block;">';
            html += '<strong>&#10007; <?php echo __('bulk_op.errors_found'); ?></strong>';
            html += '<ul style="margin:0.35rem 0 0;padding-left:1.25rem;font-size:0.8rem;">';
            report.validation.errors.forEach(function(e) {
                html += '<li style="margin-bottom:0.15rem;">' + e + '</li>';
            });
            html += '</ul></div>';
        }

        // ── Warnings ──
        if (hasWarnings) {
            html += '<div class="bulk-warning-box">';
            html += '<strong style="color:#d97706;font-size:0.85rem;display:flex;align-items:center;gap:0.3rem;">'
                + '<span style="width:20px;height:20px;border-radius:50%;background:#fef3c7;color:#d97706;display:inline-flex;align-items:center;justify-content:center;font-size:0.75rem;">&#9888;</span>'
                + '<?php echo __('bulk_op.warnings'); ?></strong>';
            html += '<ul style="margin:0.35rem 0 0;padding-left:1.25rem;font-size:0.8rem;color:#92400e;">';
            report.validation.warnings.forEach(function(w) {
                html += '<li style="margin-bottom:0.15rem;">' + w + '</li>';
            });
            html += '</ul></div>';
        }

        // ── Cylinder Impact ──
        if (report.cylinders) {
            html += this.renderSection(
                'cylinder',
                '<?php echo __('bulk_op.cylinder_impact'); ?>',
                function() {
                    var cy = report.cylinders;
                    var h = '<div class="bulk-stat-grid">';
                    h += bulkOp.statBox(cy.total_selected, '<?php echo __('bulk_op.total_selected'); ?>', '#2563eb');
                    h += bulkOp.statBox(cy.valid_count, '<?php echo __('bulk_op.valid'); ?>', '#059669');
                    h += bulkOp.statBox(cy.skipped_count, '<?php echo __('bulk_op.skipped'); ?>', '#d97706');
                    h += '</div>';
                    if (cy.invalid_count > 0) {
                        h += '<div style="margin-top:0.5rem;font-size:0.8rem;color:#b91c1c;display:flex;align-items:center;gap:0.3rem;">'
                            + '<span style="width:16px;height:16px;border-radius:50%;background:#fef2f2;color:#dc2626;display:inline-flex;align-items:center;justify-content:center;font-size:0.6rem;">&#10007;</span>'
                            + cy.invalid_count + ' <?php echo __('bulk_op.invalid'); ?>'
                            + '</div>';
                    }
                    return h;
                }
            );
        }

        // ── Inventory Impact ──
        if (report.inventory) {
            html += this.renderSection(
                'inventory',
                '<?php echo __('bulk_op.inventory_impact'); ?>',
                function() {
                    var inv = report.inventory;
                    var h = '<table class="bulk-impact-table">';
                    h += '<tr><td><?php echo __('bulk_op.cylinders_leaving'); ?></td><td>' + inv.cylinders_leaving + '</td></tr>';
                    h += '<tr><td><?php echo __('bulk_op.cylinders_entering'); ?></td><td>' + inv.cylinders_entering + '</td></tr>';
                    h += '</table>';
                    if (inv.warehouse_stock_deltas && inv.warehouse_stock_deltas.length > 0) {
                        h += '<div class="bulk-delta-list">';
                        inv.warehouse_stock_deltas.forEach(function(d) {
                            var cls = d.delta > 0 ? 'pos' : (d.delta < 0 ? 'neg' : 'zero');
                            var sign = d.delta > 0 ? '+' : '';
                            h += '<div class="bulk-delta-item"><span>' + d.gas + ' ' + d.size + '</span><span class="bulk-delta-val ' + cls + '">' + sign + d.delta + '</span></div>';
                        });
                        h += '</div>';
                    }
                    return h;
                }
            );
        }

        // ── Lot Impact ──
        if (report.lots && report.lots.lots_affected > 0) {
            html += this.renderSection(
                'lots',
                '<?php echo __('bulk_op.lot_impact'); ?>',
                function() {
                    var lots = report.lots;
                    var h = '<table class="bulk-impact-table">';
                    h += '<tr><td><?php echo __('bulk_op.lots_affected'); ?></td><td>' + lots.lots_affected + '</td></tr>';
                    h += '<tr><td><?php echo __('bulk_op.lots_completed'); ?></td><td style="color:#059669;">' + lots.lots_completed + '</td></tr>';
                    h += '<tr><td><?php echo __('bulk_op.lots_partial'); ?></td><td style="color:#d97706;">' + lots.lots_partial + '</td></tr>';
                    h += '</table>';
                    return h;
                }
            );
        }

        // ── Financial Impact ──
        if (report.financial && report.financial.total_invoice_amount > 0) {
            html += this.renderSection(
                'financial',
                '<?php echo __('bulk_op.financial_impact'); ?>',
                function() {
                    var fin = report.financial;
                    var fmt = function(v) { return '&#8377;' + parseFloat(v || 0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); };
                    var h = '<table class="bulk-impact-table">';
                    h += '<tr><td><?php echo __('bulk_op.invoice_amount'); ?></td><td>' + fmt(fin.total_invoice_amount) + '</td></tr>';
                    if (fin.advance_already_paid > 0) h += '<tr><td><?php echo __('bulk_op.advance_paid'); ?></td><td style="color:#d97706;">' + fmt(fin.advance_already_paid) + '</td></tr>';
                    if (fin.new_payments > 0) h += '<tr><td><?php echo __('bulk_op.new_payments'); ?></td><td style="color:#059669;">' + fmt(fin.new_payments) + '</td></tr>';
                    if (fin.refunds > 0) h += '<tr><td><?php echo __('bulk_op.refunds'); ?></td><td style="color:#dc2626;">' + fmt(fin.refunds) + '</td></tr>';
                    h += '<tr style="border-top:2px solid #e2e8f0;"><td style="padding:0.5rem 0.5rem;font-weight:800;color:#0f172a;font-size:0.85rem;"><?php echo __('bulk_op.outstanding_after'); ?></td><td style="padding:0.5rem 0.5rem;font-weight:800;color:#0f172a;font-size:0.85rem;">' + fmt(fin.outstanding_after) + '</td></tr>';
                    h += '</table>';
                    return h;
                }
            );
        }

        // ── GST Impact ──
        if (report.gst) {
            html += this.renderSection(
                'gst',
                '<?php echo __('bulk_op.gst_impact'); ?>',
                function() {
                    var gst = report.gst;
                    var h = '<table class="bulk-impact-table">';
                    if (gst.gst_applicable) {
                        h += '<tr><td><?php echo __('bulk_op.gst_rate'); ?></td><td>' + gst.gst_rate + '%</td></tr>';
                        if (gst.gst_amount > 0) h += '<tr><td><?php echo __('bulk_op.gst_amount'); ?></td><td>&#8377;' + parseFloat(gst.gst_amount).toFixed(2) + '</td></tr>';
                    } else {
                        h += '<tr><td style="color:#64748b;"><?php echo __('bulk_op.gst_not_applicable'); ?></td></tr>';
                    }
                    h += '<tr><td><?php echo __('bulk_op.gst_locked'); ?></td><td style="font-weight:600;' + (gst.gst_locked ? 'color:#d97706;' : 'color:#059669;') + '">' + (gst.gst_locked ? '&#9888; <?php echo __('bulk_op.yes'); ?>' : '<?php echo __('bulk_op.no'); ?>') + '</td></tr>';
                    h += '</table>';
                    if (gst.mismatch_warning) {
                        h += '<div style="margin-top:0.4rem;padding:0.4rem 0.6rem;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:0.78rem;color:#92400e;display:flex;align-items:center;gap:0.3rem;">'
                            + '<span>&#9888;</span> <?php echo __('bulk_op.gst_mismatch'); ?>'
                            + '</div>';
                    }
                    return h;
                }
            );
        }

        // ── Ledger Impact ──
        if (report.ledger && (report.ledger.vendor_entries > 0 || report.ledger.customer_entries > 0)) {
            html += this.renderSection(
                'ledger',
                '<?php echo __('bulk_op.ledger_impact'); ?>',
                function() {
                    var led = report.ledger;
                    var h = '<table class="bulk-impact-table">';
                    if (led.vendor_entries > 0) h += '<tr><td><?php echo __('bulk_op.vendor_entries'); ?></td><td>' + led.vendor_entries + '</td></tr>';
                    if (led.customer_entries > 0) h += '<tr><td><?php echo __('bulk_op.customer_entries'); ?></td><td>' + led.customer_entries + '</td></tr>';
                    if (led.cash_impact > 0) h += '<tr><td><?php echo __('bulk_op.cash_impact'); ?></td><td>&#8377;' + parseFloat(led.cash_impact).toFixed(2) + '</td></tr>';
                    if (led.advance_adjustments > 0) h += '<tr><td><?php echo __('bulk_op.advance_adjustments'); ?></td><td>' + led.advance_adjustments + '</td></tr>';
                    if (led.settlement_entries > 0) h += '<tr><td><?php echo __('bulk_op.settlement_entries'); ?></td><td>' + led.settlement_entries + '</td></tr>';
                    h += '</table>';
                    return h;
                }
            );
        }

        // ── Payment Impact ──
        if (report.payment && report.payment.payments_created > 0) {
            html += this.renderSection(
                'payment',
                '<?php echo __('bulk_op.payment_impact'); ?>',
                function() {
                    var pay = report.payment;
                    var h = '<table class="bulk-impact-table">';
                    h += '<tr><td><?php echo __('bulk_op.payments_created'); ?></td><td>' + pay.payments_created + '</td></tr>';
                    h += '<tr><td><?php echo __('bulk_op.lots_becoming_paid'); ?></td><td style="color:#059669;">' + pay.lots_becoming_paid + '</td></tr>';
                    if (pay.lots_remaining_partial > 0) h += '<tr><td><?php echo __('bulk_op.lots_remaining_partial'); ?></td><td style="color:#d97706;">' + pay.lots_remaining_partial + '</td></tr>';
                    h += '</table>';
                    return h;
                }
            );
        }

        // ── Reports Affected ──
        if (report.reports_affected && report.reports_affected.length > 0) {
            html += this.renderSection(
                'reports',
                '<?php echo __('bulk_op.reports_affected'); ?>',
                function() {
                    var h = '<div class="bulk-tag-list">';
                    report.reports_affected.forEach(function(r) {
                        h += '<span class="bulk-tag">' + r + '</span>';
                    });
                    h += '</div>';
                    return h;
                }
            );
        }

        // ── Risk warnings ──
        var riskItems = [];
        var riskLevel = 'warning';
        if (report.gst && report.gst.gst_locked) riskItems.push('<?php echo __('bulk_op.risk_gst_lock'); ?>');
        if (report.lots && report.lots.lots_completed > 0) riskItems.push('<?php echo __('bulk_op.risk_lots_closed'); ?>');
        if (report.operation === 'delete') { riskItems.push('<?php echo __('bulk_op.risk_irreversible'); ?>'); riskLevel = 'danger'; }
        if (report.operation === 'dispatch' || report.operation === 'send_to_vendor') riskItems.push('<?php echo __('bulk_op.risk_dispatch_irreversible'); ?>');

        if (riskItems.length > 0) {
            html += '<div class="bulk-risk-box risk-' + riskLevel + '">';
            html += '<strong>' + (riskLevel === 'danger' ? '&#10071; ' : '&#9888; ') + '<?php echo __('bulk_op.risk_analysis'); ?></strong>';
            riskItems.forEach(function(r) {
                html += '<div class="bulk-risk-item">' + r + '</div>';
            });
            html += '</div>';
        }

        var reportEl = document.getElementById('bulkOpReport');
        reportEl.innerHTML = html;
        reportEl.style.display = 'block';

        var footer = document.getElementById('bulkOpFooter');
        footer.style.display = 'flex';

        var confirmBtn = document.getElementById('bulkOpConfirmBtn');
        confirmBtn.disabled = hasErrors;
        if (!hasErrors) {
            setTimeout(function() { confirmBtn.focus(); }, 150);
        }

        // Reset animation for sections by re-inserting
        var sections = reportEl.querySelectorAll('.bulk-section');
        sections.forEach(function(s, i) {
            s.style.animation = 'none';
            void s.offsetHeight;
            s.style.animation = 'bulkFadeSlide 0.35s ease-out both';
            s.style.animationDelay = (i * 0.05) + 's';
        });
    },

    renderSection: function(type, title, contentFn) {
        var icons = {
            cylinder:  '&#9881;',
            inventory: '&#128230;',
            lots:      '&#128203;',
            financial: '&#8377;',
            gst:       '&#9883;',
            ledger:    '&#128221;',
            payment:   '&#128179;',
            reports:   '&#128200;'
        };
        var id = 'bulkSection-' + Math.random().toString(36).substr(2, 6);
        var html = '<div class="bulk-section">';
        html += '<div class="bulk-section-header" onclick="bulkOp.toggleSection(\'' + id + '\')">';
        html += '<span class="bulk-section-icon"><span>' + (icons[type] || '&#9654;') + '</span><span>' + title + '</span></span>';
        html += '<span class="bulk-section-chevron" id="' + id + '-icon">&#9660;</span>';
        html += '</div>';
        html += '<div class="bulk-section-body" id="' + id + '">';
        html += contentFn();
        html += '</div></div>';
        return html;
    },

    toggleSection: function(id) {
        var el = document.getElementById(id);
        var icon = document.getElementById(id + '-icon');
        if (el.style.display === 'none') {
            el.style.display = 'block';
            icon.style.transform = 'rotate(0deg)';
        } else {
            el.style.display = 'none';
            icon.style.transform = 'rotate(-90deg)';
        }
    },

    statBox: function(value, label, color) {
        return '<div class="bulk-stat-box">'
            + '<div class="bulk-stat-value" style="color:' + color + ';">' + value + '</div>'
            + '<div class="bulk-stat-label">' + label + '</div>'
            + '</div>';
    },

    showError: function(msg) {
        var el = document.getElementById('bulkOpError');
        el.innerHTML = '<strong>&#10007; <?php echo __('bulk_op.error'); ?>:</strong> ' + msg;
        el.style.display = 'block';
    },

    getOperationLabel: function(op) {
        var labels = {
            'dispatch': '<?php echo __('bulk_op.op_dispatch'); ?>',
            'send_to_vendor': '<?php echo __('bulk_op.op_dispatch'); ?>',
            'receive': '<?php echo __('bulk_op.op_receive'); ?>',
            'receive_from_vendor': '<?php echo __('bulk_op.op_receive'); ?>',
            'pay': '<?php echo __('bulk_op.op_pay'); ?>',
            'close': '<?php echo __('bulk_op.op_close'); ?>',
            'delete': '<?php echo __('bulk_op.op_delete'); ?>',
            'status_update': '<?php echo __('bulk_op.op_status_update'); ?>',
            'customer_settle': '<?php echo __('bulk_op.op_customer_settle'); ?>',
        };
        return labels[op] || op;
    },

    showRawReport: function() {
        if (!this.report) return;
        document.getElementById('bulkOpRawBody').textContent = JSON.stringify(this.report, null, 2);
        openModal('bulkOpRawModal');
    },

    confirm: function() {
        if (this.confirmCallback) {
            closeModal('bulkOpAnalyzeModal');
            this.confirmCallback(this.report, this.context);
        }
    }
};

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var analyzeModal = document.getElementById('bulkOpAnalyzeModal');
        if (analyzeModal && analyzeModal.classList.contains('active')) {
            closeModal('bulkOpAnalyzeModal');
        }
    }
    if (e.key === 'Enter') {
        var confirmBtn = document.getElementById('bulkOpConfirmBtn');
        if (confirmBtn && !confirmBtn.disabled) {
            var analyzeModal = document.getElementById('bulkOpAnalyzeModal');
            if (analyzeModal && analyzeModal.classList.contains('active')) {
                e.preventDefault();
                bulkOp.confirm();
            }
        }
    }
});
</script>
<?php
    }
}

renderBulkOperationDialogs();
