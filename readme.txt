=== WP Bunny Stream ===
Contributors: Brandon Meyer
Tags: wordpress, media library, bunnynet, video streaming, video organization
Requires at least: 5.3
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

WP Bunny Stream seamlessly integrates Bunny.net's HTTP API with the WordPress Media Library, enabling high-speed, buffer-free video offloading and playback. Videos uploaded to the Media Library are automatically offloaded to Bunny.net, ensuring optimized performance and reduced server load.
Key Features:

- Effortless Offloading – Automatically offloads videos to Bunny.net when uploaded to the Media Library and removes them when deleted.
- MP4 Compatibility – Uses Bunny.net's MP4 URL by default to ensure full compatibility with the Media Library and all WordPress video blocks.
- Automated Organization – Creates and assigns a user-specific collection at Bunny.net when a user uploads their first video, keeping libraries organized.
- User Cleanup – Deletes a user's assigned collection and all its videos when their WordPress account is removed.
- Enhanced Gutenberg Block – Provides a fully-featured Bunny Embed Player block, allowing users to configure all available parameters directly from the block settings.

No manual video handling needed—WP Bunny Stream automates the entire process, letting you focus on your content.

== Installation ==

= Minimum Requirements =

* WordPress 5.3 or greater
* PHP 7.4 or greater

= Installation Instructions =

1. Download and install WP Bunny Stream through your WordPress dashboard or manually upload the plugin files to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to "Settings > Bunny.net Settings" to configure your Bunny.net API credentials and Library ID.
4. Upload videos to your WordPress Media Library and let WP Bunny Stream handle the rest!

== Frequently Asked Questions ==

= What is Bunny.net? =
Bunny.net is a high-speed, global CDN with advanced video streaming solutions. It ensures seamless video delivery to users anywhere in the world.

= Do I need a Bunny.net account to use this plugin? =
Yes, you'll need an active Bunny.net account and access to their video streaming service to use WP Bunny Stream.

= Does this work with multisite networks? =
Yes, WP Bunny Stream is fully compatible with WordPress multisite networks.

== Changelog ==

= 0.1.0 =
* Automatic video uploading to Bunny.net via the HTTP API.
* Added support for user-specific video collections.

== Upgrade Notice ==
