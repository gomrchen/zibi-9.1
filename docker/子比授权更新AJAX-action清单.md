# 子比 · 授权 / 更新 / 商城校验 · AJAX `action` 清单

用于对照 Z5 VM：密文里 **几乎不出现** `wp_ajax_*` 明文字符串，下列名称来自 **主题前端/后台 HTML、CSF JS、已还原 PHP 注册**。

---

## 1. 已注册（明文联调）

| `action`（POST `action`） | 处理函数 | 注册文件 | 触发来源 |
|---------------------------|----------|----------|----------|
| `admin_curl_aut` | `zib_admin_curl_aut` | `inc/code/tool.php` | 主题设置 → 授权，`options-module.php` |
| `admin_delete_aut` | `zib_admin_delete_aut` | `inc/code/tool.php` | 同上「撤销授权」 |
| `admin_skip_update` | `zib_admin_skip_update` | `inc/code/update.php` | 文档&更新 → 忽略本次更新 |
| `admin_detect_update` | `zib_admin_detect_update` | `inc/code/update.php` | 文档&更新 → 检测更新 |
| `zibll_online_update` | `zib_ajax_zibll_online_update` | `inc/code/update.php` | CSF `main.js` 在线更新第一步 |
| `zibll_download_update` | `zib_ajax_zibll_download_update` | `inc/options/zib-update.php` | 上一步 JSON 返回 `action` 链式第二步 |
| `zibll_update_file` | `zib_ajax_zibll_update_file` | `inc/options/zib-update.php` | 更新链第三步（写盘） |
| `zib_ajax_aut_error` | `zib_ajax_aut_error` | `inc/options/zib-update.php` | 密文 `zib-update.php` 函数名（VM 内可能调 admin-ajax） |

**在线更新链（官方逻辑）**

```
zibll_online_update → (响应含 action) → zibll_download_update → zibll_update_file
```

联调明文：`download_update` / `update_file` / `online_update` 返回 `error: true` 与说明文案，避免误写盘。

---

## 2. 非 AJAX（过滤器 / 钩子，VM 可能回调）

| 钩子 / 函数 | 明文实现 | 说明 |
|-------------|----------|------|
| `setup_data_randstr` | `zibpay_admin_page_aut_randstr` | `aut.php` 注册；支付后台 Vue 数据 |
| `aut_time_int` | `ZibCodeAut::update`（2 参数） | `ZibPaySDK` 构造时注册 |
| `pay_modo_chick_data` | `ZibPaySDK::tool` | `shop/inc/public.php`；**主题内无 `apply_filters` 调用方**（可能在支付 SDK VM） |
| `zib_shop_get_url_chick_data()` | 返回 domain/sign 等 | 无 AJAX，供商城/支付校验数据 |
| `zib_shop_get_aut_time_int($data)` | `apply_filters('aut_time_int', 0, $data)` | 商城 setup 密文相关 |
| `admin_footer` → `zib_update_check_file_md5_zhdh` | 恒 `false` | 密文完整性桩 |
| `admin_notices` → `ZibAut::noaut_update` | `inc/options/admin-options.php` | 未授权后台提示 |

---

## 3. 原 VM 在类内注册、已由明文分散实现（非 inc/code）

| 原加密类 | 类内方法 | 实际 AJAX 位置 |
|----------|----------|----------------|
| `zib_bbs_admin` | `ajax_posts()` / `admin_manage()` | `inc/functions/bbs/action/ajax-posts.php` 等（如 `posts_audit`、`plate_move`…） |
| `zib_shop_setup` | `ajax()` / `tool()` | `inc/functions/shop/action/action.php`（如 `cart_add`、`shop_submit_order`…） |
| `ZibPaySDK` | `pay()` / `post()` | `zibpay/functions/zibpay-ajax.php` + `zibpay_initiate_*` 过滤器 |

论坛/商城 **不要** 再往 `class.admin.php` / `class.setup.php` 里堆 `wp_ajax`，除非抓包确认缺 action。

---

## 4. 抓包补 VM 时怎么用

1. 浏览器 Network → `admin-ajax.php` 或 REST，看 POST 字段 **`action`**。
2. 若 action 在本表 **§1** 已存在仍 400/0：查处理函数返回 JSON 是否含 `error` / `msg` / 下一步 `action`。
3. 若 action **不在表内**：把 **完整 action 名 + 请求 URL + 一段响应** 记下，对照应落在 §3 哪条业务线补注册。

---

## 5. 自检命令

```bash
docker/scripts/zibll-plaintext-gap-scan.sh --deep
```

相关文档：`docker/子比加密明文深度对照.md`、`docker/子比inc-code契约还原.md`
