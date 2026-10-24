<?php
/**
 * ZibAut — 联调还原（对照兄弟项目 + 主题调用方 + Z5 方法签名）
 */
if (!defined('ABSPATH')) {
    exit;
}

class ZibAut
{
    const OPT_CODE      = 'zibll_code';
    const OPT_END_TIME  = 'zibll_zat_end_time';
    const OPT_SIGN      = 'zibll_aut_sign';
    const OPT_SKIP_VER  = 'zibll_skip_update_version';
    const TRANSIENT_UPD = 'zibll_update_check';

    public static function get_aut_url()
    {
        $url = self::get_home_url();
        return self::top_host(self::replace_url($url));
    }

    public static function get_home_url()
    {
        return function_exists('home_url') ? home_url('/') : 'http://127.0.0.1/';
    }

    public static function is_local()
    {
        if (defined('ZIBLL_FORCE_NOT_LOCAL') && ZIBLL_FORCE_NOT_LOCAL) {
            return false;
        }
        $host = strtolower((string) parse_url(self::get_home_url(), PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
            return true;
        }
        if (preg_match('/\.(local|test|localhost|lan)$/i', $host)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }
        return (bool) apply_filters('zib_aut_is_local', false, $host);
    }

    public static function aut_required()
    {
        if (self::is_local()) {
            return false;
        }
        return (bool) apply_filters('zib_aut_required', !self::is_aut());
    }

    public static function replace_url($url)
    {
        return preg_replace('#^https?://#i', '', (string) $url);
    }

    public static function top_host($url)
    {
        $host = strtolower(trim((string) $url));
        $host = preg_replace('#/.*$#', '', $host);
        $host = preg_replace('#:\d+$#', '', $host);
        if (preg_match('/([^.]+\.[^.]+)$/', $host, $m)) {
            return $m[1];
        }
        return $host;
    }

    public static function delete()
    {
        delete_option(self::OPT_CODE);
        delete_option(self::OPT_END_TIME);
        delete_option(self::OPT_SIGN);
        return true;
    }

    public static function is_aut()
    {
        if (self::is_local()) {
            return true;
        }
        if (defined('ZIBLL_AUT_ALWAYS') && ZIBLL_AUT_ALWAYS) {
            return true;
        }
        $code = self::get_aut_code();
        if ($code === '') {
            return false;
        }
        $end = self::get_zat_end_time();
        if ($end && strtotime($end) < current_time('timestamp')) {
            return false;
        }
        return (bool) apply_filters('zib_aut_is_aut', true, $code);
    }

    public static function update_ok($result)
    {
        return self::is_ok($result);
    }

    public static function save_zat_end_time($result_data)
    {
        if (is_array($result_data) && isset($result_data['end_time'])) {
            update_option(self::OPT_END_TIME, $result_data['end_time']);
        } elseif (is_string($result_data) && $result_data !== '') {
            update_option(self::OPT_END_TIME, $result_data);
        }
        return true;
    }

    public static function get_aut_time()
    {
        $t = get_option(self::OPT_END_TIME, '');
        return $t === false ? '' : (string) $t;
    }

    public static function get_zat_end_time()
    {
        return self::get_aut_time();
    }

    public static function timing_aut()
    {
        if (!self::is_aut() || self::is_local()) {
            return;
        }
        self::curl_aut();
    }

    public static function curl_aut($aut_code = null)
    {
        $code = $aut_code ?: self::get_aut_code();
        if ($code === '') {
            return false;
        }
        $result = self::http_request(self::get_aut_api_url('aut'), self::curl_aut_data($code));
        if (!self::is_ok($result) || !self::is_singok($result)) {
            return false;
        }
        self::save_aut_code($code);
        if (is_array($result) && isset($result['data'])) {
            self::save_zat_end_time($result['data']);
            if (!empty($result['data']['sign'])) {
                update_option(self::OPT_SIGN, $result['data']['sign']);
            }
        }
        return true;
    }

    public static function http_request($url, $data)
    {
        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'body'    => is_array($data) ? $data : array(),
        ));
        if (is_wp_error($response)) {
            return array('error' => 1, 'msg' => $response->get_error_message());
        }
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        return is_array($json) ? $json : array('error' => 1, 'msg' => $body);
    }

    public static function is_ok($data)
    {
        if (!is_array($data)) {
            return false;
        }
        if (!empty($data['error'])) {
            return false;
        }
        return isset($data['status']) ? (int) $data['status'] === 1 : empty($data['error']);
    }

    public static function is_singok($data)
    {
        return self::is_ok($data);
    }

    public static function save_aut_code($aut_code)
    {
        update_option(self::OPT_CODE, sanitize_text_field((string) $aut_code));
        return true;
    }

    public static function get_aut_code()
    {
        return get_option(self::OPT_CODE) ?: '';
    }

    public static function get_aut_code_sign($aut_code)
    {
        $domain = self::get_aut_url();
        $secret = (string) apply_filters('zib_aut_sign_secret', defined('AUTH_KEY') ? AUTH_KEY : 'zibll');
        return hash_hmac('sha256', $domain . '|' . $aut_code, $secret);
    }

    public static function curl_aut_data($aut_code = '')
    {
        return array(
            'code'    => $aut_code ?: self::get_aut_code(),
            'domain'  => self::get_aut_url(),
            'home'    => self::get_home_url(),
            'version' => self::get_theme_version(),
            'sign'    => self::get_aut_code_sign($aut_code ?: self::get_aut_code()),
        );
    }

    public static function get_theme_version()
    {
        if (defined('THEME_VERSION')) {
            return THEME_VERSION;
        }
        return wp_get_theme()->get('Version');
    }

    public static function get_download_url()
    {
        return self::get_aut_api_url('download');
    }

    public static function curl_update($skip_flag = false)
    {
        if (self::skip_update() && !$skip_flag) {
            return false;
        }
        $result = self::http_request(self::get_aut_api_url('update'), array(
            'domain'  => self::get_aut_url(),
            'version' => self::get_theme_version(),
            'code'    => self::get_aut_code(),
        ));
        return self::update_save_data($result, $skip_flag);
    }

    public static function update_save_data($result_obj, $skip_flag = false)
    {
        if (!self::is_ok($result_obj) || empty($result_obj['data'])) {
            return false;
        }
        $data = $result_obj['data'];
        if (!is_array($data) || empty($data['version'])) {
            return false;
        }
        set_transient(self::TRANSIENT_UPD, $data, DAY_IN_SECONDS);
        return $data;
    }

    public static function timing_update()
    {
        self::curl_update();
    }

    public static function is_update($result = 'null')
    {
        if ($result !== 'null' && $result !== null) {
            return $result;
        }
        $skip = get_option(self::OPT_SKIP_VER, '');
        $cached = get_transient(self::TRANSIENT_UPD);
        if (!is_array($cached) || empty($cached['version'])) {
            return false;
        }
        if ($skip && $skip === $cached['version']) {
            return false;
        }
        if (version_compare($cached['version'], self::get_theme_version(), '<=')) {
            return false;
        }
        return $cached;
    }

    public static function skip_update()
    {
        return (bool) get_option(self::OPT_SKIP_VER, '');
    }

    public static function noaut_notice()
    {
        return '';
    }

    public static function admin_js()
    {
    }

    public static function csf_save()
    {
        return true;
    }

    public static function footer_html()
    {
    }

    public static function noaut_update()
    {
        if (self::is_aut() || self::is_local() || !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__('子比主题未授权，部分功能可能受限。', 'zib_language') . '</p></div>';
    }

    protected static function get_aut_api_url($action)
    {
        $base = defined('ZIBLL_AUT_API_BASE') ? ZIBLL_AUT_API_BASE : 'https://api.zibll.com/';
        return trailingslashit($base) . ltrim($action, '/');
    }
}
