<?php
/**
 * Theme update functions (local replacement)
 * Online update functionality disabled for local auth
 */

/**
 * Send online update check (disabled)
 */
function zib_send_online_update()
{
    // Online update check disabled
    return false;
}

/**
 * AJAX download update (disabled)
 */
function zib_ajax_zibll_download_update()
{
    echo json_encode(array('error' => 1, 'msg' => '在线更新功能已禁用'));
    exit();
}
add_action('wp_ajax_zibll_download_update', 'zib_ajax_zibll_download_update');

/**
 * AJAX update file (disabled)
 */
function zib_ajax_zibll_update_file()
{
    echo json_encode(array('error' => 1, 'msg' => '在线更新功能已禁用'));
    exit();
}
add_action('wp_ajax_zibll_update_file', 'zib_ajax_zibll_update_file');

/**
 * Clear update files (disabled)
 */
function update_file_clear()
{
    return true;
}

/**
 * Get admin auth notice (returns empty - no notice needed)
 */
function zib_get_admin_aut_notice()
{
    return '';
}

/**
 * Get admin auth info display
 */
function zib_zib_get_admin_aut_a_i($data, $code)
{
    return '';
}
