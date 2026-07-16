# 美团数据现有主线执行手册（精简版）

更新日期：2026-07-15

## 1. 本轮只做什么

只跑清楚一条现有主线：

> 授权酒店 → 美团门店绑定 → 浏览器 Profile 登录 → 目标日流量/订单采集 → 写入 `online_daily_data` → 数据库回读 → 页面回显

本轮完成点是：用户能在宿析OS页面按酒店、日期和美团来源查到与数据库回读一致的流量和订单数据。

## 2. 暂时不做什么

- 不做携程和美团双平台闭环。
- 不做广告、点评、竞品、排名等补充模块。
- 不做 AI 建议和运营执行。
- 不做投资、转让、扩张和全酒店经营结论。
- 不重构公共 OTA 架构，不新增独立订单表或流量表。
- 不用历史数据、模拟数据、默认 0 或携程数据补美团缺口。

美团数据只代表美团 OTA 渠道，不等于全酒店 OCC、RevPAR、GOP 或 ROI。

## 3. 2026-07-15 基线快照

以下数值只代表 2026-07-15 当次本地数据库检查，不应作为后续日期或生产环境的当前值：

| 项目 | 当前值 | 判断 |
|---|---:|---|
| 美团目标日记录 | 141 行 | 当次快照只能证明已有部分美团数据 |
| 美团目标日 traffic | 0 行 | 当次快照的主阻塞 |
| field fact | `not_loaded` | 流量字段来源链未闭合 |
| P0 gate | incomplete | 不能宣称美团数据链已完成 |

因此第一目标不是增加新页面，而是让目标酒店、目标日期真实产生可回读的 `traffic` 和 `order` 行。

## 4. 固定执行路径

### 主路径：浏览器 Profile 自动采集

- 页面/API入口：`POST /api/online-data/capture-meituan-browser`
- 执行脚本：`scripts/meituan_browser_capture.mjs`
- Profile目录：账号使用者实际执行设备上的本地 `storage/meituan_profile_{store_id}`；不得上传或集中同步到宿析OS服务器
- 本轮模块：`traffic,orders`
- 入库表：`online_daily_data`

### 备用路径：人工上下文补录

仅当主路径已经明确失败，并记录失败阶段后，才使用用户已授权提供的 Cookie、Payload、导出文件或必要接口参数，通过现有手动入口补数。

备用路径不能：

- 在后台代替用户登录美团。
- 绕过短信、滑块、验证码或门店权限。
- 与 Profile 路径并行重复采集同一酒店、同一日期。
- 把人工数据标成浏览器 Profile 实采。

任一路径完成“获取 → 保存 → 回读 → 页面回显”后立即停止，不再切换另一条路径。

## 5. 执行参数

开始前必须明确以下三个参数：

先在 PowerShell 中进入当前 `HOTEL` 项目根目录，再执行：

```powershell
$TargetDate = '2026-07-15'
$SystemHotelId = '<已授权的 system_hotel_id>'
$StoreId = '<与该酒店绑定的美团 store_id/poi_id>'
$Php = 'C:\xampp\php\php.exe'
```

没有明确 `$SystemHotelId` 和 `$StoreId` 时，只能做只读检查，不能执行采集和写入。

## 6. 五步执行流程

### M-01：确认酒店和美团门店绑定

#### 页面操作

1. 打开宿析OS“线上数据”。
2. 进入“平台自动获取”。
3. 选择本次授权酒店。
4. 查看美团账号/Profile状态。
5. 确认系统酒店、Store ID、POI ID属于同一家酒店。

#### 通过标准

- 系统酒店ID明确。
- 美团 Store ID/POI ID明确。
- 美团门店名称与目标酒店一致。
- 当前用户有 `can_fetch_online_data` 权限。
- 页面没有 `binding_missing`、`ota_profile_binding_blocked` 或跨酒店提示。

#### 失败处理

| 状态 | 处理 |
|---|---|
| 未选择酒店 | 选择授权酒店后重试 |
| Store ID/POI ID缺失 | 补齐该酒店真实美团门店标识 |
| 门店标识不一致 | 停止，不保存；先修正绑定 |
| 无采集权限 | 由管理员配置权限，不绕过接口 |

绑定未通过时不得进入 M-02。

---

### M-02：验证美团 Profile 登录态

#### 页面操作

1. 在“平台自动获取”选择目标酒店。
2. 点击“本机授权美团”。
3. 在打开的美团窗口中由账号使用者完成人工登录。
4. 返回宿析OS点击“刷新状态”。

#### 通过标准

