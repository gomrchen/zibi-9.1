<?php
/**
 * 子比主题 inc/code 免授权改造对照（文档用途，不要 require）
 *
 * 真实逻辑在主题目录：
 *   wp-content/themes/zibll/inc/code/{code,aut,require,update,tool}.php
 * 机器可读清单：docker/免授权改造清单.json
 */

// --- ZibAut (code.php) ---
class ZibAut_免授权对照
{
    public static function aut_required()
    {
        return false;
    }

    public static function delete()
    {
        return true;
    }

    public static function is_aut()
    {
        return true;
    }

    public static function update_ok($result)
    {
        return true;
    }

    public static function save_zat_end_time($result_data)
    {
        return true;
    }

    public static function get_zat_end_time()
    {
        return false;
    }

    public static function timing_aut()
    {
        return;
    }

    public static function is_ok($data)
    {
        return true;
    }

    public static function is_singok($data)
    {
        return true;
    }

    public static function save_aut_code($aut_code)
    {
        return true;
    }

    /** 免授权改造：保留原义，直接取已保存的授权码 */
    public static function get_aut_code()
    {
        return get_option('zibll_code') ?: '';
    }

    public static function timing_update()
    {
        return;
    }

    public static function skip_update()
    {
        return true;
    }

    public static function noaut_notice()
    {
        return '';
    }

    public static function noaut_update()
    {
        return false;
    }
}

// --- ZibCodeAut (aut.php) ---
class ZibCodeAut_免授权对照
{
    public static function aut()
    {
        return;
    }
}

// --- require.php ---
function zib_init_add_action_免授权对照()
{
    // 原为 ZibAut::is_aut + md5_file + wp_die，现已移除
    return;
}

// --- ZibCodeUpdeta (update.php) ---
class ZibCodeUpdeta_免授权对照
{
    public static function is_ok($data)
    {
        return true;
    }
}

// --- tool.php ---
function zib_admin_aut_add_action_免授权对照()
{
    return;
}
