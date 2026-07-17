<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/compression-utils.php';
require_login();
validateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $target_dir = "../uploads/blog/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

    $file = $_FILES['file'];
    $mime = getImageMimeType($file['tmp_name']);

    // Check if it's an image or video
    $isImage = isImageFile($mime);
    $isVideo = isVideoFile($mime);

    if (!$isImage && !$isVideo) {
        echo json_encode(['success' => false, 'message' => __('upload.error_type')]);
        exit;
    }

    $new_name = time() . '_' . uniqid();

    if ($isImage) {
        // Try to compress image to WebP
        $target_path = $target_dir . $new_name . '.webp';
        $compression = compressImage($file['tmp_name'], $target_path, 75);

        // If WebP fails, try fallback
        if (!$compression['success'] && isset($compression['fallback'])) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $target_path = $target_dir . $new_name . '.' . $ext;
            $compression = compressImageFallback($file['tmp_name'], $target_path);
        }

        if (!$compression['success']) {
            echo json_encode(['success' => false, 'message' => $compression['error']]);
            exit;
        }

        $url = str_replace('.webp', '', '../uploads/blog/' . $new_name);
        if ($compression['format'] === 'webp') {
            $url .= '.webp';
        } else {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $url .= '.' . $ext;
        }

        $response = [
            'url' => $url,
            'success' => true,
            'originalSize' => $compression['originalSize'],
            'compressedSize' => $compression['compressedSize'],
            'savedKB' => $compression['savedKB'],
            'savedPercent' => $compression['savedPercent'],
            'isCompressed' => !isset($compression['fallback']),
            'format' => $compression['format'],
            'type' => 'image'
        ];
    } else {
        // For videos, just move the file
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $target_path = $target_dir . $new_name . '.' . $ext;

        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $response = [
                'url' => '../uploads/blog/' . $new_name . '.' . $ext,
                'success' => true,
                'type' => 'video'
            ];
        } else {
            echo json_encode(['success' => false, 'message' => __('upload.error_failed')]);
            exit;
        }
    }

    echo json_encode($response);
    exit;
}

echo json_encode(['success' => false, 'message' => __('upload.error_invalid')]);