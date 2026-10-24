<?php
/**
 * 主题更新 AJAX（开源版：无授权）
 */
if (!defined('ABSPATH')) {
    exit;
}
if (defined('ZIBLL_ZIB_UPDATE_LOADED')) {
    return;
}
define('ZIBLL_ZIB_UPDATE_LOADED', true);

function zib_send_online_update()
{
    return false;
}

function zib_ajax_zibll_download_update()
{
    if (!current_user_can('manage_options')) {
        zib_update_json_exit(array('error' => true, 'msg' => __('权限不足', 'zib_language')));
    }
    $result = class_exists('ZibCodeUpdeta') ? ZibCodeUpdeta::download_update() : array('error' => true, 'msg' => __('更新模块未加载', 'zib_language'));
    zib_update_json_exit($result);
}
add_action('wp_ajax_zibll_download_update', 'zib_ajax_zibll_download_update');

function zib_ajax_zibll_update_file()
{
    if (!current_user_can('manage_options')) {
        zib_update_json_exit(array('error' => true, 'msg' => __('权限不足', 'zib_language')));
    }
    $result = class_exists('ZibCodeUpdeta') ? ZibCodeUpdeta::update_file() : array('error' => true, 'msg' => __('更新模块未加载', 'zib_language'));
    zib_update_json_exit($result);
}
add_action('wp_ajax_zibll_update_file', 'zib_ajax_zibll_update_file');

function update_file_clear()
{
    if (function_exists('zib_update_file_clear')) {
        return zib_update_file_clear();
    }
    if (class_exists('ZibCodeUpdeta')) {
        return ZibCodeUpdeta::zib_update_file_clear();
    }
    return true;
}

function zib_shop_setup_autstr($str = 15)
{
    $len = max(1, (int) $str);
    return function_exists('wp_generate_password')
        ? wp_generate_password($len, false, false)
        : substr(md5(uniqid((string) mt_rand(), true)), 0, $len);
}

function zib_update_json_exit($payload)
{
    if (function_exists('zib_json_exit')) {
        zib_json_exit($payload);
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo wp_json_encode($payload);
    exit;
}
