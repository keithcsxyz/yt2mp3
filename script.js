// Global variables
let downloadQueue = [];
let isDownloading = false;
let currentDownloadIndex = 0;

// Initialize the application
document.addEventListener("DOMContentLoaded", function () {
  updateQueueDisplay();
  updateQueueCount();
});

// Add URLs to download queue
function addToQueue(type) {
  const quality = getSelectedQuality();
  let urls = [];

  if (type === "single") {
    const singleUrl = document.getElementById("singleUrl").value.trim();
    if (singleUrl) {
      urls = [singleUrl];
      document.getElementById("singleUrl").value = "";
    }
  } else if (type === "bulk") {
    const bulkUrls = document.getElementById("bulkUrls").value.trim();
    if (bulkUrls) {
      urls = bulkUrls
        .split("\n")
        .map((url) => url.trim())
        .filter((url) => url.length > 0);
      document.getElementById("bulkUrls").value = "";
    }
  }

  if (urls.length === 0) {
    showMessage("Please enter valid YouTube URLs", "error");
    return;
  }

  // Validate and add URLs to queue
  let validUrls = 0;
  urls.forEach((url) => {
    if (isValidYouTubeUrl(url)) {
      const queueItem = {
        id: Date.now() + Math.random(),
        url: url,
        quality: quality,
        status: "pending",
        title: "",
        progress: 0,
      };
      downloadQueue.push(queueItem);
      validUrls++;
    }
  });

  if (validUrls > 0) {
    showMessage(`Added ${validUrls} video(s) to download queue`, "success");
    updateQueueDisplay();
    updateQueueCount();

    // Fetch video information for better display
    fetchVideoInfo();
  } else {
    showMessage("No valid YouTube URLs found", "error");
  }
}

// Validate YouTube URL
function isValidYouTubeUrl(url) {
  const ytRegex =
    /^(https?:\/\/)?(www\.)?(youtube\.com\/(watch\?v=|embed\/|v\/)|youtu\.be\/)[\w-]+/;
  return ytRegex.test(url);
}

// Get selected quality
function getSelectedQuality() {
  const qualityRadios = document.querySelectorAll('input[name="quality"]');
  for (let radio of qualityRadios) {
    if (radio.checked) {
      return radio.value;
    }
  }
  return "320"; // Default quality
}

// Update queue display
function updateQueueDisplay() {
  const queueContainer = document.getElementById("downloadQueue");

  if (downloadQueue.length === 0) {
    queueContainer.innerHTML = `
            <div class="empty-queue">
                <i class="fas fa-music"></i>
                <p>No items in queue. Add YouTube URLs to get started!</p>
            </div>
        `;
    return;
  }

  queueContainer.innerHTML = downloadQueue
    .map((item) => {
      const progressBarHtml =
        item.status === "processing" && item.progress > 0
          ? `<div class="queue-progress-bar">
             <div class="queue-progress-fill" style="width: ${item.progress}%"></div>
           </div>`
          : "";

      const statusIcon = getStatusIcon(item.status);
      const statusClass = `status-${item.status}`;

      return `
        <div class="queue-item ${statusClass}" data-id="${item.id}">
            <div class="queue-item-info">
                <div class="queue-item-url">
                    ${statusIcon} ${item.title || item.url}
                </div>
                <div class="queue-item-status">
                    Quality: ${item.quality}kbps | Status: ${item.status}
                    ${item.progress > 0 ? ` | Progress: ${item.progress}%` : ""}
                </div>
                ${progressBarHtml}
            </div>
            <div class="queue-item-actions">
                <button onclick="removeFromQueue('${
                  item.id
                }')" class="btn btn-danger btn-small" ${
        item.status === "processing" ? "disabled" : ""
      }>
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        `;
    })
    .join("");
}

// Get status icon based on item status
function getStatusIcon(status) {
  switch (status) {
    case "pending":
      return '<i class="fas fa-clock" style="color: #888;"></i>';
    case "processing":
      return '<i class="fas fa-spinner fa-spin" style="color: #007bff;"></i>';
    case "completed":
      return '<i class="fas fa-check-circle" style="color: #28a745;"></i>';
    case "error":
      return '<i class="fas fa-exclamation-circle" style="color: #dc3545;"></i>';
    default:
      return '<i class="fas fa-music" style="color: #888;"></i>';
  }
}

// Update queue count and download button state
function updateQueueCount() {
  const count = downloadQueue.length;
  document.getElementById("queueCount").textContent = count;
  document.getElementById("downloadAllBtn").disabled =
    count === 0 || isDownloading;
}

// Remove item from queue
function removeFromQueue(itemId) {
  downloadQueue = downloadQueue.filter((item) => item.id !== itemId);
  updateQueueDisplay();
  updateQueueCount();
  showMessage("Item removed from queue", "info");
}

