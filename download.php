<?php
// Turn off error reporting to prevent HTML output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Configuration
$DOWNLOAD_DIR = __DIR__ . '/downloads/';
$MAX_DOWNLOADS_PER_SESSION = 50;
$ALLOWED_QUALITIES = ['128', '192', '256', '320'];

// Create downloads directory if it doesn't exist
if (!file_exists($DOWNLOAD_DIR)) {
    mkdir($DOWNLOAD_DIR, 0755, true);
}

// Clean old files (older than 1 hour)
cleanOldFiles();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST requests are allowed');
    }

    $url = $_POST['url'] ?? '';
    $quality = $_POST['quality'] ?? '320';
    $action = $_POST['action'] ?? 'download';

    if (empty($url)) {
        throw new Exception('URL is required');
    }

    if (!in_array($quality, $ALLOWED_QUALITIES)) {
        throw new Exception('Invalid quality selection');
    }

    if (!isValidYouTubeUrl($url)) {
        throw new Exception('Invalid YouTube URL');
    }

    // Check session download limit
    session_start();
    $sessionDownloads = $_SESSION['downloads'] ?? 0;
    if ($sessionDownloads >= $MAX_DOWNLOADS_PER_SESSION) {
        throw new Exception('Download limit reached for this session');
    }

    if ($action === 'getInfo') {
        $info = getVideoInfo($url);
        echo json_encode([
            'success' => true,
            'title' => $info['title'] ?? 'Unknown Title',
            'duration' => $info['duration'] ?? '',
            'thumbnail' => $info['thumbnail'] ?? ''
        ]);
    } else {
        $result = downloadVideo($url, $quality);
        
        // Increment session counter
        $_SESSION['downloads'] = $sessionDownloads + 1;
        
        echo json_encode($result);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function isValidYouTubeUrl($url) {
    return preg_match('/^(https?:\/\/)?(www\.)?(youtube\.com\/(watch\?v=|embed\/|v\/)|youtu\.be\/)[\w-]+/', $url);
}

function getVideoInfo($url) {
    $escapedUrl = escapeshellarg($url);
    
    // Try different commands based on environment
    $commands = [];
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows (Local XAMPP)
        $commands = [
            "C:\\Users\\rnzrm\\AppData\\Local\\Programs\\Python\\Python313\\Scripts\\yt-dlp.exe --dump-json --no-warnings $escapedUrl 2>nul",
        ];
    } else {
        // Linux/Unix (Railway, VPS, etc.)
        $commands = [
            "yt-dlp --dump-json --no-warnings $escapedUrl 2>&1",
            "/usr/local/bin/yt-dlp --dump-json --no-warnings $escapedUrl 2>&1", 
            "python3 -m yt_dlp --dump-json --no-warnings $escapedUrl 2>&1",
            "/usr/bin/yt-dlp --dump-json --no-warnings $escapedUrl 2>&1"
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

function downloadVideo($url, $quality) {
    global $DOWNLOAD_DIR;
    
    $escapedUrl = escapeshellarg($url);
    $timestamp = time();
    $tempFile = $DOWNLOAD_DIR . "temp_$timestamp";
    $outputTemplate = $tempFile . '.%(ext)s';
    
    // Map quality to yt-dlp format
    $audioQuality = $quality . 'k';
    
    // Try different ways to run yt-dlp on Windows
    $commands = [];
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows commands (Local XAMPP)
        $commands = [
            "C:\\Users\\rnzrm\\AppData\\Local\\Programs\\Python\\Python313\\Scripts\\yt-dlp.exe -x --audio-format mp3 --audio-quality $audioQuality --output " . escapeshellarg($outputTemplate) . " --no-warnings --quiet $escapedUrl 2>&1",
        ];
    } else {
        // Linux/Unix commands (Railway, VPS, etc.)
        $commands = [
            "yt-dlp -x --audio-format mp3 --audio-quality $audioQuality --output " . escapeshellarg($outputTemplate) . " --no-warnings --quiet $escapedUrl 2>&1",
            "/usr/local/bin/yt-dlp -x --audio-format mp3 --audio-quality $audioQuality --output " . escapeshellarg($outputTemplate) . " --no-warnings --quiet $escapedUrl 2>&1",
            "python3 -m yt_dlp -x --audio-format mp3 --audio-quality $audioQuality --output " . escapeshellarg($outputTemplate) . " --no-warnings --quiet $escapedUrl 2>&1"
        ];
    }
    
    $lastError = '';
    
    foreach ($commands as $command) {
        $output = shell_exec($command);
        
        // Find the generated file
        $files = glob($tempFile . '*');
        if (!empty($files)) {
            $downloadedFile = $files[0];
            $filename = basename($downloadedFile);
            
            // Get video title for better filename
            $info = null;
            try {
                $info = getVideoInfo($url);
                $cleanTitle = sanitizeFilename($info['title']);
                $newFilename = $cleanTitle . '.mp3';
                $newPath = $DOWNLOAD_DIR . $newFilename;
                
                if (rename($downloadedFile, $newPath)) {
                    $downloadedFile = $newPath;
                    $filename = $newFilename;
                }
            } catch (Exception $e) {
                // Continue with original filename if title extraction fails
                $info = ['title' => $filename];
            }
            
            return [
                'success' => true,
                'downloadUrl' => 'downloads/' . $filename,
                'filename' => $filename,
                'title' => $info['title'] ?? $filename,
                'filesize' => filesize($downloadedFile)
            ];
        }
        
        $lastError = $output ?: 'Unknown error';
    }
    
    // If all commands failed, try fallback
    return downloadWithFallback($url, $quality, $lastError);
}

function downloadWithFallback($url, $quality, $lastError = '') {
    // Try youtube-dl if available
    if (isCommandAvailable('youtube-dl')) {
        return downloadWithYoutubeDl($url, $quality);
    }
    
    // Create detailed error message for Windows
    $errorMsg = 'No YouTube downloader found. ';
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $errorMsg .= "For Windows:\n";
        $errorMsg .= "1. Install Python from https://python.org\n";
        $errorMsg .= "2. Open Command Prompt as Administrator\n";
        $errorMsg .= "3. Run: pip install yt-dlp\n";
        $errorMsg .= "4. Restart Apache in XAMPP\n\n";
        $errorMsg .= "Alternative: Download yt-dlp.exe from https://github.com/yt-dlp/yt-dlp/releases\n";
        $errorMsg .= "and place it in C:\\Windows\\System32\n\n";
    } else {
        $errorMsg .= "Please install yt-dlp: pip install yt-dlp\n";
        $errorMsg .= "Or visit: https://github.com/yt-dlp/yt-dlp\n\n";
    }
    
    if ($lastError) {
        $errorMsg .= "Last error: " . $lastError;
    }
    
    throw new Exception($errorMsg);
}

function downloadWithYoutubeDl($url, $quality) {
    global $DOWNLOAD_DIR;
    
    $escapedUrl = escapeshellarg($url);
    $timestamp = time();
    $tempFile = $DOWNLOAD_DIR . "temp_$timestamp";
    $outputTemplate = $tempFile . '.%(ext)s';
    
    $command = "youtube-dl -x --audio-format mp3 --audio-quality $quality " .
               "--output " . escapeshellarg($outputTemplate) . " " .
               "--quiet $escapedUrl 2>&1";
    
    $output = shell_exec($command);
    
    $files = glob($tempFile . '*');
    if (empty($files)) {
        throw new Exception('Download failed with youtube-dl: ' . ($output ?: 'Unknown error'));
    }
    
    $downloadedFile = $files[0];
    $filename = basename($downloadedFile);
    
    return [
        'success' => true,
        'downloadUrl' => 'downloads/' . $filename,
        'filename' => $filename,
        'title' => $filename,
        'filesize' => filesize($downloadedFile)
    ];
}

function isCommandAvailable($command) {
    // Check for Windows
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Try different ways to find the command on Windows
        $paths = [
            // Check if it's in PATH
            shell_exec("where $command 2>nul"),
            // Check common Python script locations
            shell_exec("python -m pip show yt-dlp 2>nul"),
            // Check if we can run it directly
            shell_exec("$command --version 2>nul"),
        ];
        
        foreach ($paths as $result) {
            if (!empty(trim($result))) {
                return true;
            }
        }
        
        // Try to run through Python
        $pythonCheck = shell_exec("python -c \"import yt_dlp; print('found')\" 2>nul");
        if (strpos($pythonCheck, 'found') !== false) {
            return true;
        }
        
        return false;
    } else {
        // Unix/Linux
        $check = shell_exec("which $command 2>/dev/null");
        return !empty($check);
    }
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

function cleanOldFiles() {
    global $DOWNLOAD_DIR;
    
    $files = glob($DOWNLOAD_DIR . '*');
    $oneHourAgo = time() - 3600;
    
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $oneHourAgo) {
            unlink($file);
        }
    }
}

// Additional utility functions for enhanced functionality
function getVideoThumbnail($url) {
    preg_match('/[?&]v=([^&]+)/', $url, $matches);
    $videoId = $matches[1] ?? null;
    
    if ($videoId) {
        return "https://img.youtube.com/vi/$videoId/maxresdefault.jpg";
    }
    
    return null;
}

function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

function logDownload($url, $quality, $success) {
    $logFile = __DIR__ . '/download_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $status = $success ? 'SUCCESS' : 'FAILED';
    $entry = "[$timestamp] $status - Quality: {$quality}kbps - URL: $url\n";
    
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

?>
