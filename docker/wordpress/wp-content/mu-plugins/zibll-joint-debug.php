<?php
/**
 * Plugin Name: Zibll 联调辅助
 * Description: 本地联调常量 + 缓解后台「会话已过期」误报（仅 is_local / WP_DEBUG）
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZIBLL_AUT_OFFLINE_OK')) {
    define('ZIBLL_AUT_OFFLINE_OK', true);
}

/**
 * 本地 Docker 下 wp-auth-check 常因 cookie 域/心跳误判，已登录仍弹「会话已过期」。
 * 仅在本地或 WP_DEBUG 时关闭 auth-check 脚本。
 */
add_action('admin_init', static function () {
    $local = class_exists('ZibAut') ? ZibAut::is_local() : (strpos((string) (wp_parse_url(home_url(), PHP_URL_HOST) ?: ''), 'localhost') !== false);
    if (!$local && !(defined('WP_DEBUG') && WP_DEBUG)) {
        return;
    }
    remove_action('admin_enqueue_scripts', 'wp_auth_check_load');
}, 1);
