# 系统配置双表边界

- `system_config`：系统名称、登录支持、菜单、显示、安全开关等公开或通用应用配置。
- `system_configs`：携程、美团和 OTA 采集元数据；不得保存明文 Cookie、Token、Profile 或请求正文。
- `ctrip_config_list`、`meituan_config_list`、公开酒店绑定及旧 `data_config_`/`online_data_cookies_` 配置元数据只写 `system_configs`；采集健康、告警等通用运行状态仍写 `system_config`。
- 同一个 `config_key` 不得同时存在于两张表；历史空副本由迁移清理，非空冲突必须人工确认，禁止静默覆盖。

验证命令：

```powershell
C:\xampp\php\php.exe scripts\verify_system_config_storage_boundary.php
```
