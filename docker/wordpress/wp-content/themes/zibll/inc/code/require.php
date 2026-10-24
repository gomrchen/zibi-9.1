<?php
/**
 * inc/code 入口（联调明文版）
 */
if (!defined('ABSPATH')) {
    exit;
}

$code_dir = __DIR__ . '/';

require_once $code_dir . 'code.php';
require_once $code_dir . 'aut.php';
require_once $code_dir . 'file.php';
require_once $code_dir . 'tool.php';
require_once $code_dir . 'update.php';
require_once dirname(__DIR__) . '/options/zib-update.php';

function zib_init_add_action()
{
    if (ZibAut::aut_required() && !ZibAut::is_aut()) {
        $msg = __('主题未授权，请前往后台完成授权。', 'zib_language');
        if (is_admin()) {
            return;
        }
        wp_die(esc_html($msg), esc_html__('授权校验', 'zib_language'), array('response' => 503));
    }
}

function zib_admin_tool_action()
{
    add_action('init', 'zib_init_add_action', 1);
    add_action('admin_enqueue_scripts', array('ZibAut', 'admin_js'));
    add_action('admin_footer', array('ZibAut', 'footer_html'));
    add_filter('csf_save_options', array('ZibAut', 'csf_save'));
    if (!wp_next_scheduled('zib_timing_aut')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'zib_timing_aut');
    }
    if (!wp_next_scheduled('zib_timing_update')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', 'zib_timing_update');
    }
    add_action('zib_timing_aut', array('ZibAut', 'timing_aut'));
    add_action('zib_timing_update', array('ZibAut', 'timing_update'));
}

zib_admin_tool_action();
