<?php
/**
 * ZibCodeAut — 授权码二次校验 / 更新包校验（联调还原）
 */
if (!defined('ABSPATH')) {
    exit;
}

class ZibCodeAut
{
    public static function get_home_url()
    {
        return ZibAut::get_home_url();
    }

    /** @param string|array $code 授权码或接口返回体 */
    public static function isok($code)
    {
        if (is_array($code)) {
            return ZibAut::is_ok($code);
        }
        return is_string($code) && $code !== '' && $code === ZibAut::get_aut_code();
    }

    /**
     * aut_time_int 过滤器（2 参数：$time_int, $payload）
     */
    public static function update($time_int, $payload = null)
    {
        if (is_array($payload)) {
            ZibAut::update_save_data($payload, false);
        } elseif (is_array($time_int)) {
            ZibAut::update_save_data($time_int, false);
            $time_int = 0;
        }
        return is_numeric($time_int) ? (int) $time_int : 0;
    }

    public static function is_token($data)
    {
        if (!is_array($data)) {
            return false;
        }
        if (empty($data['token']) && empty($data['sign'])) {
            return true;
        }
        $expect = ZibAut::get_aut_code_sign(ZibAut::get_aut_code());
        return !empty($data['sign']) && hash_equals($expect, (string) $data['sign']);
    }

    /** 定时/入口触发的授权刷新 */
    public static function aut()
    {
        ZibAut::timing_aut();
    }

    /**
     * 后台更新流程中的整数校验（原 Z5 内用于防重放）
     * @param array|string $data
     */
    public static function zib_get_admin_require_intdata($data)
    {
        if (is_numeric($data)) {
            return (int) $data;
        }
        if (is_array($data) && isset($data['rand'])) {
            return (int) $data['rand'];
        }
        return 0;
    }
}

if (!function_exists('zib_get_admin_require_intdata')) {
    function zib_get_admin_require_intdata($data)
    {
        return ZibCodeAut::zib_get_admin_require_intdata($data);
    }
}

if (!function_exists('zibpay_admin_page_aut_randstr')) {
    function zibpay_admin_page_aut_randstr($data, $randstr)
    {
        if (!is_array($data)) {
            $data = array();
        }
        $data['randstr'] = $randstr;
        return $data;
    }
}

add_filter('setup_data_randstr', 'zibpay_admin_page_aut_randstr', 10, 2);
