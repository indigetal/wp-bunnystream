document.addEventListener("DOMContentLoaded", function () {
  const createVideoButton = document.getElementById("bunny-create-video-object");
  if (createVideoButton) {
    createVideoButton.addEventListener("click", function (event) {
      event.preventDefault();

      let formData = new URLSearchParams();
      formData.append("action", "bunny_manual_create_video");
      formData.append("title", "test");
      formData.append("security", bunnyUploadVars.nonce); // Ensure nonce is included

      fetch(ajaxurl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: formData,
      })
        .then((response) => response.json())
        .then((data) => {
          if (data.success) {
            alert("Video object created successfully!");
          } else {
            const errorMessage = data.data?.message || "An unknown error occurred.";
            alert("Error: " + errorMessage);
            console.error("Error response:", data);
          }
        })
        .catch((error) => {
          alert("An unexpected error occurred.");
          console.error(error);
        });
    });
  }
});
