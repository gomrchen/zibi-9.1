<?php
/**
 * 后台授权 AJAX（联调还原）
 */
if (!defined('ABSPATH')) {
    exit;
}

function zib_admin_aut_add_action()
{
    add_action('wp_ajax_admin_curl_aut', 'zib_admin_curl_aut');
    add_action('wp_ajax_admin_delete_aut', 'zib_admin_delete_aut');
}

function zib_json_exit($payload)
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo wp_json_encode($payload);
    exit;
}

function zib_admin_curl_aut()
{
    if (!current_user_can('manage_options')) {
        zib_json_exit(array('error' => true, 'msg' => __('权限不足', 'zib_language')));
    }
    $aut_code = isset($_POST['aut_code']) ? sanitize_text_field(wp_unslash($_POST['aut_code'])) : '';
    if ($aut_code === '') {
        zib_json_exit(array('error' => true, 'msg' => __('请输入授权码', 'zib_language')));
    }
    ZibAut::save_aut_code($aut_code);
    $ok = ZibAut::curl_aut($aut_code);
    if (!$ok && !ZibAut::is_local()) {
        if (defined('ZIBLL_AUT_OFFLINE_OK') && ZIBLL_AUT_OFFLINE_OK) {
            ZibAut::save_aut_code($aut_code);
            $ok = true;
        }
    }
    if ($ok || ZibAut::is_local()) {
        zib_json_exit(array(
            'error'  => false,
            'msg'    => __('授权成功', 'zib_language'),
            'reload' => true,
        ));
    }
    zib_json_exit(array('error' => true, 'msg' => __('授权失败，请检查授权码与域名', 'zib_language')));
}

function zib_admin_delete_aut()
{
    if (!current_user_can('manage_options')) {
        zib_json_exit(array('error' => true, 'msg' => __('权限不足', 'zib_language')));
    }
    ZibAut::delete();
    zib_json_exit(array(
        'error'  => false,
        'msg'    => __('已撤销授权', 'zib_language'),
        'reload' => true,
    ));
}

function zib_is_local()
{
    return ZibAut::is_local();
}

zib_admin_aut_add_action();
