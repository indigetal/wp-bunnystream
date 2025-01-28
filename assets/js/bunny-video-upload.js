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
    const formData = new FormData();
    formData.append("action", "bunny_video_upload");
    formData.append("video", file);

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
        } else {
          statusMessage.textContent = `Error: ${data.message || "Unknown error occurred."}`;
          statusMessage.style.color = "red";
        }
      })
      .catch((error) => {
        console.error("Upload error:", error);
        statusMessage.textContent = "An unexpected error occurred.";
        statusMessage.style.color = "red";
      });
  });
});
