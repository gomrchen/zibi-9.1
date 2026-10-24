<?php
/**
 * inc/code 入口（开源版：无主题授权门禁）
 */
if (!defined('ABSPATH')) {
    exit;
}

$code_dir = __DIR__ . '/';
require_once $code_dir . 'file.php';
require_once $code_dir . 'update.php';
require_once dirname(__DIR__) . '/options/zib-update.php';

if (!function_exists('zib_json_exit')) {
    function zib_json_exit($payload)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo wp_json_encode($payload);
        exit;
    }
}
