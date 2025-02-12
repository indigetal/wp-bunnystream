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

    function openMediaUploader() {
      const fileFrame = wp.media({
        title: "Upload or Select a Video",
        library: { type: "video" },
        button: { text: "Use this Video" },
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
      wp.element.createElement(
        wp.components.Placeholder,
        {
          icon: "video-alt2",
          label: "Bunny Stream Video",
          instructions: "Upload or select a video from the media library.",
        },
        embedUrl
          ? wp.element.createElement("iframe", {
              src: embedUrl,
              width: "100%",
              height: "180",
              style: { border: "none" },
            })
          : wp.element.createElement(
              wp.components.Button,
              {
                isPrimary: true,
                onClick: openMediaUploader,
              },
              "Upload Video"
            )
      )
    );
  },

  save: function (props) {
    if (!props.attributes.iframeUrl) {
      return wp.element.createElement("div", {}, "No video selected.");
    }

    const params = new URLSearchParams();
    if (props.attributes.autoplay) params.append("autoplay", "true");
    if (props.attributes.muted) params.append("muted", "true");
    if (props.attributes.loop) params.append("loop", "true");
    if (props.attributes.playsInline) params.append("playsinline", "true");
    if (props.attributes.captions) params.append("captions", props.attributes.captions);
    if (props.attributes.preload) params.append("preload", props.attributes.preload);
    if (props.attributes.t) params.append("t", props.attributes.t);
    if (!props.attributes.chromecast) params.append("chromecast", "false");
    if (props.attributes.disableAirplay) params.append("disableAirplay", "true");
    if (props.attributes.disableIosPlayer) params.append("disableIosPlayer", "true");
    if (props.attributes.showHeatmap) params.append("showHeatmap", "true");
    if (props.attributes.showSpeed) params.append("showSpeed", "true");

    const baseUrl = props.attributes.iframeUrl.split("?")[0];
    const embedUrl = `${baseUrl}?${params.toString().replace(/&amp;/g, "&")}`;

    // console.log("Final Iframe URL on Frontend:", embedUrl);
    // console.log("Saved attributes in Frontend:", props.attributes);

    return wp.element.createElement("iframe", {
      src: embedUrl,
      width: "100%",
      height: "315",
      allow: "autoplay; fullscreen",
      allowFullScreen: true,
    });
  },
});
