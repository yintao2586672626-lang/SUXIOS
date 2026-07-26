# 宿析OS 云端浏览器网关

## 当前交付状态

这是可部署但尚未部署的最小受保护网关资产。它接入现有
`CloudBrowserProfileService` 状态机，但本次没有 SSH、没有启动云端浏览器、
没有真实登录，也没有导出任何 Profile、Cookie 或会话资料。

## 安全契约

- Profile 持久层只允许 `*.tar.gz.enc`，使用 AES-256-GCM 和每个
  `profile_id` 独立 AAD；主密钥由 systemd credential 提供。
- 解密后的 Profile 只存在 `/run/suxios-cloud-browser/profiles`，浏览器退出后
  先重新加密，再将状态机推进到 `ready_to_collect`。
- 登录入口使用既有 15 分钟一次性票据。`open` 仅做不消费校验；
  `complete` 在密文写入成功后原子消费票据并推进状态。
- 网关 `8787`、noVNC `6080`、VNC `5900`、按需 CDP `9223` 全部只允许
  `127.0.0.1`。网关启动时不会自动启动 Chromium；最小版本一次只允许一个
  登录窗口，繁忙时显式返回 `gateway_login_capacity_busy`，不争用 CDP/Profile。
- 采集回执只接受结构化身份、日期、保存数、回读数和字段事实摘要，不接受
  Cookie、Token、请求头、原始 HTML/HAR、Profile 路径等会话材料。
- 每条回执包含 `prev_hash` 和 `receipt_hash`；链校验失败时网关拒绝启动。

## 网关契约

### 1. 打开短期登录窗口

`POST http://127.0.0.1:8787/v1/login/open`

```json
{
  "profile_id": "cbp_opaque-id",
  "session_id": "cbls_opaque-id",
  "ticket": "one-time-login-ticket",
  "platform": "ctrip"
}
```

成功后只返回本机 noVNC 地址、到期时间和公开 ID。网关此时才按需解密
Profile 并启动一个 Chromium；票据错误、过期或范围不一致时不会启动。

### 2. 完成登录

用户在受保护窗口完成真实登录后，由受信任的本机操作调用：

`POST http://127.0.0.1:8787/v1/login/complete`

请求体与 `open` 相同，并且必须带受信任本机进程持有的
`Authorization: Bearer <control-token>`。普通登录窗口不能自行把 Profile
标记为已验证。网关依次停止浏览器、加密 Profile、消费一次性票据、推进状态机，
并写入 `login_profile_ready` 回执。任一步失败均不返回可采集状态。

### 3. 写入采集回执

`POST http://127.0.0.1:8787/v1/collection/receipt`

请求必须带 `Authorization: Bearer <control-token>`，正文只允许：

```json
{
  "task_id": "cct_opaque-id",
  "profile_id": "cbp_opaque-id",
  "platform": "ctrip",
  "tenant_id": 8,
  "hotel_id": 80,
  "target_date": "2026-07-25",
  "source_method": "cloud_browser_profile",
  "status": "saved",
  "identity_verified": true,
  "saved_count": 12,
  "readback_count": 12,
  "field_facts_sha256": "64-lowercase-hex",
  "failure_stage": null
}
```

`saved` 必须同时满足身份已验证且保存数等于回读数，否则回执门禁拒绝。
`partial`、`failed`、`blocked` 必须保持真实状态，不会被转换为成功。

## 获得云端授权后的部署步骤

只有目标服务器、SSH 账号、部署窗口和回滚责任人均已明确授权后才能执行：

```bash
cd /var/www/suxios/current
sudo bash deploy/remote-browser/install_secure_remote_browser.sh /var/www/suxios/current
sudo bash deploy/remote-browser/verify_secure_remote_browser.sh
```

安装器会启动显示层、VNC、noVNC 和网关，但不会启动 Chromium。不要为
`5900`、`6080`、`8787` 或 `9223` 添加公网防火墙规则。

操作人员从自己的电脑建立 SSH 隧道：

```bash
ssh -N \
  -L 8787:127.0.0.1:8787 \
  -L 6080:127.0.0.1:6080 \
  <ssh-user>@<server-address>
```

VNC 密码仅允许在已授权 SSH 终端读取，不得粘贴到聊天、工单或源码：

```bash
sudo systemd-creds cat vnc-password \
  /run/credentials/suxios-cloud-browser-vnc.service/vnc-password
```

## 验收与停止条件

部署后必须先运行 `verify_secure_remote_browser.sh`。只有在用户真实登录、
密文 Profile 写入、状态机为 `ready_to_collect`、采集保存与数据库回读一致、
回执链校验通过后，才能称为云端端到端闭环。

本地代码测试、systemd 静态资产或 Profile 状态存在，均不能替代真实云端验收。
