<?php
/**
 * 主题更新辅助（开源版：无远程授权）
 */
if (!defined('ABSPATH')) {
    exit;
}

class ZibCodeUpdeta
{
    public static function zib_update_file_clear()
    {
        $dir = get_theme_file_path('update');
        if (!is_dir($dir)) {
            return true;
        }
        foreach (glob($dir . '/*') ?: array() as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        return true;
    }

    public static function download_update()
    {
        return array('error' => true, 'msg' => __('未配置远程更新源', 'zib_language'));
    }

    public static function update_file()
    {
        return array('error' => true, 'msg' => __('未启用写盘更新', 'zib_language'));
    }

    public static function online_update()
    {
        return array('error' => true, 'msg' => __('开源发行版请通过发布渠道手动更新主题', 'zib_language'));
    }
}

function zib_admin_skip_update()
{
    if (!current_user_can('manage_options')) {
        zib_json_exit(array('error' => true, 'msg' => __('权限不足', 'zib_language')));
    }
    zib_json_exit(array('error' => false, 'msg' => __('已忽略', 'zib_language'), 'reload' => true));
}

function zib_admin_detect_update()
{
    if (!current_user_can('manage_options')) {
        zib_json_exit(array('error' => true, 'msg' => __('权限不足', 'zib_language')));
    }
    zib_json_exit(array(
        'error'  => false,
        'msg'    => __('当前为开源发行版，请关注项目发布页获取更新', 'zib_language'),
        'reload' => false,
    ));
}

function zib_ajax_zibll_online_update()
{
    if (!current_user_can('manage_options')) {
        zib_json_exit(array('error' => true, 'msg' => __('权限不足', 'zib_language')));
    }
    zib_json_exit(ZibCodeUpdeta::online_update());
}

add_action('wp_ajax_admin_skip_update', 'zib_admin_skip_update');
add_action('wp_ajax_admin_detect_update', 'zib_admin_detect_update');
add_action('wp_ajax_zibll_online_update', 'zib_ajax_zibll_online_update');

if (!function_exists('zib_update_file_clear')) {
    function zib_update_file_clear()
    {
        return ZibCodeUpdeta::zib_update_file_clear();
    }
}
