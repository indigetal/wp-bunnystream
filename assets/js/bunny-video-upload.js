document.addEventListener("DOMContentLoaded", function () {
  const uploadButton = document.getElementById("bunny-video-upload-button");
  const fileInput = document.getElementById("bunny-video-file");
  const statusMessage = document.getElementById("bunny-upload-status");

  if (!uploadButton || !fileInput || !statusMessage) {
    console.error("Bunny video upload elements are missing.");
    return;
  }

  uploadButton.addEventListener("click", function (e) {
    e.preventDefault();

    if (!fileInput.files.length) {
      alert("Please select a video file to upload.");
      return;
    }

    const file = fileInput.files[0];
    const allowedTypes = ["video/mp4", "video/webm"];
    const maxFileSize = bunnyUploadVars.maxFileSize;

    if (!allowedTypes.includes(file.type)) {
      alert("Invalid file type. Please upload an MP4 or WebM video.");
      return;
    }

    if (file.size > maxFileSize) {
      alert("File size exceeds the maximum allowed limit (500MB). Please choose a smaller file.");
      return;
    }

    const formData = new FormData();
    formData.append("action", "bunny_video_upload");
    formData.append("video", file);
    formData.append("security", bunnyUploadVars.nonce); // Include nonce for security

    // Check if additional metadata like post ID is needed
    const postId = uploadButton.dataset.postId;
    if (postId) {
      formData.append("post_id", postId);
    }

    // Display loading message
    statusMessage.textContent = "Uploading...";
    statusMessage.style.color = "blue";

    fetch(ajaxurl, {
      method: "POST",
      body: formData,
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          statusMessage.textContent = "Video uploaded successfully!";
          statusMessage.style.color = "green";

          // Optionally update UI with uploaded video details
          if (data.video_url) {
            const videoPreview = document.getElementById("bunny-video-preview");
            if (videoPreview) {
              videoPreview.src = data.video_url;
              videoPreview.style.display = "block";
            }
          }
        } else {
          statusMessage.textContent = `Error: ${data.message || "Unknown error occurred."}`;
          statusMessage.style.color = "red";
          console.error("Upload failed:", data);
        }
      })
      .catch((error) => {
        console.error("Upload error:", error);
        statusMessage.textContent = "An unexpected error occurred.";
        statusMessage.style.color = "red";
      });
  });
});
