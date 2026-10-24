<?php
/**
 * Shop public utility functions (local replacement)
 */

/**
 * Check if zibpay_order table exists (cached within the same request)
 */
function zib_shop_check_order_table_exists() {
    static $exists = null;
    if ($exists === null) {
        global $wpdb;
        $table = $wpdb->prefix . 'zibpay_order';
        $exists = ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table);
    }
    return $exists;
}

/**
 * Get shop order type constant
 */
function zib_shop_get_order_type()
{
    return '10';
}

/**
 * Get shop home URL
 */
function zib_shop_get_home_url()
{
    static $url = null;
    if ($url === null) {
        $pages = get_pages(array(
            'meta_key'   => '_wp_page_template',
            'meta_value' => 'pages/shop-home.php',
        ));
        if (!empty($pages[0])) {
            $url = get_permalink($pages[0]->ID);
        } else {
            // Try shop archive
            $url = get_post_type_archive_link('shop_product');
            if (!$url) {
                $url = home_url('/shop/');
            }
        }
    }
    return $url;
}

/**
 * Get express/shipping companies data
 */
function zib_shop_get_express_companies_data()
{
    return array(
        'SF'      => array('name' => '顺丰速运', 'code' => 'SF'),
        'YTO'     => array('name' => '圆通速递', 'code' => 'YTO'),
        'ZTO'     => array('name' => '中通快递', 'code' => 'ZTO'),
        'STO'     => array('name' => '申通快递', 'code' => 'STO'),
        'YD'      => array('name' => '韵达速递', 'code' => 'YD'),
        'JTSD'    => array('name' => '极兔速递', 'code' => 'JTSD'),
        'EMS'     => array('name' => 'EMS', 'code' => 'EMS'),
        'YZPY'    => array('name' => '邮政包裹', 'code' => 'YZPY'),
        'DBL'     => array('name' => '德邦快递', 'code' => 'DBL'),
        'JD'      => array('name' => '京东物流', 'code' => 'JD'),
        'OTHER'   => array('name' => '其他', 'code' => 'OTHER'),
    );
}

/**
 * Get after-sale status count
 */
function zib_shop_get_after_sale_status_count($status = '', $user_id = 0)
{
    unset($user_id);
    // Check if table exists (cached)
    if (!zib_shop_check_order_table_exists()) {
        return 0;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'zibpay_order';
    $order_type = zib_shop_get_order_type();

    if (is_array($status)) {
        $count = 0;
        foreach ($status as $s) {
            $like = '%"after_sale_status";s:1:"' . esc_sql($s) . '"%';
            $c = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE order_type = %s AND status = 1 AND other LIKE %s",
                $order_type, $like
            ));
            $count += (int) $c;
        }
        return $count;
    }

    $like = '%"after_sale_status";s:1:"' . esc_sql($status) . '"%';
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE order_type = %s AND status = 1 AND other LIKE %s",
        $order_type, $like
    ));
}

/**
 * Get shipping status count
 */
function zib_shop_get_shipping_status_count($status = '0', $user_id = 0)
{
    unset($user_id);
    // Check if table exists (cached)
    if (!zib_shop_check_order_table_exists()) {
        return 0;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'zibpay_order';
    $order_type = zib_shop_get_order_type();
    $like = '%"shipping_status";s:1:"' . esc_sql($status) . '"%';

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE order_type = %s AND status = 1 AND other LIKE %s",
        $order_type, $like
    ));
}

/**
 * Check if product has multiple SKU data
 */
function zib_shop_get_is_mudata($post_id = 0)
{
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    $config = get_post_meta($post_id, 'shop_product_config', true);
    if (!is_array($config)) {
        return false;
    }
    return !empty($config['mudata']) || !empty($config['sku']);
}

/**
 * URL check data for auth (disabled)
 */
function zib_shop_get_url_chick_data()
{
    if (!class_exists('ZibAut')) {
        return array();
    }
    $code = ZibAut::get_aut_code();
    return array(
        'domain'  => ZibAut::get_aut_url(),
        'home'    => ZibAut::get_home_url(),
        'code'    => $code,
        'sign'    => $code !== '' ? ZibAut::get_aut_code_sign($code) : '',
        'version' => ZibAut::get_theme_version(),
    );
}

add_filter('pay_modo_chick_data', array('ZibPaySDK', 'tool'), 10, 1);