// Clear entire queue
function clearQueue() {
  if (downloadQueue.length === 0) return;

  if (confirm("Are you sure you want to clear the entire queue?")) {
    downloadQueue = [];
    updateQueueDisplay();
    updateQueueCount();
    hideProgressSection();
    showMessage("Queue cleared", "info");
  }
}

// Start downloading all items in queue
async function startDownloadAll() {
  if (downloadQueue.length === 0 || isDownloading) return;

  isDownloading = true;
  currentDownloadIndex = 0;

  updateQueueCount();
  showProgressSection();

  try {
    for (let i = 0; i < downloadQueue.length; i++) {
      currentDownloadIndex = i;
      await downloadSingleItem(downloadQueue[i]);
      updateOverallProgress();
    }

    showMessage("All downloads completed!", "success");

    // Auto-clear completed items after 5 seconds
    setTimeout(() => {
      downloadQueue = downloadQueue.filter(
        (item) => item.status !== "completed"
      );
      updateQueueDisplay();
      updateQueueCount();
      if (downloadQueue.length === 0) {
        hideProgressSection();
      }
    }, 5000);
  } catch (error) {
    console.error("Download error:", error);
    showMessage(
      "Some downloads failed. Check the progress section for details.",
      "error"
    );
  } finally {
    isDownloading = false;
    updateQueueCount();
  }
}

// Download single item with real-time progress
async function downloadSingleItem(item) {
  return new Promise((resolve, reject) => {
    try {
      item.status = "processing";
      item.progress = 0;
      updateQueueDisplay();
      addStatusMessage(
        `Starting download: ${item.title || item.url}`,
        "processing"
      );

      // Generate unique download ID
      const downloadId =
        "download_" +
        Date.now() +
        "_" +
        Math.random().toString(36).substr(2, 9);

      // Build URL with parameters for EventSource
      const params = new URLSearchParams({
        url: item.url,
        quality: item.quality,
        downloadId: downloadId,
      });

      const eventSourceUrl = `download-progress.php?${params.toString()}`;

      // Create EventSource for progress updates
      const eventSource = new EventSource(eventSourceUrl);

      // Listen for progress events
      eventSource.onmessage = function (event) {
        try {
          const data = JSON.parse(event.data);

          // Check if this progress update is for our download
          if (data.downloadId === downloadId) {
            if (data.error) {
              // Handle error
              item.status = "error";
              item.progress = 0;
              addStatusMessage(
                `✗ Failed: ${item.title || item.url} - ${data.error}`,
                "error"
              );
              eventSource.close();
              updateQueueDisplay();
              reject(new Error(data.error));
              return;
            }

            // Update progress
            item.progress = Math.max(0, Math.min(100, data.progress));
            updateQueueDisplay();
            updateOverallProgress();

            // Update status message
            if (data.message) {
              addStatusMessage(data.message, "processing");
            }

            // Check if download is complete
            if (data.progress === 100 && data.data) {
              item.status = "completed";
              item.title = data.data.title || item.title;
              item.downloadUrl = data.data.downloadUrl;

              // Auto-download the file
              if (data.data.downloadUrl) {
                const link = document.createElement("a");
                link.href = data.data.downloadUrl;
                link.download = data.data.filename || "audio.mp3";
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
              }

              addStatusMessage(`✓ Completed: ${item.title}`, "success");
              eventSource.close();
              updateQueueDisplay();
              updateOverallProgress();
              resolve();
            }
          }
        } catch (parseError) {
          console.error("Error parsing progress data:", parseError);
        }
      };

      // Handle EventSource errors
      eventSource.onerror = function (event) {
        console.error("EventSource error:", event);
        eventSource.close();

        if (item.status === "processing") {
          item.status = "error";
          item.progress = 0;
          addStatusMessage(
            `✗ Failed: ${item.title || item.url} - Connection error`,
            "error"
          );
          updateQueueDisplay();
          reject(new Error("Connection error"));
        }
      };

      // Set a timeout to prevent hanging
      setTimeout(() => {
        if (item.status === "processing") {
          eventSource.close();
          item.status = "error";
          item.progress = 0;
          addStatusMessage(
            `✗ Failed: ${item.title || item.url} - Timeout`,
            "error"
          );
          updateQueueDisplay();
          reject(new Error("Download timeout"));
        }
      }, 300000); // 5 minutes timeout
    } catch (error) {
      item.status = "error";
      item.progress = 0;
      addStatusMessage(
        `✗ Failed: ${item.title || item.url} - ${error.message}`,
        "error"
      );
      console.error("Download error:", error);
      updateQueueDisplay();
      reject(error);
    }
  });
}

// Update overall progress
function updateOverallProgress() {
  const totalItems = downloadQueue.length;
  const completedItems = currentDownloadIndex + 1;
  const progressPercent = Math.round((completedItems / totalItems) * 100);

  document.getElementById("overallProgress").style.width =
    progressPercent + "%";
  document.getElementById(
    "progressText"
  ).textContent = `${progressPercent}% Complete (${completedItems}/${totalItems})`;
}

