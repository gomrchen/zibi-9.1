# 子比 `inc/code` 联调契约还原说明

对照来源：

| 来源 | 用途 |
|------|------|
| `wordpress/wordpress` 兄弟项目 | `inc/class/db-class-legacy.php`（ZibDB 全量链式 API） |
| `wordpress-91` 主题调用方 | `options-module.php`、`admin-options.php`、`csf main.js` |
| Z5 加密文件方法签名 | `inc/code/.encrypted-bak/*.php`（strings 提取） |

加密原件备份：`themes/zibll/inc/code/.encrypted-bak/`

---

## 1. 加载顺序

```
inc/inc.php → inc/code/require.php
  → code.php (ZibAut)
  → aut.php (ZibCodeAut)
  → file.php → inc/class/db-class-legacy.php (ZibDB)
  → tool.php (授权 AJAX)
  → update.php (更新 AJAX + ZibCodeUpdeta)
  → zib_admin_tool_action() 注册 init/cron/后台钩子
```

独立入口（OAuth 等）仍可能只 `require file.php`，此时仅保证 `ZibDB` 可用。

---

## 2. ZibAut（`code.php`）

| 方法 | 参数 | 返回（契约） | 行为摘要 |
|------|------|--------------|----------|
| `get_aut_url` | — | `string` | 无协议域名，授权页展示 |
| `get_home_url` | — | `string` | `home_url('/')` |
| `is_local` | — | `bool` | 本地/内网免授权 UI |
| `aut_required` | — | `bool` | 非本地且未授权时为 true |
| `replace_url` | `$url` | `string` | 去 `http(s)://` |
| `top_host` | `$url` | `string` | 主域名提取 |
| `delete` | — | `bool` | 清 `zibll_code` 等 option |
| `is_aut` | — | `bool` | 本地恒 true；否则看 code + 到期 |
| `update_ok` | `$result` | `bool` | 同 `is_ok` |
| `save_zat_end_time` | `$result_data` | `bool` | 写 `zibll_zat_end_time` |
| `get_aut_time` / `get_zat_end_time` | — | `string` | 空=永久；否则日期 |
| `timing_aut` | — | `void` | cron：远程刷新授权 |
| `curl_aut` | `$aut_code=null` | `bool` | POST 授权 API |
| `http_request` | `$url,$data` | `array` | `wp_remote_post` JSON |
| `is_ok` / `is_singok` | `$data` | `bool` | `status==1` 且无 error |
| `save_aut_code` / `get_aut_code` | | | option `zibll_code` |
| `get_aut_code_sign` | `$aut_code` | `string` | HMAC 域+码 |
| `curl_aut_data` | `$aut_code=''` | `array` | code,domain,home,version,sign |
| `get_theme_version` | — | `string` | `THEME_VERSION` |
| `get_download_url` | — | `string` | 更新包地址 API |
| `curl_update` | `$skip_flag=false` | `array\|false` | 检测更新写 transient |
| `update_save_data` | `$result_obj,$skip_flag` | `array\|false` | 缓存 `zibll_update_check` |
| `timing_update` | — | `void` | cron 检测更新 |
| `is_update` | `$result='null'` | `array\|false` | 含 version、update_description、update_content |
| `skip_update` | — | `bool` | 是否忽略版本 |
| `noaut_notice` | — | `string` | 前台提示（可空） |
| `admin_js` / `csf_save` / `footer_html` | — | mixed | 后台钩子（联调空实现） |
| `noaut_update` | — | `void` | `admin_notices` 未授权提示 |

**Option / Transient**

- `zibll_code` — 授权码  
- `zibll_zat_end_time` — 到期  
- `zibll_aut_sign` — 服务端 sign  
- `zibll_skip_update_version` — 忽略的版本号  
- transient `zibll_update_check` — 更新元数据  

**wp-config 联调常量（可选）**

