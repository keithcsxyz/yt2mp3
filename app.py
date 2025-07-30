from flask import Flask, request, jsonify, Response, session, send_from_directory
import os
import time
import json
import re
import threading
import uuid
from datetime import datetime, timedelta
import logging

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = Flask(__name__)
app.secret_key = os.environ.get('SECRET_HULAAN_MO', 'your-secret-key-change-this-in-production')

# Configuration
DOWNLOAD_DIR = os.path.join(os.path.dirname(__file__), 'downloads')
STATIC_DIR = os.path.join(os.path.dirname(__file__))
MAX_DOWNLOADS_PER_SESSION = 50
ALLOWED_QUALITIES = ['128', '192', '256', '320']

# Create downloads directory if it doesn't exist
try:
    if not os.path.exists(DOWNLOAD_DIR):
        os.makedirs(DOWNLOAD_DIR, mode=0o755)
except Exception as e:
    logger.error(f"Error creating downloads directory: {e}")

# Clean old files on startup
def clean_old_files():
    """Clean files older than 1 hour"""
    try:
        one_hour_ago = time.time() - 3600
        for filename in os.listdir(DOWNLOAD_DIR):
            filepath = os.path.join(DOWNLOAD_DIR, filename)
            if os.path.isfile(filepath) and os.path.getmtime(filepath) < one_hour_ago:
                os.remove(filepath)
                logger.info(f"Cleaned old file: {filename}")
    except Exception as e:
        logger.error(f"Error cleaning old files: {e}")

# Run file cleanup in background
threading.Thread(target=clean_old_files, daemon=True).start()

# Serve static files
@app.route('/')
def index():
    return send_from_directory(STATIC_DIR, 'index.html')

@app.route('/<path:filename>')
def static_files(filename):
    # Serve static files (CSS, JS, etc.)
    try:
        return send_from_directory(STATIC_DIR, filename)
    except FileNotFoundError:
        # If file not found, return 404
        return "File not found", 404

def is_valid_youtube_url(url):
    """Validate YouTube URL"""
    yt_regex = r'^(https?:\/\/)?(www\.)?(youtube\.com\/(watch\?v=|embed\/|v\/)|youtu\.be\/)[\w-]+'
    return re.match(yt_regex, url) is not None

def test_youtube_connectivity():
    """Test connectivity to YouTube"""
    try:
        import urllib.request
        import urllib.error
        
        # Test URL
        test_url = "https://www.youtube.com/"
        
        # Create a request with headers
        req = urllib.request.Request(
            test_url,
            headers={
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            }
        )
        
        # Try to open the URL
        response = urllib.request.urlopen(req, timeout=10)
        return response.getcode() == 200
    except Exception as e:
        logger.error(f"YouTube connectivity test failed: {e}")
        return False

def sanitize_filename(filename):
    """Sanitize filename by removing invalid characters"""
    filename = re.sub(r'[<>:"/\\|?*]', '', filename)
    filename = re.sub(r'\s+', ' ', filename)
    filename = filename.strip()
    
    # Limit length
    if len(filename) > 100:
        filename = filename[:100]
    
    return filename or 'download'

