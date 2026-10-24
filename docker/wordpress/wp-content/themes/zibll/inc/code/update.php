<?php
/**
 * 主题在线更新（联调还原）
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

    public static function init()
    {
        self::zib_update_file_clear();
    }

    public static function check_url_data($rand_int, $data)
    {
        $rand = ZibCodeAut::zib_get_admin_require_intdata($rand_int);
        if (!$rand || !is_array($data)) {
            return false;
        }
        return ZibCodeAut::is_token($data);
    }

    public static function download_update()
    {
        return array('error' => true, 'msg' => __('联调环境未启用远程包下载', 'zib_language'));
    }

    public static function update_file()
    {
        return array('error' => true, 'msg' => __('联调环境未启用写盘更新', 'zib_language'));
    }

    public static function online_update()
    {
        return array('error' => true, 'msg' => __('请配置更新源或关闭在线更新', 'zib_language'));
    }

    public static function is_ok($data)
    {
        return ZibAut::is_ok($data);
    }
}

function zib_admin_skip_update()
{
    if (!current_user_can('manage_options')) {
        zib_json_exit(array('error' => true, 'msg' => __('权限不足', 'zib_language')));
    }
    $info = ZibAut::is_update();
    if (is_array($info) && !empty($info['version'])) {
        update_option(ZibAut::OPT_SKIP_VER, $info['version']);
    }
    zib_json_exit(array('error' => false, 'msg' => __('已忽略此次更新', 'zib_language'), 'reload' => true));
}

function zib_admin_detect_update()
{
    if (!current_user_can('manage_options')) {
        zib_json_exit(array('error' => true, 'msg' => __('权限不足', 'zib_language')));
    }
    ZibAut::curl_update(true);
    zib_json_exit(array(
        'error'  => false,
        'msg'    => __('检测完成', 'zib_language'),
        'reload' => true,
    ));
}

function zib_ajax_zibll_online_update()
{
    if (!current_user_can('manage_options')) {
        zib_json_exit(array('error' => true, 'msg' => __('权限不足', 'zib_language')));
    }
    zib_json_exit(ZibCodeUpdeta::online_update());
}

function zib_admin_update_add_action()
{
    add_action('wp_ajax_admin_skip_update', 'zib_admin_skip_update');
    add_action('wp_ajax_admin_detect_update', 'zib_admin_detect_update');
    add_action('wp_ajax_zibll_online_update', 'zib_ajax_zibll_online_update');
}

zib_admin_update_add_action();
