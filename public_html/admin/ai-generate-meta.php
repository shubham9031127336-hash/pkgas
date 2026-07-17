<?php
header('Content-Type: application/json');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lang_init.php';
require_login();

// Read input JSON
$input = json_decode(file_get_contents('php://input'), true);
$title = $input['title'] ?? '';
$content = $input['content'] ?? '';

if (empty($title) && empty($content)) {
    echo json_encode(['success' => false, 'message' => __('ai_meta.no_content')]);
    exit;
}

// Prepare plain text content by stripping HTML
$plainContent = strip_tags($content);
$plainContent = substr($plainContent, 0, 1500); // Take first 1500 chars

// NOTE: In a real environment, you would use an LLM API like OpenAI here.
// Example OpenAI Call:
// $apiKey = "YOUR_OPENAI_KEY";
// ... (cURL setup to OpenAI API) ...

// Since we may not have an API key configured, we will use a local heuristic approach 
// to simulate AI text generation for meta description and keywords.

function generate_meta_description($text, $title) {
    // Basic heuristic: take the first paragraph or two, max 155 chars
    $text = preg_replace('/\s+/', ' ', $text); // normalize whitespace
    $desc = substr($text, 0, 155);
    
    // Make sure it doesn't end in the middle of a word
    if (strlen($text) > 155) {
        $desc = substr($desc, 0, strrpos($desc, ' '));
        $desc .= '...';
    }
    
    if (empty(trim($desc))) {
        $desc = __('ai_meta.fallback_desc') . ' ' . $title . ' ' . __('ai_meta.from_supplier');
    }
    return trim($desc);
}

function generate_meta_keywords($text, $title) {
    // List of common stop words
    $stopWords = ['the', 'and', 'to', 'of', 'a', 'in', 'is', 'that', 'for', 'it', 'as', 'was', 'with', 'on', 'this', 'by', 'are', 'we', 'you', 'be', 'an', 'at', 'or'];
    
    // Extract words
    preg_match_all('/\b[a-zA-Z]{4,}\b/i', strtolower($title . ' ' . $text), $matches);
    $words = $matches[0];
    
    // Filter and count
    $wordCounts = [];
    foreach ($words as $word) {
        if (!in_array($word, $stopWords)) {
            if (!isset($wordCounts[$word])) $wordCounts[$word] = 0;
            $wordCounts[$word]++;
        }
    }
    
    arsort($wordCounts);
    $topWords = array_slice(array_keys($wordCounts), 0, 5);
    
    // Always include some primary keywords
    $keywords = array_unique(array_merge(['Prem Gas Solution', 'Bihar'], $topWords));
    
    return implode(', ', $keywords);
}

// Generate the simulated AI results
$meta_description = generate_meta_description($plainContent, $title);
$meta_keywords = generate_meta_keywords($plainContent, $title);

// Simulate network delay
sleep(1);

echo json_encode([
    'success' => true,
    'meta_description' => $meta_description,
    'meta_keywords' => $meta_keywords
]);
?>