def get_video_info(url):
    """Get video information using yt-dlp"""
    try:
        import yt_dlp
        
        # Enhanced yt-dlp options for better compatibility
        ydl_opts = {
            'quiet': True,
            'no_warnings': True,
            'format': 'bestaudio/best',
            'socket_timeout': 30,
            'retries': 3,
            'no_check_certificate': True,
            'user_agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'referer': 'https://www.youtube.com/',
            'force_generic_extractor': False,
            'http_headers': {
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language': 'en-US,en;q=0.5',
                'Accept-Encoding': 'gzip, deflate',
                'Connection': 'keep-alive',
            },
            'http_proxy': os.environ.get('HTTP_PROXY', ''),
            'https_proxy': os.environ.get('HTTPS_PROXY', ''),
        }
        
        with yt_dlp.YoutubeDL(ydl_opts) as ydl:
            info = ydl.extract_info(url, download=False)
            
            return {
                'title': info.get('title', 'Unknown Title'),
                'duration': info.get('duration', ''),
                'thumbnail': info.get('thumbnail', ''),
                'uploader': info.get('uploader', ''),
            }
    except Exception as e:
        logger.error(f"Error getting video info: {e}")
        # Provide more specific error messages
        error_msg = str(e)
        if "unavailable" in error_msg.lower():
            raise Exception("The video is not available or accessible. This could be due to regional restrictions, the video being private, or network connectivity issues.")
        elif "copyright" in error_msg.lower():
            raise Exception("The video cannot be downloaded due to copyright restrictions.")
        elif "timeout" in error_msg.lower():
            raise Exception("The connection timed out. Please try again or use a different video URL.")
        else:
            # Fallback to basic info extraction
            video_id = re.search(r'[?&]v=([^&]+)', url)
            video_id = video_id.group(1) if video_id else 'unknown'
            
            return {
                'title': f'YouTube Video {video_id}',
                'duration': '',
                'thumbnail': '',
                'uploader': '',
            }

def download_video(url, quality, download_id=None):
    """Download video and convert to MP3 using yt-dlp"""
    try:
        import yt_dlp
        
        timestamp = int(time.time())
        temp_file = os.path.join(DOWNLOAD_DIR, f"temp_{timestamp}")
        output_template = temp_file + ".%(ext)s"
        
        # Map quality to yt-dlp format
        audio_quality = f"{quality}k"
        
        # Enhanced yt-dlp options for better compatibility
        ydl_opts = {
            'format': 'bestaudio/best',
            'postprocessors': [{
                'key': 'FFmpegExtractAudio',
                'preferredcodec': 'mp3',
                'preferredquality': quality,
            }],
            'outtmpl': temp_file + '.%(ext)s',
            'quiet': True,
            'no_warnings': True,
            'socket_timeout': 30,
            'retries': 3,
            'no_check_certificate': True,
            'user_agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'referer': 'https://www.youtube.com/',
            'force_generic_extractor': False,
            'http_headers': {
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language': 'en-US,en;q=0.5',
                'Accept-Encoding': 'gzip, deflate',
                'Connection': 'keep-alive',
            },
            'http_proxy': os.environ.get('HTTP_PROXY', ''),
            'https_proxy': os.environ.get('HTTPS_PROXY', ''),
        }
        
        # Get video info first
        info = get_video_info(url)
        
        with yt_dlp.YoutubeDL(ydl_opts) as ydl:
            # Download the video
            ydl.download([url])
            
            # Find the generated file
            downloaded_file = None
            for filename in os.listdir(DOWNLOAD_DIR):
                if filename.startswith(f"temp_{timestamp}"):
                    downloaded_file = os.path.join(DOWNLOAD_DIR, filename)
                    break
            
            if not downloaded_file or not os.path.exists(downloaded_file):
                raise Exception("Download failed: File not found")
            
            # Get the file size
            filesize = os.path.getsize(downloaded_file)
            
            # Create a better filename
            clean_title = sanitize_filename(info['title'])
            new_filename = f"{clean_title}.mp3"
            new_path = os.path.join(DOWNLOAD_DIR, new_filename)
            
            # Rename the file
            os.rename(downloaded_file, new_path)
            
            return {
                'success': True,
                'downloadUrl': f'downloads/{new_filename}',
                'filename': new_filename,
                'title': info['title'],
                'filesize': filesize
            }
            
    except Exception as e:
        logger.error(f"Error downloading video: {e}")
        # Provide more specific error messages
        error_msg = str(e)
        if "unavailable" in error_msg.lower():
            raise Exception("The video is not available or accessible. This could be due to regional restrictions, the video being private, or network connectivity issues.")
        elif "copyright" in error_msg.lower():
            raise Exception("The video cannot be downloaded due to copyright restrictions.")
        elif "timeout" in error_msg.lower():
            raise Exception("The download timed out. Please try again or use a different video URL.")
        else:
            raise Exception(f"Download failed: {error_msg}")