// Show progress section
function showProgressSection() {
  document.getElementById("progressSection").style.display = "block";
  document.getElementById("downloadStatus").innerHTML = "";
  document.getElementById("overallProgress").style.width = "0%";
  document.getElementById("progressText").textContent = "0% Complete";
}

// Hide progress section
function hideProgressSection() {
  document.getElementById("progressSection").style.display = "none";
}

// Add status message
function addStatusMessage(message, type) {
  const statusContainer = document.getElementById("downloadStatus");
  const messageElement = document.createElement("div");
  messageElement.className = `status-item ${type}`;
  messageElement.textContent = `${new Date().toLocaleTimeString()}: ${message}`;
  statusContainer.appendChild(messageElement);
  statusContainer.scrollTop = statusContainer.scrollHeight;
}

// Fetch video information for better display
async function fetchVideoInfo() {
  const pendingItems = downloadQueue.filter((item) => !item.title);

  for (let item of pendingItems) {
    try {
      const formData = new FormData();
      formData.append("url", item.url);
      formData.append("action", "getInfo");

      const response = await fetch("download.php", {
        method: "POST",
        body: formData,
      });

      if (response.ok) {
        const result = await response.json();
        if (result.success && result.title) {
          item.title = result.title;
        }
      }
    } catch (error) {
      console.log("Could not fetch video info for:", item.url);
    }
  }

  updateQueueDisplay();
}

// Show message to user
function showMessage(message, type) {
  // Remove existing messages
  const existingMessages = document.querySelectorAll(".message");
  existingMessages.forEach((msg) => msg.remove());

  // Create new message
  const messageElement = document.createElement("div");
  messageElement.className = `message ${type}`;
  messageElement.textContent = message;

  // Insert after header
  const header = document.querySelector("header");
  header.parentNode.insertBefore(messageElement, header.nextSibling);

  // Auto-remove after 5 seconds
  setTimeout(() => {
    if (messageElement.parentNode) {
      messageElement.remove();
    }
  }, 5000);
}

// Show/hide loading modal
function showLoading() {
  document.getElementById("loadingModal").style.display = "block";
}

function hideLoading() {
  document.getElementById("loadingModal").style.display = "none";
}

// Keyboard shortcuts
document.addEventListener("keydown", function (e) {
  // Enter key in single URL input
  if (e.key === "Enter" && e.target.id === "singleUrl") {
    e.preventDefault();
    addToQueue("single");
  }

  // Ctrl+Enter in bulk textarea
  if (e.key === "Enter" && e.ctrlKey && e.target.id === "bulkUrls") {
    e.preventDefault();
    addToQueue("bulk");
  }

  // Ctrl+D to start download
  if (e.key === "d" && e.ctrlKey) {
    e.preventDefault();
    if (!isDownloading && downloadQueue.length > 0) {
      startDownloadAll();
    }
  }

  // Escape to clear queue
  if (e.key === "Escape") {
    clearQueue();
  }
});

// Handle paste events for auto-processing
document.getElementById("singleUrl").addEventListener("paste", function (e) {
  setTimeout(() => {
    const pastedText = e.target.value;
    if (isValidYouTubeUrl(pastedText)) {
      // Auto-add if it's a valid URL
      setTimeout(() => addToQueue("single"), 100);
    }
  }, 10);
});

document.getElementById("bulkUrls").addEventListener("paste", function (e) {
  setTimeout(() => {
    const pastedText = e.target.value;
    const urls = pastedText.split("\n").filter((url) => url.trim().length > 0);

    // If multiple valid URLs, show suggestion
    const validUrls = urls.filter((url) => isValidYouTubeUrl(url.trim()));
    if (validUrls.length > 1) {
      showMessage(
        `${validUrls.length} valid URLs detected. Click "Add All to Queue" to process them.`,
        "info"
      );
    }
  }, 10);
});

// Auto-save queue to localStorage
function saveQueueToStorage() {
  localStorage.setItem("yt2mp3_queue", JSON.stringify(downloadQueue));
}

function loadQueueFromStorage() {
  const saved = localStorage.getItem("yt2mp3_queue");
  if (saved) {
    try {
      const parsed = JSON.parse(saved);
      // Only load pending items
      downloadQueue = parsed.filter((item) => item.status === "pending");
      updateQueueDisplay();
      updateQueueCount();
    } catch (error) {
      console.error("Could not load saved queue:", error);
    }
  }
}

// Save queue on changes
const originalAddToQueue = addToQueue;
addToQueue = function (...args) {
  originalAddToQueue.apply(this, args);
  saveQueueToStorage();
};

const originalRemoveFromQueue = removeFromQueue;
removeFromQueue = function (...args) {
  originalRemoveFromQueue.apply(this, args);
  saveQueueToStorage();
};

const originalClearQueue = clearQueue;
clearQueue = function (...args) {
  originalClearQueue.apply(this, args);
  saveQueueToStorage();
};

// Load saved queue on page load
document.addEventListener("DOMContentLoaded", function () {
  loadQueueFromStorage();
});
