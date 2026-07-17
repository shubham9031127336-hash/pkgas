<?php
// Image/Video compression utility functions

function getImageMimeType($filePath) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filePath);
    finfo_close($finfo);
    return $mime;
}

function isImageFile($mimeType) {
    $imageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];
    return in_array($mimeType, $imageMimes);
}

function isVideoFile($mimeType) {
    $videoMimes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
    return in_array($mimeType, $videoMimes);
}

function compressImage($sourceFile, $targetFile, $quality = 75) {
    // Check if GD library is available
    if (!extension_loaded('gd')) {
        return [
            'success' => false,
            'error' => 'GD library not available',
            'fallback' => true
        ];
    }

    $mime = getImageMimeType($sourceFile);
    $info = @getimagesize($sourceFile);

    if ($info === false) {
        return ['success' => false, 'error' => 'Invalid image file'];
    }

    $width = $info[0];
    $height = $info[1];

    // Create image resource based on type
    $image = null;
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $image = @imagecreatefromjpeg($sourceFile);
            break;
        case 'image/png':
            $image = @imagecreatefrompng($sourceFile);
            break;
        case 'image/gif':
            $image = @imagecreatefromgif($sourceFile);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $image = @imagecreatefromwebp($sourceFile);
            }
            break;
    }

    if ($image === false) {
        return ['success' => false, 'error' => 'Failed to process image'];
    }

    // Resize if too large
    if ($width > 1600) {
        $newHeight = (1600 / $width) * $height;
        $resized = @imagecreatetruecolor(1600, (int)$newHeight);
        if ($resized === false) {
            imagedestroy($image);
            return ['success' => false, 'error' => 'Failed to create resized image'];
        }
        @imagecopyresampled($resized, $image, 0, 0, 0, 0, 1600, (int)$newHeight, $width, $height);
        imagedestroy($image);
        $image = $resized;
    }

    // Check if WebP support is available
    if (!function_exists('imagewebp')) {
        imagedestroy($image);
        return [
            'success' => false,
            'error' => 'WebP support not available on server',
            'fallback' => true
        ];
    }

    // Save as WebP with optimized quality
    $webpQuality = max(50, min(85, (int)$quality));
    $success = @imagewebp($image, $targetFile, $webpQuality);
    imagedestroy($image);

    if ($success && file_exists($targetFile)) {
        $originalSize = filesize($sourceFile);
        $compressedSize = filesize($targetFile);
        $saved = $originalSize - $compressedSize;
        $percent = $originalSize > 0 ? round(($saved / $originalSize) * 100) : 0;

        return [
            'success' => true,
            'originalSize' => $originalSize,
            'compressedSize' => $compressedSize,
            'savedBytes' => $saved,
            'savedPercent' => max(0, $percent),
            'savedKB' => round($saved / 1024, 1),
            'format' => 'webp'
        ];
    }

    return ['success' => false, 'error' => 'WebP compression failed', 'fallback' => true];
}

function compressImageFallback($sourceFile, $targetFile) {
    // If WebP is not available, copy original file
    if (!copy($sourceFile, $targetFile)) {
        return ['success' => false, 'error' => 'Failed to save image'];
    }

    $originalSize = filesize($sourceFile);
    $compressedSize = filesize($targetFile);

    return [
        'success' => true,
        'originalSize' => $originalSize,
        'compressedSize' => $compressedSize,
        'savedBytes' => 0,
        'savedPercent' => 0,
        'savedKB' => 0,
        'format' => 'original',
        'fallback' => true
    ];
}
