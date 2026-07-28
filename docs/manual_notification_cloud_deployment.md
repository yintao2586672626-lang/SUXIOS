# 通知中心测试播报云端安装与验收

## 交付边界

该资产仅用于完整宿析OS应用 release，并且只允许：

- 酒店：`80`
- 企业微信机器人：`1`
- 模式：`test`
- 命令：`manual-notification:schedule --dispatch --mode=test`

`suxios-cloud-browser-*` 浏览器专用 release 不包含完整 PHP 应用、命令和数据库
迁移，安装器会直接拒绝。测试机器人不能作为正式群身份使用。

## 默认安全检查

完整应用 release 切换完成、命令已注册、dispatch migration 已执行、酒店80机器人1
身份已配置后，先运行只读检查：

```bash
sudo bash deploy/systemd/install_manual_notification_test_dispatch.sh \
  --release-root /var/www/suxios/current
```

检查内容：

1. release 不是 `suxios-cloud-browser-*`；
2. `think`、Composer、应用入口、调度命令、服务和 migration 文件完整；
3. `manual-notification:schedule` 已注册；
4. `manual_notifications`、dispatches、机器人表可读；
5. 唯一允许的测试身份是 `hotel_id=80 + robot_id=1`；
6. 只执行 `--preview`，不读取 Webhook、不发送消息。

输出必须为 `CHECK_OK ... installed=0 enabled=0`。

## 安装但保持禁用

```bash
sudo bash deploy/systemd/install_manual_notification_test_dispatch.sh \
  --release-root /var/www/suxios/current \
  --install
```

预期输出：`INSTALLED_DISABLED ... enabled=0`。检查：

```bash
systemctl is-enabled suxios-manual-notification-test-dispatch.timer
systemctl is-active suxios-manual-notification-test-dispatch.timer
systemctl cat suxios-manual-notification-test-dispatch.service
```

前两项应分别为 `disabled`、`inactive`；`ExecStart` 必须同时包含：

```text
--dispatch --mode=test --hotel-id=80 --robot-id=1
```

## 授权后的单一真实部署动作

只有在完整应用 release、migration、命令注册和机器人身份全部验收后，由有权操作员
执行这一条命令：

```bash
sudo bash deploy/systemd/install_manual_notification_test_dispatch.sh \
  --release-root /var/www/suxios/current \
  --install \
  --enable-test-dispatch
```

该动作会安装并启用测试 timer。它不会配置或升级正式群，不会触发 OTA 采集，也不会
绕过通知、窗口、模式三元幂等。回滚时执行：

```bash
sudo systemctl disable --now suxios-manual-notification-test-dispatch.timer
```

## 正式自动推送

正式定时消息使用独立的 `formal` 调度器，只读取满足以下条件的已保存计划：

- `send_method=wecom_formal`；
- `enabled=1` 且 `schedule_status=schedule_enabled`；
- 触发类型为每日固定时间或整点循环；
- 酒店、租户、机器人和共享范围校验通过；
- 计划已完成真实测试，Webhook 换绑后必须重新测试；
- 消息类型对应的数据门禁通过，缺失事实不会用 `0`、旧日数据或 OTA 数据补齐。

每次调度先生成运行记录，再以“通知计划 + 调度窗口 + 模式”抢占唯一投递账本；
只有抢占成功的记录才调用企业微信。失败记录仅允许显式重试，回执不明确的记录标记为
`outcome_unknown`，不会自动重复发送。

### 1. 只读预检

```bash
sudo bash deploy/systemd/install_manual_notification_formal_dispatch.sh \
  --release-root /var/www/suxios/current
```

该命令只校验完整应用、数据库结构、正式计划与机器人归属，并执行 `formal` 预览；
不读取 Webhook，也不发送消息。预期输出包含：

```text
CHECK_OK ... mode=formal installed=0 enabled=0
```

### 2. 安装但保持禁用

```bash
sudo bash deploy/systemd/install_manual_notification_formal_dispatch.sh \
  --release-root /var/www/suxios/current \
  --install
```

预期输出为 `INSTALLED_DISABLED ... mode=formal enabled=0`。此时 timer 必须是
`disabled`、`inactive`。

### 3. 显式启用正式推送

先在“运营自动化中心 → 企业微信推送”中完成正式群绑定、真实测试并启用至少一个计划，
再由有权操作员执行：

```bash
sudo bash deploy/systemd/install_manual_notification_formal_dispatch.sh \
  --release-root /var/www/suxios/current \
  --install \
  --enable-formal-dispatch
```

正式 timer 每分钟检查一次到期窗口，但同一窗口只会生成一条逻辑投递记录。验收：

```bash
systemctl is-enabled suxios-manual-notification-formal-dispatch.timer
systemctl is-active suxios-manual-notification-formal-dispatch.timer
systemctl list-timers suxios-manual-notification-formal-dispatch.timer
```

安装器会先把 `--release-root` 解析为真实版本目录，并将同一个绝对路径写入正式
service 的 `ConditionPathExists` 与 `WorkingDirectory`。因此预检、启用前复检和
systemd 实际执行的是同一版本，不会因 `/var/www/suxios/current` 随后切换而运行
另一版代码。发布新版本后，应使用新的 `--release-root` 重新执行安装；安装默认仍
保持禁用，只有显式传入 `--enable-formal-dispatch` 才会启用 timer。

回滚不会删除计划和历史回执：

```bash
sudo systemctl disable --now suxios-manual-notification-formal-dispatch.timer
```

当前 Windows 本地开发环境只验证代码、迁移、预检契约和调度测试；以上正式 timer
需要随完整 Linux 应用 release 安装后才会实际运行。
