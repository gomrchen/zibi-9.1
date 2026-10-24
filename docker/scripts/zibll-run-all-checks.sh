#!/usr/bin/env bash
# 子比全量联调：缺口对齐 + 夹具 + 冒烟 + ajax + 前台 HTTP + 矩阵报告
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAMP="$(date '+%Y%m%d-%H%M')"
REPORT_LATEST="$ROOT/子比联调检查报告.txt"
REPORT_STAMPED="$ROOT/子比联调检查报告-${STAMP}.txt"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass_n=0
fail_n=0
log() { echo "$@"; }
ok()  { pass_n=$((pass_n+1)); log "[OK] $*"; }
fail(){ fail_n=$((fail_n+1)); log "[FAIL] $*"; }

{
  echo "=== 子比全量联调检查 $(date '+%Y-%m-%d %H:%M:%S') ==="
  echo "stamp=$STAMP"
  echo ""
} | tee "$REPORT_LATEST"

echo "======== 1/6 明文缺口扫描 ========" | tee -a "$REPORT_LATEST"
if "$ROOT/scripts/zibll-plaintext-gap-scan.sh" --deep 2>&1 | tee -a "$REPORT_LATEST" | tee "$TMP/gap.txt" | tail -5; then
  ok "缺口扫描"
else
  fail "缺口扫描"
fi

echo "" | tee -a "$REPORT_LATEST"
echo "======== 2/6 种夹具 ========" | tee -a "$REPORT_LATEST"
docker exec wp-app php /var/www/html/tools/zibll-seed-fixtures.php 2>&1 | tee -a "$REPORT_LATEST" | tee "$TMP/seed.txt"
ok "种夹具"

echo "" | tee -a "$REPORT_LATEST"
echo "======== 3/6 CLI 冒烟 ========" | tee -a "$REPORT_LATEST"
if docker exec wp-app php /var/www/html/tools/zibll-integration-smoke.php 2>&1 | tee -a "$REPORT_LATEST" | tee "$TMP/smoke.txt"; then
  ok "CLI 冒烟"
else
  fail "CLI 冒烟"
fi

echo "" | tee -a "$REPORT_LATEST"
echo "======== 4/6 admin-ajax 探测 ========" | tee -a "$REPORT_LATEST"
if docker exec wp-app php /var/www/html/tools/zibll-ajax-probe.php 2>&1 | tee "$TMP/probe.txt" | tee -a "$REPORT_LATEST"; then
  ok "ajax-probe"
else
  fail "ajax-probe"
fi

echo "" | tee -a "$REPORT_LATEST"
echo "======== 5/6 宿主机 HTTP（未登录/前台） ========" | tee -a "$REPORT_LATEST"
WP_PORT="${WP_PORT:-8080}"
BASE="http://127.0.0.1:${WP_PORT}"

CART_BODY="$(curl -s -X POST "${BASE}/wp-admin/admin-ajax.php" -d 'action=cart_add&product_id=1' || true)"
if [[ "$CART_BODY" == "0" || "$CART_BODY" == *"no_logged"* || "$CART_BODY" == *"请先登录"* ]]; then
  ok "cart_add 未登录 — ${CART_BODY:0:80}"
  echo "[OK] cart_add 未登录 — body=${CART_BODY}" | tee -a "$REPORT_LATEST"
else
  fail "cart_add 未登录 — ${CART_BODY:0:120}"
  echo "[FAIL] cart_add 未登录 — body=${CART_BODY}" | tee -a "$REPORT_LATEST"
fi

HOME_CODE="$(curl -sS -o "$TMP/home.html" -w '%{http_code}' "${BASE}/" || echo 0)"
if [[ "$HOME_CODE" == "200" ]] && ! rg -q '主题未授权|Fatal error' "$TMP/home.html"; then
  ok "前台首页 HTTP $HOME_CODE"
  echo "[OK] 前台首页 HTTP $HOME_CODE" | tee -a "$REPORT_LATEST"
else
  fail "前台首页 HTTP $HOME_CODE"
  echo "[FAIL] 前台首页 HTTP $HOME_CODE" | tee -a "$REPORT_LATEST"
fi