- `ZIBLL_AUT_ALWAYS` — 强制已授权  
- `ZIBLL_AUT_OFFLINE_OK` — 提交授权码时不校验远程  
- `ZIBLL_FORCE_NOT_LOCAL` — 强制走正式授权 UI  
- `ZIBLL_AUT_API_BASE` — 自建 API 根 URL  

---

## 3. ZibCodeAut（`aut.php`）

| 方法 | 参数 | 说明 |
|------|------|------|
| `get_home_url` | — | 委托 `ZibAut` |
| `isok` | `$code` | 数组走 `is_ok`，字符串比对本地 code |
| `update` | `$data` | 委托 `update_save_data` |
| `is_token` | `$data` | 校验 sign/token |
| `aut` | — | 调 `timing_aut` |
| `zib_get_admin_require_intdata` | `$data` | 更新流程 rand 整数 |

---

## 4. ZibDB（`file.php` → `db-class-legacy.php`）

静态入口：`ZibDB::name($table)` / `ZibDB::table($table)` → `ZibDBQuery`。

主题内实际用到的链式方法（自 grep 归纳）：

`field`, `where`, `whereTime`, `order`, `limit`, `page`, `group`, `having`, `join`, `distinct`, `alias`, `metaTable`, `metaQuery`, `select`, `find`, `count`, `insert`, `insertGetId`, `update`, `delete`, `sum`…

结果对象：`ZibDBResult::toArray()`, `toArrayMap()`, `result()`, `find()`。

与明文 `inc/class/db-class.php`（`zib_db`）为并行实现；业务代码统一走 `ZibDB::`。

---

## 5. 后台 AJAX（`tool.php` + `update.php`）

| `action`（POST） | 处理函数 | 请求字段 | JSON 响应 |
|------------------|----------|----------|-----------|
| `admin_curl_aut` | `zib_admin_curl_aut` | `aut_code` | `{error,msg,reload?}` |
| `admin_delete_aut` | `zib_admin_delete_aut` | — | `{error,msg,reload?}` |
| `admin_skip_update` | `zib_admin_skip_update` | — | `{error,msg,reload?}` |
| `admin_detect_update` | `zib_admin_detect_update` | — | `{error,msg,reload?}` |
| `zibll_online_update` | `zib_ajax_zibll_online_update` | 分步 `data` | `{error,msg,action?,data?,progress?,status?}` 见 `csf main.js` |

前端收集规则：`ajax-name` / `name` → POST 字段（`inc/csf-framework/assets/js/main.js`）。

---

## 6. ZibCodeUpdeta（`update.php`）

| 方法 | 说明 |
|------|------|
| `zib_update_file_clear` | 清空 `主题/update/` 临时目录 |
| `init` | 更新前清理 |
| `check_url_data` | `$rand_int` + `$data` 防重放 |
| `download_update` / `update_file` / `online_update` | 官方写盘更新（联调默认返回 error） |
| `is_ok` | 委托 `ZibAut::is_ok` |

---

## 7. `zib_init_add_action`（原 Z5）

原逻辑：`ZibAut::is_aut()` 失败时对主题文件 `md5_file` 校验并 `wp_die`。  
联调版：仅 `aut_required()` 时前台 `503`；后台可进授权页。

---

## 8. 与兄弟项目差异

兄弟项目 `inc/code/*.php` 为极简 stub，**不含** Z5 全量方法名；本仓库按 **wordpress-91 加密签名 + 调用方** 还原完整类面。  
兄弟项目独有 `inc/class/db-class-legacy.php` 已复制到本仓库并作为 `file.php` 后端。

---

## 9. 验证

```bash
php -l docker/wordpress/wp-content/themes/zibll/inc/code/*.php
php -l docker/wordpress/wp-content/themes/zibll/inc/class/db-class-legacy.php
```

后台：主题设置 → 主题&授权 → 提交授权码 / 撤销；文档&更新 → 检测更新。
