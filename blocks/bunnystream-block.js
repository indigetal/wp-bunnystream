const { useEffect } = wp.element;

wp.blocks.registerBlockType("bunnystream/video", {
  title: "Bunny Embed Player",
  icon: "video-alt",
  category: "media",
  supports: {
    align: true,
  },
  attributes: {
    iframeUrl: { type: "string", default: "" }, // Stores the full Bunny Embed URL only in block attributes
    autoplay: { type: "boolean", default: false },
    muted: { type: "boolean", default: false },
    loop: { type: "boolean", default: false },
    playsInline: { type: "boolean", default: true },
    captions: { type: "string", default: "" },
    preload: { type: "boolean", default: false },
    t: { type: "string", default: "" },
    chromecast: { type: "boolean", default: true },
    disableAirplay: { type: "boolean", default: false },
    disableIosPlayer: { type: "boolean", default: false },
    showHeatmap: { type: "boolean", default: false },
    showSpeed: { type: "boolean", default: true },
  },

  edit: function (props) {
    const { setAttributes, attributes } = props;

    // Load _bunny_iframe_url from postmeta when block is first loaded
    useEffect(() => {
      const meta = wp.data.select("core/editor").getEditedPostAttribute("meta");

      if (meta && meta["_bunny_iframe_url"] && attributes.iframeUrl !== meta["_bunny_iframe_url"]) {
        setAttributes({ iframeUrl: meta["_bunny_iframe_url"] });

        // Save iframeUrl inside the post content so it persists on the frontend
        wp.data.dispatch("core/block-editor").updateBlockAttributes(props.clientId, {
          iframeUrl: meta["_bunny_iframe_url"],
        });
      }
    }, [attributes.iframeUrl]);

    function openFileUploader() {
      const uploadInput = document.createElement("input");
      uploadInput.type = "file";
      uploadInput.accept = "video/*";
      uploadInput.style.display = "none";

      uploadInput.addEventListener("change", function (event) {
        const file = event.target.files[0];

        if (!file) return;

        const formData = new FormData();
        formData.append("file", file);
        formData.append("action", "upload-attachment");
        formData.append("_wpnonce", wp.media.model.settings.post.nonce);

        wp.apiFetch({
          url: wp.ajax.settings.url,
          method: "POST",
          body: formData,
        }).then((response) => {
          if (response?.id) {
            wp.apiFetch({
              path: `/wp/v2/media/${response.id}?_fields=id,meta`,
              method: "GET",
            }).then((attachmentData) => {
              if (attachmentData?.meta?._bunny_iframe_url) {
                setAttributes({ iframeUrl: attachmentData.meta._bunny_iframe_url });
              }
            });
          }
        });
      });

      document.body.appendChild(uploadInput);
      uploadInput.click();
      document.body.removeChild(uploadInput);
    }

    function openMediaUploader() {
      const fileFrame = wp.media({
        title: "Media Library",
        library: { type: "video" },
        button: { text: "Media Library" },
        multiple: false,
      });

      fileFrame.on("select", function () {
        const attachment = fileFrame.state().get("selection").first().toJSON();

        // Fetch postmeta for `_bunny_iframe_url`
        wp.apiFetch({
          path: `/wp/v2/media/${attachment.id}?_fields=id,meta`,
          method: "GET",
        }).then((attachmentData) => {
          if (attachmentData?.meta?._bunny_iframe_url) {
            setAttributes({ iframeUrl: attachmentData.meta._bunny_iframe_url });
            // console.log("Updated iframe URL:", attachmentData.meta._bunny_iframe_url);
          } else {
            // console.warn("No _bunny_iframe_url found in postmeta.");
          }
        });
      });

      fileFrame.open();
    }

    // Ensure the iframe URL is valid
    let embedUrl = attributes.iframeUrl || "";
    if (embedUrl) {
      try {
        const url = new URL(embedUrl); // Parse the base URL properly
        const params = url.searchParams;

        if (attributes.autoplay) params.set("autoplay", "true");
        if (attributes.muted) params.set("muted", "true");
        if (attributes.loop) params.set("loop", "true");
        if (attributes.playsInline) params.set("playsinline", "true");
        if (attributes.captions) params.set("captions", attributes.captions);
        if (attributes.preload) params.set("preload", attributes.preload);
        if (attributes.t) params.set("t", attributes.t);
        if (!attributes.chromecast) params.set("chromecast", "false");
        if (attributes.disableAirplay) params.set("disableAirplay", "true");
        if (attributes.disableIosPlayer) params.set("disableIosPlayer", "true");
        if (attributes.showHeatmap) params.set("showHeatmap", "true");
        if (attributes.showSpeed) params.set("showSpeed", "true");

        embedUrl = url.origin + url.pathname + "?" + params.toString();
      } catch (error) {
        // console.error("Invalid iframe URL:", embedUrl, error);
      }
    }

    // Log the fully constructed iframe URL
    // console.log("Final Iframe URL in Block:", embedUrl);

    return wp.element.createElement(
      wp.element.Fragment,
      {},
      wp.element.createElement(
        wp.blockEditor.InspectorControls,
        {},
        wp.element.createElement(
          wp.components.PanelBody,
          { title: "Embed Settings", initialOpen: true },
          wp.element.createElement(wp.components.ToggleControl, {
            label: "Autoplay",
            checked: attributes.autoplay,
            onChange: (value) => setAttributes({ autoplay: value }),
          }),
          wp.element.createElement(wp.components.ToggleControl, {
            label: "Loop",
            checked: attributes.loop,
            onChange: (value) => setAttributes({ loop: value }),
          }),
          wp.element.createElement(wp.components.ToggleControl, {
            label: "Muted",
            checked: attributes.muted,
            onChange: (value) => setAttributes({ muted: value }),
          }),
          wp.element.createElement(wp.components.ToggleControl, {
            label: "Plays Inline",
            checked: attributes.playsInline,
            onChange: (value) => setAttributes({ playsInline: value }),
          })
        )
      ),
      !embedUrl
        ? wp.element.createElement(
            wp.components.Placeholder,
            {
              icon: "video-alt2",
              label: "Bunny Stream Video",
              instructions: "Upload a video file or pick one from your media library.",
            },
            wp.element.createElement(
              wp.components.Button,
              {
                isPrimary: true,
                onClick: openFileUploader,
                style: { backgroundColor: "#007cba", color: "white", marginRight: "10px" },
              },
              "Upload Video"
            ),
            wp.element.createElement(
              wp.components.Button,
              {
                isSecondary: true,
                onClick: openMediaUploader,
                style: { backgroundColor: "white", border: "1px solid #007cba", color: "#007cba" },
              },
              "Media Library"
            )
          )
        : wp.element.createElement(
            "div",
            { style: { position: "relative", paddingTop: "56.25%" } },
            wp.element.createElement("iframe", {
              src: embedUrl,
              loading: "lazy",
              style: {
                border: "none",
                position: "absolute",
                top: "0",
                height: "100%",
                width: "100%",
              },
              allow: "accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture",
              allowFullScreen: true,
            })
          )
    );
  },

  save: function (props) {
    return null; // Rendering handled by bunnystream_render_video() in main plugin file
  },
});
