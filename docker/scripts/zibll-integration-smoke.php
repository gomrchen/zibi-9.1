<?php
/**
 * 子比授权/更新/商城 — CLI 冒烟（容器内执行）
 * docker exec wp-app php /var/www/html/tools/zibll-integration-smoke.php
 */
declare(strict_types=1);

$wp_load = dirname(__DIR__) . '/wp-load.php';
if (!is_file($wp_load)) {
    fwrite(STDERR, "找不到 wp-load.php\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $wp_load;

$fail = 0;
$ok = 0;

function smoke(string $label, bool $pass, string $detail = ''): void
{
    global $ok, $fail;
    if ($pass) {
        $ok++;
        echo "[OK] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    } else {
        $fail++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

echo "=== 子比联调冒烟 " . date('Y-m-d H:i:s') . " ===\n";
echo 'home: ' . home_url('/') . "\n\n";

echo "--- 门禁类 / 符号 ---\n";
smoke('class ZibAut', class_exists('ZibAut'));
smoke('class ZibCodeAut', class_exists('ZibCodeAut'));
smoke('class ZibCodeUpdeta', class_exists('ZibCodeUpdeta'));
smoke('class ZibDB', class_exists('ZibDB'));
smoke('class ZibPaySDK', class_exists('ZibPaySDK'));
smoke('class zib_shop_setup', class_exists('zib_shop_setup'));
smoke('class zib_bbs_admin', class_exists('zib_bbs_admin'));
smoke('function zib_json_exit', function_exists('zib_json_exit'));
smoke('function zib_shop_get_url_chick_data', function_exists('zib_shop_get_url_chick_data'));
smoke('function zib_is_aut', function_exists('zib_is_aut'));
smoke('function aut_required', function_exists('aut_required'));
smoke('mu-plugin ZIBLL_AUT_OFFLINE_OK', defined('ZIBLL_AUT_OFFLINE_OK') && ZIBLL_AUT_OFFLINE_OK);

echo "\n--- 授权 / 签名 ---\n";
smoke('ZibAut::is_local', ZibAut::is_local() === true, 'localhost 应为 true');
smoke('ZibAut::is_aut (local)', ZibAut::is_aut() === true);
$sign = ZibAut::get_aut_code_sign('test-code');
smoke('ZibAut::get_aut_code_sign', is_string($sign) && strlen($sign) === 64);
$data = ZibAut::curl_aut_data('demo');
smoke(
    'ZibAut::curl_aut_data keys',
    is_array($data) && isset($data['code'], $data['domain'], $data['sign'], $data['version'])
);
smoke('zib_is_aut()', function_exists('zib_is_aut') && zib_is_aut() === true);

$filtered = ZibCodeAut::update(0, array('status' => 0, 'error' => 1));
smoke('ZibCodeAut::update(0, array)', $filtered === 0);
$filtered2 = apply_filters('aut_time_int', 0, array('probe' => 1));
smoke('filter aut_time_int returns int', is_int($filtered2));
$rand = ZibCodeAut::zib_get_admin_require_intdata(array('rand' => 42));
smoke('ZibCodeAut::zib_get_admin_require_intdata', $rand === 42);
smoke('ZibCodeAut::is_token 无 sign', ZibCodeAut::is_token(array('a' => 1)) === true);

echo "\n--- 商城校验壳 ---\n";
$chick = zib_shop_get_url_chick_data();
smoke(
    'zib_shop_get_url_chick_data 五键',
    is_array($chick) && isset($chick['domain'], $chick['home'], $chick['code'], $chick['sign'], $chick['version']),
    is_array($chick) ? implode(',', array_keys($chick)) : ''
);
smoke('zib_shop_setup::isok', class_exists('zib_shop_setup') && zib_shop_setup::isok('probe') === true);
$pay_modo = apply_filters('pay_modo_chick_data', array('probe' => 1));
smoke('filter pay_modo_chick_data', is_array($pay_modo) || $pay_modo !== null);

echo "\n--- ZibDB ---\n";
$exists = ZibDB::name('posts')->where('ID', 1)->is_exists();
smoke('ZibDB::is_exists', is_bool($exists));

echo "\n--- AJAX 注册（授权/更新门禁） ---\n";
foreach (array(
    'admin_curl_aut', 'admin_delete_aut', 'admin_skip_update', 'admin_detect_update',
    'zibll_online_update', 'zibll_download_update', 'zibll_update_file', 'zib_ajax_aut_error',
) as $act) {
    smoke('registered wp_ajax_' . $act, has_action('wp_ajax_' . $act) !== false);
}

echo "\n--- 业务 AJAX 注册抽查 ---\n";
foreach (array(
    'cart_add', 'shop_submit_order', 'shop_confirm_modal', 'initiate_pay',
    'posts_audit', 'plate_move', 'posts_topping_set', 'bbs_posts_save',
) as $act) {
    smoke('registered wp_ajax_' . $act, has_action('wp_ajax_' . $act) !== false);
}

$admin = get_users(array('role' => 'administrator', 'number' => 1));
if (!$admin) {
    smoke('存在管理员用户', false, '请先完成 WP 安装');
} else {
    wp_set_current_user($admin[0]->ID);
    smoke('current_user_can manage_options', current_user_can('manage_options'));
    smoke('handler zib_admin_curl_aut', function_exists('zib_admin_curl_aut'));
    smoke('handler zib_ajax_zibll_online_update', function_exists('zib_ajax_zibll_online_update'));

    $upd = ZibCodeUpdeta::online_update();
    smoke(
        'ZibCodeUpdeta::online_update shape',
        is_array($upd) && array_key_exists('error', $upd),
        is_array($upd) ? (string) ($upd['msg'] ?? '') : ''
    );
    $dl = ZibCodeUpdeta::download_update();
    smoke('ZibCodeUpdeta::download_update shape', is_array($dl) && array_key_exists('error', $dl));
    $uf = ZibCodeUpdeta::update_file();
    smoke('ZibCodeUpdeta::update_file shape', is_array($uf) && array_key_exists('error', $uf));
}

$fx = get_option('zibll_probe_fixtures');
smoke(
    '夹具 option 存在',
    is_array($fx) && !empty($fx['product_id']) && !empty($fx['plate_id']),
    is_array($fx) ? wp_json_encode(array(
        'product' => $fx['product_id'] ?? 0,
        'plate'   => $fx['plate_id'] ?? 0,
        'post'    => $fx['forum_post_id'] ?? 0,
    )) : '无'
);

echo "\n合计 OK={$ok} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