- Profile状态为 `logged_in`。
- Profile绑定的 Store ID与 M-01 一致。
- 登录态保存在账号使用者设备上的“设备 + 平台 + 平台账号”本地 Profile 中；同账号的多家授权门店复用该 Profile，并分别校验 Store ID/POI 映射。
- 页面不显示 `login_expired` 或 `login_required`。

#### 失败处理

| 状态 | 处理 |
|---|---|
| `login_expired` | 重新打开美团登录窗口，由用户登录 |
| Profile不存在 | 由账号使用者在本机创建账号级 Profile 后重新登录 |
| Profile已映射其他门店 | 校验是否为同一平台账号；同账号则复用 Profile 并新增当前门店授权映射，不同账号或其他用户设备则停止并修正绑定 |
| 验证码/短信 | 等用户手工完成，不自动绕过 |

登录未验证时不得把旧数据当成本次成功。

---

### M-03：采集目标日流量和订单

#### 主路径

调用现有浏览器 Profile 采集入口，固定参数：

```text
system_hotel_id = $SystemHotelId
store_id/poi_id = $StoreId
data_date = $TargetDate
sections = traffic,orders
```

开发排障时可以只生成本地采集结果，不提交入库：

```powershell
npm.cmd run meituan:capture -- `
  --store-id=$StoreId `
  --system-hotel-id=$SystemHotelId `
  --data-date=$TargetDate `
  --sections=traffic,orders
```

本地结果只用于检查采集阶段；正式保存应通过当前已鉴权的宿析OS页面/API完成，不把登录Token写进文档、命令历史或Git。

#### 必查返回字段

```text
auth_status.ok = true
capture_gate.status = pass
capture_gate.section_statuses.traffic = captured 或 empty_confirmed
capture_gate.section_statuses.orders = captured 或 empty_confirmed
system_hotel_id = $SystemHotelId
store_id/poi_id = $StoreId
default_data_date = $TargetDate
source = meituan_browser_profile
```

#### 空数据规则

- `empty_confirmed`：平台明确返回目标范围为0，可以标记“采集完成、业务为空”。
- 没有响应证据、接口未命中或解析不到行：属于采集失败，不能标记为空数据成功。
- 目标日期来自默认上下文而非平台请求/返回证据：属于日期未验证，不能入库。

#### 当前重点检查

当前美团 `traffic=0`，所以优先检查：

1. 流量页是否实际打开。
2. 是否监听到 `businessData`、`traffic` 或 `peerTrends` 业务响应。
3. 响应中的日期是否为 `$TargetDate`。
4. 标准化后是否产生 `data_type=traffic` 行。
5. 是否映射出以下字段及其来源路径：
   - `list_exposure`
   - `detail_exposure`
   - `flow_rate`
   - `order_filling_num`
   - `order_submit_num`

#### 失败处理

| 失败码/现象 | 最小处理 |
|---|---|
| `login_expired` | 回到 M-02重新登录 |
| `target_date_missing` | 明确传入目标日期 |
| `target_date_mismatch` | 修正页面筛选或请求日期，不改成当前日期兜底 |
| `target_date_unverified` | 补请求或返回中的日期证据 |
| `section_traffic_not_captured` | 只排查美团流量页响应识别和字段映射 |
| `section_orders_not_captured` | 只排查订单页响应识别和字段映射 |
| HTTP 200但平台业务报错 | 按平台失败处理，不当作空结果 |
| `meituan_capture_no_business_rows` | 不入库；确认响应是否命中现有解析器 |

一次只修复当前失败阶段，不同时修改通用OTA架构和其他平台。

---

### M-04：保存并执行数据库回读

#### 保存入口

- 主路径由 `POST /api/online-data/capture-meituan-browser` 在采集成功后调用现有保存链路。
- 已有合法采集Payload时使用 `POST /api/online-data/save-meituan-captured-data`。
- 禁止直接拼SQL写入真实数据。

#### 保存响应检查

必须确认：

```text
ingestion_method = browser_profile
row_count >= 0
saved_count >= 0
counts.traffic 与实际流量行一致
counts.order 与实际订单行一致
```

`saved_count=0`只有在平台提供权威空结果时才能算采集完成；它不能算“美团业务数据已就绪”。

#### 数据库回读

不要读取或打印完整 `raw_data`。只查询验收字段：

```powershell
& 'C:\xampp\mysql\bin\mysql.exe' -u root hotelx -e "
SELECT
  id,
  system_hotel_id,
  hotel_id,
  data_date,
  source,
  data_type,
  list_exposure,
  detail_exposure,
  flow_rate,
  order_filling_num,
  order_submit_num,
  amount,
  quantity,
  book_order_num,
  validation_status,
  data_source_id,
  source_trace_id,
  create_time,
  update_time
FROM online_daily_data
WHERE system_hotel_id = $SystemHotelId
  AND source = 'meituan'
  AND data_date = '$TargetDate'
  AND data_type IN ('traffic', 'order')
ORDER BY data_type, id;
"
```

