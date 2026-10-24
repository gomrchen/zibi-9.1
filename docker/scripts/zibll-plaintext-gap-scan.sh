#!/usr/bin/env bash
# 对照 Z5 备份与明文实现，输出符号/钩子缺口（可重复跑）
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
THEME="$ROOT/wordpress/wp-content/themes/zibll"
BAK_CODE="$THEME/inc/code/.encrypted-bak"
BAK_REST="$THEME/.encrypted-bak-rest"

pairs=(
  "$BAK_CODE/code.php|$THEME/inc/code/code.php|inc/code/code.php"
  "$BAK_CODE/aut.php|$THEME/inc/code/aut.php|inc/code/aut.php"
  "$BAK_CODE/update.php|$THEME/inc/code/update.php|inc/code/update.php"
  "$BAK_CODE/tool.php|$THEME/inc/code/tool.php|inc/code/tool.php"
  "$BAK_REST/inc_options_zib-update.php|$THEME/inc/options/zib-update.php|inc/options/zib-update.php"
  "$BAK_REST/zibpay_class_ajax-class.php|$THEME/zibpay/class/ajax-class.php|zibpay/class/ajax-class.php"
  "$BAK_REST/inc_functions_shop_inc_class.setup.php|$THEME/inc/functions/shop/inc/class.setup.php|shop/class.setup.php"
  "$BAK_REST/inc_functions_shop_inc_public.php|$THEME/inc/functions/shop/inc/public.php|shop/public.php"
  "$BAK_REST/inc_functions_bbs_inc_class.admin.php|$THEME/inc/functions/bbs/inc/class.admin.php|bbs/class.admin.php"
  "$BAK_REST/zibpay_functions_admin_admin-options.php|$THEME/zibpay/functions/admin/admin-options.php|zibpay/admin-options.php"
)

enc_symbols() {
  strings "$1" 2>/dev/null | rg -o "function [a-zA-Z0-9_]+" | awk '{print $2}' | sort -u
}

live_symbols() {
  rg -o "function [a-zA-Z0-9_]+" "$1" 2>/dev/null | awk '{print $2}' | sort -u
}

enc_hooks() {
  strings "$1" 2>/dev/null | rg -o "add_(action|filter)\('[^']+'|wp_ajax[a-zA-Z0-9_]+" | sort -u
}

live_hooks() {
  rg -o "add_(action|filter)\('[^']+'|wp_ajax[a-zA-Z0-9_]+" "$1" 2>/dev/null | sort -u
}

DEEP=0
if [[ "${1:-}" == "--deep" ]]; then
  DEEP=1
fi

missing=0
echo "【子比明文缺口扫描】$(date '+%F %T')"
echo "主题: $THEME"
echo ""

for entry in "${pairs[@]}"; do
  IFS='|' read -r enc live label <<< "$entry"
  if [[ ! -f "$enc" || ! -f "$live" ]]; then
    echo "【跳过】$label — 文件缺失"
    continue
  fi
  mf=$(comm -23 <(enc_symbols "$enc") <(live_symbols "$live") | rg -v '^$' || true)
  mh=$(comm -23 <(enc_hooks "$enc") <(live_hooks "$live") | rg -v '^$' || true)
  if [[ -n "$mf" || -n "$mh" ]]; then
    echo "【缺口】$label"
    if [[ -n "$mf" ]]; then
      echo "  函数(仅密文 strings):"
      echo "$mf" | sed 's/^/    /'
      missing=1
    fi
    if [[ -n "$mh" ]]; then
      echo "  钩子(仅密文 strings):"
      echo "$mh" | sed 's/^/    /'
      missing=1
    fi
  else
    echo "【对齐】$label"
  fi
done

echo ""
echo "【活跃 Z5 扫描】"
if rg -l 'gzinflate|Z5Encrypt' "$THEME" --glob '*.php' 2>/dev/null | rg -v 'encrypted-bak' | head -1; then
  echo "  仍有活跃密文，请检查上方路径"
  missing=1
else
  echo "  无活跃 Z5（仅备份目录）"
fi

if [[ "$DEEP" -eq 1 ]]; then
  echo "【深度】ZibDB 链式方法（密文 file.php strings → db-class-legacy）"
  enc_db=$(strings "$BAK_CODE/file.php" 2>/dev/null | rg -o "function [a-zA-Z0-9_]+" | awk '{print $2}' | sort -u)
  live_db=$(rg -o "function [a-zA-Z0-9_]+\(" "$THEME/inc/class/db-class-legacy.php" | sed 's/function //' | sed 's/(.*//' | sort -u)
  mdb=$(comm -23 <(echo "$enc_db") <(echo "$live_db") | rg -v '^$' || true)
  if [[ -n "$mdb" ]]; then
    echo "  缺方法: $mdb"
    missing=1
  else
    echo "  链式 API 已覆盖"
  fi

  echo ""
  echo "【深度】后台授权/更新 AJAX（清单全量必注册）"
  for act in admin_curl_aut admin_delete_aut admin_skip_update admin_detect_update zibll_online_update zibll_download_update zibll_update_file zib_ajax_aut_error; do
    if rg -q "wp_ajax_$act" "$THEME/inc/code" "$THEME/inc/options/zib-update.php" 2>/dev/null; then
      echo "  OK wp_ajax_$act"
    else
      echo "  缺注册 wp_ajax_$act"
      missing=1
    fi
  done

  echo ""
  echo "【深度】业务 AJAX 抽查（商城/论坛成功路径）"
  for act in cart_add shop_submit_order shop_confirm_modal initiate_pay posts_audit plate_move posts_topping_set bbs_posts_save; do
    if rg -q "wp_ajax_$act" "$THEME/inc/functions" "$THEME/zibpay" 2>/dev/null; then
      echo "  OK wp_ajax_$act"
    else
      echo "  缺注册 wp_ajax_$act"
      missing=1
    fi
  done

  echo ""
  echo "【深度】aut_time_int 过滤器参数个数"
  if rg -q "add_filter\('aut_time_int'.*,\s*2\)" "$THEME/zibpay/class/ajax-class.php"; then
    echo "  OK ZibPaySDK 注册 accepted_args=2"
  else
    echo "  警告: aut_time_int 可能未声明 2 个参数"
    missing=1
  fi
fi

echo ""
if [[ "$missing" -eq 0 ]]; then
  echo "结论: strings 可见符号/钩子均已覆盖（VM 内动态符号需运行时验证）"
  exit 0
fi
echo "结论: 存在 strings 级缺口，请对照备份补明文或确认仅为类方法/内联"
exit 1
