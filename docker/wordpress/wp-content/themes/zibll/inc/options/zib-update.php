<?php
/**
 * 主题更新 / 授权辅助（联调明文，对齐 .encrypted-bak-rest/inc_options_zib-update.php）
 */
if (!defined('ABSPATH')) {
    exit;
}

if (defined('ZIBLL_ZIB_UPDATE_LOADED')) {
    return;
}
define('ZIBLL_ZIB_UPDATE_LOADED', true);

if (!function_exists('zib_is_aut')) {
    function zib_is_aut()
    {
        return ZibAut::is_aut();
    }
}

if (!function_exists('aut_required')) {
    function aut_required()
    {
        return ZibAut::aut_required();
    }
}

function zib_send_online_update()
{
    if (class_exists('ZibAut')) {
        ZibAut::curl_update(true);
    }
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

function zib_get_admin_aut_notice()
{
    if (!class_exists('ZibAut') || ZibAut::is_aut() || ZibAut::is_local()) {
        return '';
    }
    return '<div class="notice notice-warning"><p>' . esc_html__('子比主题未授权，请完成授权后使用全部功能。', 'zib_language') . '</p></div>';
}

function zib_zib_get_admin_aut_a_i($data, $code)
{
    if (!is_array($data)) {
        $data = array();
    }
    if (class_exists('ZibAut')) {
        $data['aut_code'] = $code !== '' && $code !== null ? (string) $code : ZibAut::get_aut_code();
        $data['is_aut']   = ZibAut::is_aut();
        $data['aut_url']  = ZibAut::get_aut_url();
    }
    return $data;
}

function zib_ajax_aut_error()
{
    zib_update_json_exit(array(
        'error' => true,
        'msg'   => __('授权校验失败', 'zib_language'),
    ));
}

add_action('wp_ajax_zib_ajax_aut_error', 'zib_ajax_aut_error');

function zib_admin_aut_err_notice()
{
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }
    $notice = zib_get_admin_aut_notice();
    if ($notice !== '') {
        echo $notice;
    }
}

function zib_shop_setup_autstr($str = 15)
{
    $len = max(1, (int) $str);
    if (function_exists('wp_generate_password')) {
        return wp_generate_password($len, false, false);
    }
    return substr(md5(uniqid((string) mt_rand(), true)), 0, $len);
}

function zib_update_check_file_md5_zhdh()
{
    return false;
}

add_action('admin_footer', 'zib_update_check_file_md5_zhdh');

/**
 * @param array|string $payload
 */
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
