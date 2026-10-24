<?php
/**
 * ZibPaySDK — 支付 SDK 引导（联调还原）
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('ZibPaySDK')) {
    class ZibPaySDK
    {
        public function __construct()
        {
            add_filter('aut_time_int', array('ZibCodeAut', 'update'));
        }

        public static function tool($data)
        {
            return apply_filters('zibpay_sdk_tool', $data);
        }

        public function error_page()
        {
            wp_die(esc_html__('支付接口异常', 'zib_language'), esc_html__('支付错误', 'zib_language'), array('response' => 500));
        }

        public function post($data)
        {
            return is_array($data) ? $data : array();
        }

        public function err_del()
        {
            return true;
        }

        public function err()
        {
            return array('error' => 1, 'msg' => __('支付失败', 'zib_language'));
        }

        public function pay_check($url = null)
        {
            if ($url && !wp_http_validate_url($url)) {
                return false;
            }
            return true;
        }

        public function pay()
        {
            return array('error' => 1, 'msg' => __('请使用主题内置支付通道', 'zib_language'));
        }

        public function __call($name, $arguments)
        {
            return null;
        }

        public static function __callStatic($name, $arguments)
        {
            return null;
        }
    }
}
