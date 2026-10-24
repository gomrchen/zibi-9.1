# 开源说明：主题授权已移除

本发行版已删除商业主题授权体系：

- 无 `ZibAut` / `ZibCodeAut` / 授权 AJAX / 授权后台页
- 无 `inc/code/{code,aut,tool,action,new_aut}.php`
- 无 `aut_time_int` / `zib_shop_get_aut_time_int` / `CFS_Module::aut`
- 无远程授权 API、无未授权 `wp_die`
- 无 `.encrypted-bak*` 密文备份与联调免授权绕过插件
- 无 `zibll_code` / `zibll_aut_data` 选项依赖

更新：后台「文档&更新」仅提示手动更新，不拉官方授权包。
校验：`docker/scripts/zibll-plaintext-gap-scan.sh --deep`
