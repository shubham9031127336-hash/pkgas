<?php
/**
 * Navigation helpers for Rent Cylinder / Partner flow.
 * Provides breadcrumb rendering and contextual back-links.
 */

function render_breadcrumb($crumbs = []) {
    // Normalize breadcrumbs to handle both formats:
    // Old format: ['label' => 'url', ...]
    // New format: [['title' => 'Label', 'href' => 'url'], ...]
    $normalized = [];
    foreach ($crumbs as $key => $value) {
        if (is_array($value)) {
            // New format: ['title' => ..., 'href' => ...]
            $label = $value['title'] ?? '';
            $url = $value['href'] ?? null;
            if ($label) {
                $normalized[$label] = $url;
            }
        } else {
            // Old format: 'label' => 'url'
            $normalized[$key] = $value;
        }
    }
    
    $all = array_merge([
        '🏠 Dashboard' => 'dashboard.php',
    ], $normalized);
    
    echo '<nav class="page-breadcrumb" style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; margin-bottom:1.25rem; padding:0.5rem 0; color:var(--admin-muted); flex-wrap:wrap;">';
    $i = 0;
    foreach ($all as $label => $url) {
        if ($i > 0) echo '<span style="color:var(--admin-border); user-select:none;">›</span>';
        if ($url) {
            echo '<a href="' . htmlspecialchars($url) . '" style="color:var(--admin-accent); text-decoration:none; font-weight:600;">' . htmlspecialchars((string)$label) . '</a>';
        } else {
            echo '<span style="color:var(--admin-fg); font-weight:700;">' . htmlspecialchars((string)$label) . '</span>';
        }
        $i++;
    }
    echo '</nav>';
}