PROD_CODE="$(curl -sSL -o "$TMP/prod.html" -w '%{http_code}' "${BASE}/shop/5.html" || echo 0)"
if [[ "$PROD_CODE" == "200" ]] && rg -q '联调探测商品|加入购物车|立即购买' "$TMP/prod.html"; then
  ok "商品页 HTTP $PROD_CODE"
  echo "[OK] 商品页 HTTP $PROD_CODE" | tee -a "$REPORT_LATEST"
else
  fail "商品页 HTTP $PROD_CODE"
  echo "[FAIL] 商品页 HTTP $PROD_CODE" | tee -a "$REPORT_LATEST"
fi

echo "" | tee -a "$REPORT_LATEST"
echo "======== 6/6 清单对齐矩阵 ========" | tee -a "$REPORT_LATEST"
A_OK=$(rg -q '结论: strings' "$TMP/gap.txt" && echo OK || echo FAIL)
B_OK=$(rg -q '提交授权码' "$TMP/probe.txt" && rg -q '授权成功' "$TMP/probe.txt" && echo OK || echo FAIL)
C_OK=$(rg -q 'ajax zibll_update_file' "$TMP/probe.txt" && echo OK || echo FAIL)
D_OK=$(rg -q 'shop_submit_order 余额支付' "$TMP/probe.txt" && echo OK || echo FAIL)
E_OK=$(rg -q 'bbs_posts_save' "$TMP/probe.txt" && echo OK || echo FAIL)
F_OK=$(rg -q '\[OK\] chick_data 五键' "$TMP/probe.txt" && rg -q '\[OK\] 前台首页 HTTP' "$TMP/probe.txt" && echo OK || echo FAIL)
PROBE_FAIL=$(rg -o 'FAIL=[0-9]+' "$TMP/probe.txt" | tail -1 | sed 's/FAIL=//' || echo 1)
SMOKE_FAIL=$(rg -q 'FAIL=0' "$TMP/smoke.txt" && echo 0 || echo 1)
{
  echo ""
  echo "| 手测节 | 自动覆盖 | 本轮 |"
  echo "|--------|----------|------|"
  echo "| A 开测/缺口 | gap-scan --deep | $A_OK |"
  echo "| B 授权 | smoke+probe admin_curl/delete | $B_OK |"
  echo "| C 更新 | probe online/download/update_file | $C_OK |"
  echo "| D 商城支付 | cart→confirm→balance→initiate→收货评价 | $D_OK |"
  echo "| E 论坛 | audit/topping/move/draft/save | $E_OK |"
  echo "| F 门禁交叉 | chick_data + 前台 HTTP | $F_OK |"
  echo ""
  SMOKE_OK=$(rg -o '合计 OK=[0-9]+' "$TMP/smoke.txt" | tail -1 || true)
  PROBE_OK=$(rg -o '合计 OK=[0-9]+ FAIL=[0-9]+' "$TMP/probe.txt" | tail -1 || true)
  echo "冒烟: ${SMOKE_OK:-?} | 探测: ${PROBE_OK:-?} | 宿主机附加 OK=${pass_n} FAIL=${fail_n}"
  echo "缺陷台账: docker/子比联调缺陷台账.md"
  echo "手测清单: docker/子比手测全量链路清单.md"
  echo "矩阵文档: docker/子比联调全量检查矩阵.md"
} | tee -a "$REPORT_LATEST"

for x in "$A_OK" "$B_OK" "$C_OK" "$D_OK" "$E_OK" "$F_OK"; do
  [[ "$x" == "OK" ]] || fail_n=$((fail_n+1))
done
[[ "${PROBE_FAIL:-1}" == "0" ]] || fail_n=$((fail_n+1))
[[ "${SMOKE_FAIL:-1}" == "0" ]] || fail_n=$((fail_n+1))

cp "$REPORT_LATEST" "$REPORT_STAMPED"
echo ""
echo "全部检查完成。报告: $REPORT_LATEST"
echo "归档: $REPORT_STAMPED"
echo "总 FAIL 计数(含矩阵): $fail_n"

if [[ "$fail_n" -gt 0 ]]; then
  exit 1
fi
exit 0
