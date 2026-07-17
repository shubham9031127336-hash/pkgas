<?php

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../ai/ai-config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$text = trim($input['text'] ?? '');
$voice = $input['voice'] ?? '';
$speed = $input['speed'] ?? '+0%';

if (empty($text)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Text is required.']);
    exit;
}

try {
    $config = getAIConfig($pdo);

    $apiKey = $config['azure_tts_key'] ?? '';
    $region = $config['azure_tts_region'] ?? 'eastus';
    if (empty($voice)) {
        $voice = $config['azure_tts_voice'] ?? 'hi-IN-SwaraNeural';
    }

    if (empty($apiKey) || empty($region)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Azure TTS not configured.']);
        exit;
    }

    $lang = 'hi-IN';
    if (strpos($voice, 'en-') === 0) {
        $lang = 'en-IN';
    }

    $ssml = '<?xml version="1.0" encoding="utf-8"?>';
    $ssml .= '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xmlns:mstts="http://www.w3.org/2001/mstts" xml:lang="' . $lang . '">';
    $ssml .= '<voice name="' . htmlspecialchars($voice) . '">';
    $ssml .= '<prosody rate="' . htmlspecialchars($speed) . '">';
    $ssml .= '<break strength="medium"/>';
    $ssml .= htmlspecialchars($text);
    $ssml .= '</prosody>';
    $ssml .= '</voice>';
    $ssml .= '</speak>';

    $url = "https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1";

    $httpHeaders = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                "Content-Type: application/ssml+xml",
                "X-Microsoft-OutputFormat: audio-16khz-32kbitrate-mono-mp3",
                "User-Agent: PremGasSolutionAI",
                "Ocp-Apim-Subscription-Key: $apiKey",
            ]),
            'content' => $ssml,
            'timeout' => 15,
        ],
    ];

    $context = stream_context_create($httpHeaders);
    $audioData = @file_get_contents($url, false, $context);

    if ($audioData === false) {
        $lastError = error_get_last();
        $errMsg = $lastError['message'] ?? 'Unknown error';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $ssml,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/ssml+xml',
                    'X-Microsoft-OutputFormat: audio-16khz-32kbitrate-mono-mp3',
                    'User-Agent: NutanGasesAI',
                    "Ocp-Apim-Subscription-Key: $apiKey",
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $audioData = curl_exec($ch);
            $curlErrno = curl_errno($ch);
            if ($curlErrno !== 0) {
                curl_close($ch);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => "Azure TTS failed (curl error $curlErrno)"]);
                exit;
            }
            curl_close($ch);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => "Azure TTS failed: $errMsg"]);
            exit;
        }
    }

    $base64 = base64_encode($audioData);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'audio_base64' => $base64,
        'audio_format' => 'audio/mpeg',
        'voice' => $voice,
        'duration_ms' => strlen($audioData) * 1000 / 4000,
    ]);
} catch (Throwable $e) {
    error_log("azure-tts error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
