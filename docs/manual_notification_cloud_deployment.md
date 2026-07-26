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
