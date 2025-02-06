<?php
namespace WP_BunnyStream\Utils;

class BunnyLogger {
    public static function log($message, $type = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[BunnyStream] [%s] %s', strtoupper($type), $message));
        }
    }
}
