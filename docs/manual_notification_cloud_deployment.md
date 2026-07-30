# 通知中心测试群云端安装与验收

## 交付边界

该资产只用于完整宿析OS应用 release。真实调度始终固定为：

- 模式：`test`
- 命令：`manual-notification:schedule --dispatch --mode=test`
- 酒店与机器人：由安装时显式传入
- 机器人数据库作用域：`notification_scope=operating_target_test`
- 正式群发送：禁止

`suxios-cloud-browser-*` 浏览器专用 release 不包含完整 PHP 应用、命令和数据库迁移，安装器会拒绝。

## 建立并回读测试群作用域

仅在已确认 `<HOTEL_ID>`、`<ROBOT_ID>` 和机器人名称确实对应目标测试群，且已取得生产库写入授权后执行。不要查询或输出 `webhook`：

```sql
START TRANSACTION;
SELECT id, store_id, name, status, notification_scope
FROM competitor_wechat_robot
WHERE id = <ROBOT_ID> AND store_id = <HOTEL_ID>
FOR UPDATE;

UPDATE competitor_wechat_robot
SET notification_scope = 'operating_target_test'
WHERE id = <ROBOT_ID>
  AND store_id = <HOTEL_ID>
  AND status = 1
  AND (notification_scope IS NULL OR notification_scope = '')
LIMIT 1;

SELECT id, store_id, name, status, notification_scope
FROM competitor_wechat_robot
WHERE id = <ROBOT_ID> AND store_id = <HOTEL_ID>;
COMMIT;
```

两次 `SELECT` 都必须只返回同一条目标机器人；最终 `notification_scope` 必须为 `operating_target_test`。若原作用域非空且不同，`UPDATE` 必须保持 0 行并停止部署，不得覆盖其他用途绑定。

## 默认只读检查

完整应用 release、迁移和机器人测试作用域准备好后执行：

```bash
sudo bash deploy/systemd/install_manual_notification_test_dispatch.sh \
  --release-root /var/www/suxios/current \
  --hotel-id <HOTEL_ID> \
  --robot-id <ROBOT_ID>
```

检查只执行文件、命令注册和数据库 `SELECT` 门禁，不创建调度运行记录，不读取 Webhook，不发送消息。预期输出包含：

```text
CHECK_OK ... install_requested=0 enable_requested=0 database_write=0 webhook_read=0 message_sent=0
```

## 安装但保持禁用

```bash
sudo bash deploy/systemd/install_manual_notification_test_dispatch.sh \
  --release-root /var/www/suxios/current \
  --hotel-id <HOTEL_ID> \
  --robot-id <ROBOT_ID> \
  --install
```

预期输出：`INSTALLED_DISABLED ... enabled=0`。继续核对：

安装器会在写入环境文件或 systemd unit 前先确认没有正在执行的测试发送，
并先禁用测试 timer；若发送服务仍在运行，或 timer 不能确认处于
`disabled/not-found`，则直接拒绝安装，避免安装窗口或重启后触发外发。

```bash
systemctl is-enabled suxios-manual-notification-test-dispatch.timer
systemctl is-active suxios-manual-notification-test-dispatch.timer
systemctl cat suxios-manual-notification-test-dispatch.service
```

前两项应分别为 `disabled`、`inactive`。服务必须从
`/etc/suxios/manual-notification-test-dispatch.env` 读取非密钥酒店/机器人 ID，
并在每次 dispatch 前执行只读测试绑定门禁。

## 授权后的单一真实启用动作

只有在以下事实全部验证后才允许启用：

1. 数据库迁移已完成；
2. 机器人确认为测试群，并标记 `operating_target_test`；
3. 当日经营目标报告通过来源、日期、身份、质量和明细对账门禁；
4. 已保存至少一条匹配该酒店和机器人的启用计划；
5. 操作人明确授权测试群真实投递。

```bash
sudo bash deploy/systemd/install_manual_notification_test_dispatch.sh \
  --release-root /var/www/suxios/current \
  --hotel-id <HOTEL_ID> \
  --robot-id <ROBOT_ID> \
  --install \
  --enable-test-dispatch
```

回滚：

```bash
sudo systemctl disable --now suxios-manual-notification-test-dispatch.timer
```

## 订单来了经营目标：采集后再发送

订单来了作为经营目标数据源时，不启用上面的“仅发送”timer，改用组合流水线：

```text
到点检查
  -> 云端已登录 Profile 只读采集今日住宿数据
  -> 门店身份、日期、六项汇总、房费明细合计、数据库回读
  -> 同步到住宿房费口径经营目标（保留人工目标金额）
  -> 报告门禁
  -> 仅测试群机器人发送
  -> 调度、采集、阻断、发送与重试记录
```

先只读检查，不安装、不发送：

```bash
sudo bash deploy/systemd/install_dingdandao_notification_pipeline.sh \
  --release-root /var/www/suxios/current \
  --hotel-id <HOTEL_ID> \
  --robot-id <ROBOT_ID> \
  --owner-user-id <OWNER_USER_ID> \
  --profile-id <DINGDANDAO_PROFILE_ID>
```

安装但保持禁用：

```bash
sudo bash deploy/systemd/install_dingdandao_notification_pipeline.sh \
  --release-root /var/www/suxios/current \
  --hotel-id <HOTEL_ID> \
  --robot-id <ROBOT_ID> \
  --owner-user-id <OWNER_USER_ID> \
  --profile-id <DINGDANDAO_PROFILE_ID> \
  --install
```

只有云端 Profile 已完成真实登录、测试群绑定正确、启用计划已保存并取得真实发送授权后，
才可执行唯一启用动作：

```bash
sudo bash deploy/systemd/install_dingdandao_notification_pipeline.sh \
  --release-root /var/www/suxios/current \
  --hotel-id <HOTEL_ID> \
  --robot-id <ROBOT_ID> \
  --owner-user-id <OWNER_USER_ID> \
  --profile-id <DINGDANDAO_PROFILE_ID> \
  --install \
  --enable-test-dispatch
```

组合流水线与 `suxios-manual-notification-test-dispatch.timer` 不得同时启用，
避免同一分钟重复发送。流水线在数据缺失、登录过期、门店身份不匹配、明细对账失败、
经营目标口径不一致或报告门禁未通过时，必须保存阻断状态并停止在企业微信调用之前。

回滚：

```bash
sudo systemctl disable --now suxios-dingdandao-notification-pipeline.timer
```
