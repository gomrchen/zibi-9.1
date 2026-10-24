#!/usr/bin/env bash
# 开源版扫描：无授权密文对照；检查残留授权符号 + 更新/业务 AJAX
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
THEME="$ROOT/wordpress/wp-content/themes/zibll"

DEEP=0
[[ "${1:-}" == "--deep" ]] && DEEP=1

missing=0
echo "【开源残留扫描】$(date '+%F %T')"
echo "主题: $THEME"
echo ""

echo "【授权符号残留】"
AUTH_PAT='ZibAut|ZibCodeAut|admin_curl_aut|admin_delete_aut|zib_ajax_aut_error|ZIBLL_AUT_|function zib_is_aut|function aut_required|zib_shop_get_aut_time_int|aut_time_int|zibll_aut_data|curl_aut_data|get_aut_code_sign|new_aut\.php'
if hits=$(rg -l "$AUTH_PAT" "$THEME" --glob '*.php' 2>/dev/null | rg -v 'vendor/' || true); then
  if [[ -n "$hits" ]]; then
    echo "$hits" | sed 's/^/  /'
    missing=1
  else
    echo "  无"
  fi
else
  echo "  无"
fi

echo ""
echo "【授权路径残留】"
path_hit=0
for f in "$THEME/inc/code/code.php" "$THEME/inc/code/aut.php" "$THEME/inc/code/tool.php" "$THEME/inc/code/action.php" "$THEME/inc/code/new_aut.php"; do
  if [[ -e "$f" ]]; then
    echo "  仍存在: $f"
    path_hit=1
    missing=1
  fi
done
[[ "$path_hit" -eq 0 ]] && echo "  无"

echo ""
echo "【活跃 Z5】"
if rg -l 'gzinflate|Z5Encrypt' "$THEME" --glob '*.php' 2>/dev/null | rg -v 'encrypted-bak|vendor/' | head -5; then
  echo "  仍有活跃密文"
  missing=1
else
  echo "  无"
fi

echo ""
echo "【备份目录】"
if [[ -d "$THEME/inc/code/.encrypted-bak" || -d "$THEME/.encrypted-bak-rest" ]]; then
  echo "  仍有 encrypted-bak，应删除"
  missing=1
else
  echo "  无"
fi

if [[ "$DEEP" -eq 1 ]]; then
  echo ""
  echo "【更新 AJAX】"
  for act in admin_skip_update admin_detect_update zibll_online_update zibll_download_update zibll_update_file; do
    if rg -q "wp_ajax_$act" "$THEME/inc/code" "$THEME/inc/options/zib-update.php" 2>/dev/null; then
      echo "  OK wp_ajax_$act"
    else
      echo "  缺 wp_ajax_$act"
      missing=1
    fi
  done
  echo ""
  echo "【业务 AJAX】"
  for act in cart_add shop_submit_order shop_confirm_modal initiate_pay posts_audit plate_move posts_topping_set bbs_posts_save; do
    if rg -q "wp_ajax_$act" "$THEME/inc/functions" "$THEME/zibpay" 2>/dev/null; then
      echo "  OK wp_ajax_$act"
    else
      echo "  缺 wp_ajax_$act"
      missing=1
    fi
  done
  echo ""
  echo "【ZibDB】"
  if rg -q 'class ZibDB' "$THEME/inc/class/db-class-legacy.php"; then
    echo "  OK ZibDB 存在"
  else
    echo "  缺 ZibDB"
    missing=1
  fi
fi

echo ""
if [[ "$missing" -eq 0 ]]; then
  echo "结论: 开源授权清理通过"
  exit 0
fi
echo "结论: 仍有残留，请继续清理"
exit 1