@app.route('/download.php', methods=['POST', 'OPTIONS'])
def download_handler():
    """Handle download requests"""
    # Handle preflight requests
    if request.method == 'OPTIONS':
        response = jsonify({'success': True})
        response.headers.add('Access-Control-Allow-Origin', '*')
        response.headers.add('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
        response.headers.add('Access-Control-Allow-Headers', 'Content-Type')
        return response
    
    try:
        # Set headers
        response_headers = {
            'Content-Type': 'application/json',
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
            'Access-Control-Allow-Headers': 'Content-Type'
        }
        
        # Get form data
        url = request.form.get('url', '')
        quality = request.form.get('quality', '320')
        action = request.form.get('action', 'download')
        
        if not url:
            return jsonify({'success': False, 'error': 'URL is required'}), 400, response_headers
        
        if quality not in ALLOWED_QUALITIES:
            return jsonify({'success': False, 'error': 'Invalid quality selection'}), 400, response_headers
        
        if not is_valid_youtube_url(url):
            return jsonify({'success': False, 'error': 'Invalid YouTube URL'}), 400, response_headers
        
        # Check session download limit
        if 'downloads' not in session:
            session['downloads'] = 0
        
        if session['downloads'] >= MAX_DOWNLOADS_PER_SESSION:
            return jsonify({'success': False, 'error': 'Download limit reached for this session'}), 400, response_headers
        
        if action == 'getInfo':
            try:
                info = get_video_info(url)
                response = jsonify({
                    'success': True,
                    'title': info['title'],
                    'duration': info['duration'],
                    'thumbnail': info['thumbnail']
                })
            except Exception as e:
                return jsonify({'success': False, 'error': str(e)}), 400, response_headers
        else:
            try:
                result = download_video(url, quality)
                
                # Increment session counter
                session['downloads'] = session.get('downloads', 0) + 1
                
                response = jsonify(result)
            except Exception as e:
                return jsonify({'success': False, 'error': str(e)}), 400, response_headers
        
        # Add headers to response
        for key, value in response_headers.items():
            response.headers[key] = value
            
        return response
        
    except Exception as e:
        error_response = jsonify({
            'success': False,
            'error': str(e)
        })
        
        # Add headers to error response
        response_headers = {
            'Content-Type': 'application/json',
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
            'Access-Control-Allow-Headers': 'Content-Type'
        }
        
        for key, value in response_headers.items():
            error_response.headers[key] = value
            
        return error_response, 500

@app.route('/download-progress.php')
def download_progress_handler():
    """Handle download progress with Server-Sent Events"""
    url = request.args.get('url', '')
    quality = request.args.get('quality', '320')
    download_id = request.args.get('downloadId', str(uuid.uuid4()))
    
    if not url:
        def error_stream():
            yield f"data: {json.dumps({'downloadId': download_id, 'error': 'URL is required', 'progress': -1})}\n\n"
        return Response(error_stream(), mimetype='text/event-stream')
    
    if not is_valid_youtube_url(url):
        def error_stream():
            yield f"data: {json.dumps({'downloadId': download_id, 'error': 'Invalid YouTube URL', 'progress': -1})}\n\n"
        return Response(error_stream(), mimetype='text/event-stream')
    
    def progress_stream():
        try:
            # Send initial progress
            yield f"data: {json.dumps({'downloadId': download_id, 'progress': 0, 'message': 'Starting download...'})}\n\n"
            
            # Import yt-dlp
            import yt_dlp
            
            # Send progress update
            yield f"data: {json.dumps({'downloadId': download_id, 'progress': 10, 'message': 'Getting video information...'})}\n\n"
            
            # Get video info
            try:
                info = get_video_info(url)
                yield f"data: {json.dumps({'downloadId': download_id, 'progress': 25, 'message': 'Starting download: ' + info['title']})}\n\n"
            except Exception as e:
                yield f"data: {json.dumps({'downloadId': download_id, 'error': str(e), 'progress': -1})}\n\n"
                return
            
            # Download with progress
            timestamp = int(time.time())
            temp_file = os.path.join(DOWNLOAD_DIR, f"temp_{timestamp}")
            
            # Enhanced yt-dlp options for better compatibility
            ydl_opts = {
                'format': 'bestaudio/best',
                'postprocessors': [{
                    'key': 'FFmpegExtractAudio',
                    'preferredcodec': 'mp3',
                    'preferredquality': quality,
                }],
                'outtmpl': temp_file + '.%(ext)s',
                'quiet': True,
                'no_warnings': True,
                'socket_timeout': 30,
                'retries': 3,
                'no_check_certificate': True,
                'user_agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'referer': 'https://www.youtube.com/',
                'force_generic_extractor': False,
                'http_headers': {
                    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language': 'en-US,en;q=0.5',
                    'Accept-Encoding': 'gzip, deflate',
                    'Connection': 'keep-alive',
                },
                'http_proxy': os.environ.get('HTTP_PROXY', ''),
                'https_proxy': os.environ.get('HTTPS_PROXY', ''),
            }
            
            yield f"data: {json.dumps({'downloadId': download_id, 'progress': 40, 'message': 'Downloading video...'})}\n\n"
            
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                ydl.download([url])
            
            yield f"data: {json.dumps({'downloadId': download_id, 'progress': 80, 'message': 'Converting to MP3...'})}\n\n"
            
            # Find the generated file
            downloaded_file = None
            for filename in os.listdir(DOWNLOAD_DIR):
                if filename.startswith(f"temp_{timestamp}"):
                    downloaded_file = os.path.join(DOWNLOAD_DIR, filename)
                    break
            
            if not downloaded_file or not os.path.exists(downloaded_file):
                yield f"data: {json.dumps({'downloadId': download_id, 'error': 'Download failed: File not found', 'progress': -1})}\n\n"
                return
            
            yield f"data: {json.dumps({'downloadId': download_id, 'progress': 90, 'message': 'Processing filename...'})}\n\n"
            
            # Get file size
            filesize = os.path.getsize(downloaded_file)
            
            # Create a better filename
            clean_title = sanitize_filename(info['title'])
            new_filename = f"{clean_title}.mp3"
            new_path = os.path.join(DOWNLOAD_DIR, new_filename)
            
            # Rename the file
            os.rename(downloaded_file, new_path)
            
            yield f"data: {json.dumps({'downloadId': download_id, 'progress': 95, 'message': 'Finalizing...'})}\n\n"
            
            # Send completion
            result = {
                'success': True,
                'downloadUrl': f'downloads/{new_filename}',
                'filename': new_filename,
                'title': info['title'],
                'filesize': filesize
            }
            
            yield f"data: {json.dumps({'downloadId': download_id, 'progress': 100, 'message': 'Download completed!', 'data': result})}\n\n"
            
        except Exception as e:
            logger.error(f"Download error: {e}")
            yield f"data: {json.dumps({'downloadId': download_id, 'error': str(e), 'progress': -1})}\n\n"
    
    return Response(progress_stream(), mimetype='text/event-stream')

# Serve download files
@app.route('/downloads/<path:filename>')
def serve_download(filename):
    try:
        return send_from_directory(DOWNLOAD_DIR, filename, as_attachment=True)
    except FileNotFoundError:
        return "File not found", 404

@app.route('/test-youtube')
def test_youtube():
    """Test YouTube connectivity endpoint"""
    try:
        connectivity = test_youtube_connectivity()
        return jsonify({
            'success': True,
            'youtube_accessible': connectivity,
            'message': 'YouTube is accessible' if connectivity else 'YouTube is not accessible'
        })
    except Exception as e:
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

if __name__ == '__main__':
    app.run(debug=True, host='0.0.0.0', port=8000)
