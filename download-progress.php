<?php
// Turn off error reporting to prevent HTML output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Prevent timeout
set_time_limit(300); // 5 minutes
ignore_user_abort(false);

// Configuration
$DOWNLOAD_DIR = __DIR__ . '/downloads/';

// Create downloads directory if it doesn't exist
if (!file_exists($DOWNLOAD_DIR)) {
    mkdir($DOWNLOAD_DIR, 0755, true);
}

try {
    // Get parameters from URL for GET request (EventSource)
    $url = $_GET['url'] ?? '';
    $quality = $_GET['quality'] ?? '320';
    $downloadId = $_GET['downloadId'] ?? uniqid();

    if (empty($url)) {
        throw new Exception('URL is required');
    }

    if (!isValidYouTubeUrl($url)) {
        throw new Exception('Invalid YouTube URL');
    }

    // Send initial progress
    sendProgress($downloadId, 0, 'Starting download...');

    // Download with progress
    $result = downloadVideoWithProgress($url, $quality, $downloadId);
    
    // Send completion
    sendProgress($downloadId, 100, 'Download completed!', $result);

} catch (Exception $e) {
    sendError($downloadId, $e->getMessage());
}

function isValidYouTubeUrl($url) {
    return preg_match('/^(https?:\/\/)?(www\.)?(youtube\.com\/(watch\?v=|embed\/|v\/)|youtu\.be\/)[\w-]+/', $url);
}

function sendProgress($downloadId, $percentage, $message, $data = null) {
    $progressData = [
        'downloadId' => $downloadId,
        'progress' => $percentage,
        'message' => $message,
        'data' => $data
    ];
    
    echo "data: " . json_encode($progressData) . "\n\n";
    
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
    
    // Small delay to make progress visible
    if ($percentage < 100) {
        usleep(200000); // 0.2 seconds
    }
}

function sendError($downloadId, $error) {
    $errorData = [
        'downloadId' => $downloadId,
        'error' => $error,
        'progress' => -1
    ];
    
    echo "data: " . json_encode($errorData) . "\n\n";
    
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

function downloadVideoWithProgress($url, $quality, $downloadId) {
    global $DOWNLOAD_DIR;
    
    $escapedUrl = escapeshellarg($url);
    $timestamp = time();
    $tempFile = $DOWNLOAD_DIR . "temp_$timestamp";
    $outputTemplate = $tempFile . '.%(ext)s';
    
    // Map quality to yt-dlp format
    $audioQuality = $quality . 'k';
    
    // Get video info first
    sendProgress($downloadId, 10, 'Getting video information...');
    $info = getVideoInfo($url);
    
    sendProgress($downloadId, 25, 'Starting download: ' . ($info['title'] ?? 'Unknown'));
    
    // Build command with progress hook
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = "C:\\Users\\rnzrm\\AppData\\Local\\Programs\\Python\\Python313\\Scripts\\yt-dlp.exe -x --audio-format mp3 --audio-quality $audioQuality --output " . escapeshellarg($outputTemplate) . " --no-warnings $escapedUrl 2>&1";
    } else {
        $command = "yt-dlp -x --audio-format mp3 --audio-quality $audioQuality --output " . escapeshellarg($outputTemplate) . " --no-warnings $escapedUrl 2>&1";
    }
    
    // Simulate progress during download
    sendProgress($downloadId, 40, 'Downloading video...');
    sendProgress($downloadId, 60, 'Processing audio...');
    
    $output = shell_exec($command);
    
    sendProgress($downloadId, 80, 'Converting to MP3...');
    
    // Find the generated file
    $files = glob($tempFile . '*');
    if (empty($files)) {
        throw new Exception('Download failed: ' . ($output ?: 'Unknown error'));
    }
    
    $downloadedFile = $files[0];
    $filename = basename($downloadedFile);
    
    sendProgress($downloadId, 90, 'Processing filename...');
    
    // Get video title for better filename
    try {
        $cleanTitle = sanitizeFilename($info['title']);
        $newFilename = $cleanTitle . '.mp3';
        $newPath = $DOWNLOAD_DIR . $newFilename;
        
        if (rename($downloadedFile, $newPath)) {
            $downloadedFile = $newPath;
            $filename = $newFilename;
        }
    } catch (Exception $e) {
        // Continue with original filename if title extraction fails
    }
    
    sendProgress($downloadId, 95, 'Finalizing...');
    
    return [
        'success' => true,
        'downloadUrl' => 'downloads/' . $filename,
        'filename' => $filename,
        'title' => $info['title'] ?? $filename,
        'filesize' => filesize($downloadedFile)
    ];
}

function getVideoInfo($url) {
    $escapedUrl = escapeshellarg($url);
    
    // Try different commands based on OS
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $commands = [
            "C:\\Users\\rnzrm\\AppData\\Local\\Programs\\Python\\Python313\\Scripts\\yt-dlp.exe --dump-json --no-warnings $escapedUrl 2>nul",
        ];
    } else {
        $commands = [
            "yt-dlp --dump-json --no-warnings $escapedUrl 2>&1"
        ];
    }
    
    foreach ($commands as $command) {
        $output = shell_exec($command);
        if (!empty($output)) {
            $data = json_decode($output, true);
            
            if (json_last_error() === JSON_ERROR_NONE && $data && isset($data['title'])) {
                return [
                    'title' => $data['title'] ?? 'Unknown Title',
                    'duration' => $data['duration'] ?? '',
                    'thumbnail' => $data['thumbnail'] ?? ''
                ];
            }
        }
    }
    
    // Fallback to basic info extraction
    return extractBasicInfo($url);
}

function extractBasicInfo($url) {
    // Basic fallback method to extract video ID and create a generic title
    preg_match('/[?&]v=([^&]+)/', $url, $matches);
    $videoId = $matches[1] ?? 'unknown';
    
    return [
        'title' => 'YouTube Video ' . $videoId,
        'duration' => '',
        'thumbnail' => ''
    ];
}

function sanitizeFilename($filename) {
    // Remove or replace invalid filename characters
    $filename = preg_replace('/[<>:\"\/\\\|?*]/', '', $filename);
    $filename = preg_replace('/\s+/', ' ', $filename);
    $filename = trim($filename);
    
    // Limit length
    if (strlen($filename) > 100) {
        $filename = substr($filename, 0, 100);
    }
    
    return $filename ?: 'download';
}

?>