#### 通过标准

- 每行的 `system_hotel_id`、`hotel_id`、`data_date`、`source`正确。
- 至少有目标日 `traffic` 或平台权威空证明；不能是历史行。
- 有订单时，`amount`、`quantity`、`book_order_num`与平台同条件结果一致。
- 有流量时，五个流量字段与平台同条件结果一致。
- `data_source_id`和 `source_trace_id`可追溯。
- 重复执行同一酒店、同一日期后不产生重复业务事实。

如果接口返回成功但数据库查不到对应行，链路仍为失败，停止进入页面验收。

---

### M-05：页面回显和最终门禁

#### 页面操作

1. 打开“线上数据” → “数据记录”。
2. 开始日期和结束日期都选择 `$TargetDate`。
3. 数据来源选择“美团”。
4. 酒店选择 `$SystemHotelId`对应酒店。
5. 先选择“流量”查询，再选择“订单”查询。
6. 刷新页面后再次查询。

#### 页面通过标准

- 页面行数与数据库回读一致。
- 酒店、日期、平台和数据类型正确。
- 页面明确显示美团 OTA 渠道口径。
- 缺失值显示为缺失，不显示成伪造的0。
- 刷新后数据仍存在。
- 页面不展示 Cookie、Token、完整原始响应或敏感订单信息。

#### 最终验证命令

```powershell
npm.cmd run verify:p0-ota-field-loop -- `
  --date=$TargetDate `
  --platform=meituan `
  --system-hotel-id=$SystemHotelId
```

#### 完成标准

美团主线只有同时满足以下条件才能标记完成：

- [ ] 目标酒店和美团门店绑定一致。
- [ ] Profile登录状态已验证。
- [ ] 目标日期来自平台请求或返回证据。
- [ ] 流量和订单采集状态明确。
- [ ] 保存结果与数据库回读一致。
- [ ] 页面筛选结果与数据库回读一致。
- [ ] 五个流量字段有来源路径和field facts。
- [ ] P0 verifier中美团状态为ready。
- [ ] 没有用历史值、默认0或其他平台数据补缺口。

## 7. 只在失败时修改代码

| 失败阶段 | 允许修改范围 | 不允许扩展 |
|---|---|---|
| Profile登录 | `PlatformProfileLogin`、美团Profile状态读取 | 不改携程登录 |
| 流量未命中 | `meituan_browser_capture.mjs`和美团normalize/gate | 不改全平台采集框架 |
| 订单未命中 | 美团订单交互和响应映射 | 不做点评、广告 |
| 保存失败 | `OnlineDataRequestConcern`、美团持久化服务 | 不新建订单/流量表 |
| 回读不一致 | 美团保存粒度、幂等和readback | 不改历史无关数据 |
| 页面不显示 | 美团筛选、加载和刷新逻辑 | 不重做线上数据页面 |

每次修复遵循：复现 → 定位 → 最小修改 → 重跑当前失败步骤 → 数据库回读 → 页面确认。

## 8. 最小代码验证集

只有实际修改了相关代码时运行：

```powershell
& $Php vendor\bin\phpunit --colors=never `
  tests\BrowserProfileCaptureRequestServiceTest.php `
  tests\MeituanOnlineDataPersistenceServiceTest.php `
  tests\MeituanCapturedDataIntegrityTest.php `
  tests\MeituanManualIdentityServiceTest.php `
  tests\OnlineDataTenantScopeTest.php

node --test `
  tests\automation\meituan_browser_capture_gate.test.mjs `
  tests\automation\meituan_browser_capture_normalize.test.mjs `
  tests\automation\meituan_ingestion_contract.test.mjs

npm.cmd run verify:frontend-template
npm.cmd run verify:public-entry
```

如果没有修改前端，不额外做全站UI重构或全量视觉验收。

## 9. 本轮停止点

当 M-01 至 M-05 全部通过后立即停止并报告：

```markdown
- 酒店：
- 美团Store/POI：仅脱敏标识
- 目标日期：
- 采集方式：browser_profile / manual_import
- traffic抓取数：
- order抓取数：
- 保存数：
- 数据库回读数：
- 页面回显数：
- field fact状态：
- P0状态：
- 失败或缺失项：
```

不继续进入AI、运营、投资或正式发布工作。
