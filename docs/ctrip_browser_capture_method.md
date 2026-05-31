# 携程 OTA 数据自动采集方法说明

## 1. 方案定位

本方案用于在酒店已有 OTA 商家后台账号和合法授权的前提下，自动化获取自身经营数据。

它不是简单抓取网页 HTML，而是模拟真实运营人员打开携程商家后台页面，由页面自行触发业务接口，系统监听页面返回的结构化数据，再完成清洗、去重和入库。

适用场景：

- 酒店每日经营日报
- 携程 OTA 渠道数据汇总
- 订单、流量、广告、评分数据采集
- 差评预警
- 流量转化分析
- 广告投放效果复盘
- 收益管理和 AI 运营建议

合规边界：

- 只采集客户自己账号内可见数据。
- 不采集第三方非授权数据。
- 不绕过平台登录、短信、滑块、人机验证或权限体系。
- 不高频请求影响平台服务。
- Cookie、token、Profile、账号密码、手机号明文不得写入普通文档、日志或 Git。
- 前端 i18n 翻译包和埋点脚本只用于识别页面文案、按钮和指标术语，不作为业务数据来源。

## 2. 为什么采用浏览器监听方案

很多团队会先尝试复制浏览器接口，在代码里拼参数请求数据。这种方式短期可用，但长期维护成本高。

| 方式 | 主要问题 |
| --- | --- |
| 直接请求接口 | 容易受 Cookie、签名、Token、CSRF、时间戳影响 |
| 复制接口参数 | 平台页面或接口稍改版就可能失效 |
| 解析页面 HTML | 现代后台多为前端渲染，HTML 中通常没有完整数据 |
| 人工导出报表 | 费时、容易漏填，无法形成稳定自动化 |

推荐方案：

```text
真实浏览器
  + 登录态复用
  + 页面触发接口
  + 网络响应监听
  + JSON 优先解析
  + DOM 兜底补充
  + 清洗去重
  + 入库分析
```

核心原则：不强行伪造请求，而是让浏览器像真实用户一样打开页面，由页面自己请求数据，系统只监听和解析返回结果。

## 3. 整体架构

```text
酒店账号 / 门店配置
        ↓
独立浏览器 Profile
        ↓
登录态检测与恢复
        ↓
进入携程商家后台页面
        ↓
监听 XHR / Fetch 接口响应
        ↓
按规则识别业务接口
        ↓
解析 JSON 数据
        ↓
DOM 页面兜底补充
        ↓
数据清洗、脱敏、去重、标准化
        ↓
入库
        ↓
日报 / 看板 / 预警 / AI 分析
```

核心模块：

| 模块 | 作用 |
| --- | --- |
| 登录态管理 | 保存和检查账号登录状态，减少重复登录 |
| 多门店 Profile 管理 | 每家酒店账号独立，避免 Cookie 串用 |
| 页面访问模块 | 打开经营概况、流量、订单、广告、昨日概况等页面 |
| 网络监听模块 | 捕获页面 XHR/fetch 返回的 JSON |
| 接口规则模块 | 判断接口属于点评、订单、流量、广告还是日报；排除菜单、语言包、埋点和静态资源 |
| 数据解析模块 | 将原始 JSON 转成统一字段 |
| ETL 入库模块 | 清洗、脱敏、去重、保存，供后续分析读取 |

## 4. 多门店登录态设计

携程后台通常依赖 Cookie 和浏览器本地存储保存登录状态。多门店采集必须避免账号混用。

推荐 Profile 目录：

```text
storage/
  ctrip_profile_hotel_001/
  ctrip_profile_hotel_002/
  ctrip_profile_hotel_003/
```

规则：

- 每家酒店对应一个独立浏览器 Profile。
- A 门店不会串到 B 门店。
- 登录态不会互相覆盖。
- 一个门店登录失效，不影响其他门店。
- Profile、Cookie、截图和含敏感数据的导出不得进入 Git。

客户可理解为：

> 系统会为每家酒店准备一个专用浏览器身份。登录一次后，后续自动复用，避免每天重复登录。

## 5. 登录流程

```text
启动采集任务
  ↓
读取门店对应浏览器 Profile
  ↓
打开携程后台验证页面
  ↓
判断是否已登录
  ↓
已登录：直接采集
未登录：打开登录页或尝试配置化登录
  ↓
遇到验证码/短信/安全验证：人工介入
  ↓
登录成功后继续采集
```

登录判断不只看 URL，建议综合判断：

- 当前 URL 是否仍在登录页。
- 页面标题是否包含登录提示。
- 是否能进入携程 eBooking 后台。
- 是否出现后台导航或业务页面内容。

失败处理：

- 不绕过平台安全验证。
- 遇到短信、人机验证时保留人工确认入口。
- Headless 模式无法人工登录时，返回明确失败原因。
- 登录失败不写入空经营数据，不假装采集成功。

### 本地实测命令

方式一：先准备登录态，只保存 Profile，不采集不入库。

```powershell
node scripts/probe_ctrip_capture.mjs --profile-id=hotel_001 --login-only=true --login-timeout-ms=600000
```

浏览器弹出后人工完成携程登录。成功后再次运行全量采集：

```powershell
node scripts/probe_ctrip_capture.mjs --profile-id=hotel_001 --sections=wide --data-date=2026-05-31 --output=runtime/ctrip_capture/hotel_001_wide.json
```

方式二：使用本地 Cookie 文件注入。Cookie 文件只放在本机临时目录，不写入 Git，不粘贴到聊天或普通文档。

```powershell
node scripts/probe_ctrip_capture.mjs --profile-id=hotel_001 --sections=wide --cookies-file=runtime/secret/ctrip_cookie.txt --data-date=2026-05-31 --output=runtime/ctrip_capture/hotel_001_cookie_wide.json
```

`probe_ctrip_capture.mjs` 会自动执行采集和摘要。输出状态为 `ready` 表示已经拿到可诊断标准字段；`login_prepared` 表示登录态已保存但尚未采集；`not_ready` 表示登录态或接口数据不足。摘要文件只显示登录状态、模块命中、接口 ID、标准字段和缺口，不输出 Cookie、Authorization、token 或住客隐私字段。

系统接口 `POST /api/online-data/capture-ctrip-browser` 和自动抓取中的 `browser_profile` 任务会返回 `diagnosis_summary`，用于直接判断本次登录态或 Cookie 采集是否已经覆盖收益销售、流量转化、竞争圈、服务质量/IM、广告推广、商旅 BPI 和辅助事实等诊断方向。

## 6. 核心采集方式

携程后台数据通常由页面加载后通过接口返回。系统重点监听：

- XHR
- fetch
- HTTP 200 响应
- 可解析 JSON 响应
- 命中明确业务规则的接口 URL

伪代码：

```python
def on_response(response):
    if response.request.resource_type not in ("xhr", "fetch"):
        return

    if response.status != 200:
        return

    content_type = response.headers.get("content-type", "")
    if "json" not in content_type:
        return

    url = response.url
    body = response.json()
    slot = match_business_slot(url)

    if slot:
        captured[slot] = body
```

一次采集可形成如下捕获结果：

```json
{
  "traffic": {},
  "orders": {},
  "ads": {},
  "ctrip_yesterday": {}
}
```

## 7. 接口规则表

接口规则表用于根据 URL 特征判断响应属于哪类业务数据。

| URL 特征 | 业务归类 |
| --- | --- |
| `getCommentList` | 点评数据 |
| `queryScanFlowDetailsV2` | 流量数据 |
| `queryFlowTransforNew` | 流量转化 |
| `queryHomePageRealTimeData` | 首页实时数据 |
| `unprocessOrderList` | 待处理订单 |
| `queryOrderList` | 订单列表 |
| `getOrderList` | 订单列表 |
| `pyramidad` | 金字塔广告 |
| `promotion` | 推广广告 |
| `fetchMarketOverViewV2` | 昨日成交概况 |
| `getDayReportServerQuantity` | 服务质量/评分 |
| `fetchVisitorTitleV2` | 访客排名 |
| `getDayReportFlowCompete` | 竞品流量 |

规则设计：

- 越具体的规则放越前。
- 同一类数据可以有多个接口特征。
- 未命中的疑似接口要记录 URL、模块、响应摘要和时间。
- 不使用过宽规则，避免误抓菜单、配置和埋点数据。
- 平台接口变化时先补规则，再考虑改解析器。

## 8. 数据模块采集逻辑

### 8.1 点评数据（暂缓）

携程点评当前不作为默认自动采集重点。原因是平台管控较严、稳定投入成本高，且短期经营分析价值低于经营概况、流量、订单和广告数据。

保留以下内容作为后续显式启用时的技术口径，不纳入当前默认采集流程。

采集流程：

1. 打开点评页面。
2. 等待页面加载。
3. 滚动页面触发接口请求。
4. 捕获 `getCommentList` 接口。
5. 解析评论列表。
6. 接口未命中时，从页面 DOM 做有限兜底。

标准字段：

| 字段 | 说明 |
| --- | --- |
| `review_id` | 平台原始评价 ID |
| `platform` | 固定为 `ctrip` |
| `score` | 评分，统一为 1-5 分 |
| `content` | 点评内容 |
| `reply` | 商家回复 |
| `is_replied` | 是否已回复 |
| `is_negative` | 是否差评 |
| `reviewer_name` | 评价人昵称 |
| `room_type` | 房型 |
| `stay_date` | 入住日期 |
| `review_time` | 评价时间 |
| `tags` | 评价标签 |
| `raw_data` | 脱敏后的原始 JSON |

评分兼容字段：

```text
score
rating
starRating
commentScore
reviewScore
avgScore
```

评分处理：

- `4.8` 直接使用。
- `48` 可按后台口径换算为 `4.8`。
- 明显超出 1-5 分的值必须标记异常，不写入错误分数。
- 小于 `4.0` 可标记为差评；缺评分时不标记差评。

DOM 兜底过滤：

- 过滤空文本。
- 过滤菜单、导航、按钮文案。
- 过滤无 ID、无评分、内容过短的数据。
- 不把页面标题、侧边栏、帮助入口写成点评。

### 8.2 流量数据

采集流程：

1. 打开数据中心 / 流量分析页。
2. 等待 SPA 前端渲染。
3. 滚动页面触发懒加载。
4. 捕获流量相关接口。
5. 解析 PV、UV、曝光、点击、订单和转化率。
6. API 缺少排名时，从 DOM 补充页面已展示排名。

标准字段：

| 字段 | 说明 |
| --- | --- |
| `page_views` | 浏览量 PV |
| `unique_visitors` | 访客 UV |
| `click_count` | 订单数或点击数 |
| `exposure_count` | 曝光量 |
| `conversion_rate` | 转化率 |
| `search_rank` | 搜索排名 |
| `category_rank` | 分类排名 |
| `keyword_rank_data` | 原始排名数据 |

经验：

```text
API 获取基础流量
DOM 补充排名信息
```

DOM 解析建议使用 `textContent` 补充提取，因为部分前端组件的完整文本可能不体现在 `innerText` 中。DOM 只补页面已展示的指标，不抓导航、菜单或按钮文本。

### 8.3 订单数据

采集流程：

1. 打开订单管理页。
2. 等待页面完整加载。
3. 等待接口返回。
4. 捕获订单列表接口。
5. 解析订单字段。
6. 按平台订单号去重。

标准字段：

| 字段 | 说明 |
| --- | --- |
| `order_id` | 订单号 |
| `platform` | 固定为 `ctrip` |
| `order_status` | 订单状态 |
| `room_type` | 房型 |
| `room_count` | 房间数 |
| `check_in_date` | 入住日期 |
| `check_out_date` | 离店日期 |
| `nights` | 入住晚数 |
| `total_amount` | 订单总金额 |
| `avg_price` | 平均房价 |
| `guest_name` | 客人姓名 |
| `guest_phone` | 客人电话 |
| `order_time` | 下单时间 |
| `source` | 来源渠道 |

字段兼容：

| 统一字段 | 可能来源字段 |
| --- | --- |
| 订单号 | `orderId`、`id` |
| 金额 | `totalAmount`、`amount`、`orderAmount`、`payAmount` |
| 入住日期 | `checkInDate`、`checkinDate`、`arrivalDate` |
| 离店日期 | `checkOutDate`、`checkoutDate`、`departureDate` |
| 客人姓名 | `guestName`、`userName` |
| 电话 | `phone`、`mobile` |

入库规则：

- 没有订单号的明细不入库。
- 金额去掉逗号后再转数字。
- 客人手机号按最小必要原则保存，进入 `raw_data` 前应脱敏。
- 缺入住日期、离店日期或金额时，不参与依赖这些字段的统计。

### 8.4 广告数据

采集页面：

- 金字塔广告页。
- 推广广告页。

标准字段：

| 字段 | 说明 |
| --- | --- |
| `exposure_count` | 广告展示次数 |
| `click_count` | 用户点击次数 |
| `booking_count` | 广告带来的订单或预订 |
| `cost_amount` | 广告花费 |
| `conversion_metric` | 广告效果指标 |

项目处理：

- 可复用流量类字段保存曝光、点击、转化。
- 平台来源单独标识为 `ctrip_ads` 或 `data_type=advertising`。
- 没有广告成本时不计算 ROI。
- 广告计划、关键词、消耗明细优先进入脱敏 `raw_data`。

### 8.5 昨日经营概况

OTA 平台当天数据可能存在延迟、回滚或未结算。日报更适合采集昨日完整数据。

采集流程：

```text
打开昨日概况页
  ↓
选择昨天日期
  ↓
滚动触发接口加载
  ↓
捕获实时概况接口
  ↓
捕获市场成交接口
  ↓
捕获服务质量接口
  ↓
捕获访客排名接口
  ↓
进入点评页补充分渠道评分
  ↓
写入日报汇总
```

标准字段：

| 字段 | 说明 |
| --- | --- |
| `uv` | 昨日访客数 |
| `order_count` | 昨日订单数 |
| `checkout_revenue` | 昨日成交金额 |
| `checkout_room_nights` | 昨日成交间夜 |
| `avg_price` | 平均房价 |
| `deal_rate` | 成交率 |
| `order_cvr` | 订单转化率 |
| `competitor_rank` | 竞品排名 |
| `ctrip_score` | 携程评分 |
| `psi_score` | 服务质量分 |
| `reply_rate` | 回复率 |
| `hotel_collect` | 酒店收藏数 |
| `visitor_rank` | 访客排名 |
| `neg_tags` | 差评标签 |

## 9. 宿析OS 当前落库口径

当前宿析OS优先统一写入 `online_daily_data`，不因为本方法文档默认新增 `reviews`、`orders`、`traffic_data` 等明细表。携程点评和美团点评先不进入默认自动采集。

| 来源数据 | 推荐 `data_type` | 当前项目字段映射 |
| --- | --- | --- |
| 经营概况 | `overview` 或空值兼容旧数据 | `amount`、`quantity`、`book_order_num`、`comment_score`、`raw_data` |
| 点评 | `review` | `comment_score` / `data_value` 保存评分，详情写入 `raw_data` |
| 流量 | `traffic` | `list_exposure`、`detail_exposure`、`flow_rate`、`order_filling_num`、`order_submit_num`、排名写入 `raw_data` |
| 订单 | `order` | `amount`、`quantity`、`book_order_num`、`data_value`，订单详情写入 `raw_data` |
| 广告 | `advertising` | 曝光、点击、转化复用流量字段，费用和计划写入 `raw_data` |

去重规则：

- 点评按 `review_id` 去重；缺少评价 ID 时按酒店、日期、内容摘要兜底，但必须保留数据质量标记。
- 订单按 `order_id` 去重；缺少订单号时不伪造明细订单。
- 汇总按 `system_hotel_id + source + data_type + dimension + data_date` 更新。
- 日报建议按 `store_id + stat_date` 做唯一约束；已有记录更新，没有记录新增。

新增结构化字段条件：

- 现有字段和 `raw_data` 无法支持保存、回显、编辑、分析或去重。
- 页面、报表、预警或 AI 分析已有明确读取需求。
- 同时补齐迁移、旧数据兼容、权限过滤、脱敏、空值处理和验证。

## 10. 稳定性设计

### 页面加载

OTA 后台多为 SPA 页面，不应只等待 `domcontentloaded`。

建议：

- 页面打开后等待 3-10 秒。
- 滚动页面触发懒加载。
- 必要时 reload 后再等一轮。
- 不同页面使用不同等待窗口。

建议等待窗口：

| 页面 | 等待窗口 |
| --- | --- |
| 点评 | 15 秒 |
| 流量 | 25 秒 |
| 订单 | 15 秒 |
| 昨日概况 | 35 秒 |

### 失败兜底

推荐顺序：

```text
接口 JSON
  ↓
DOM 页面补充
  ↓
原始日志记录
  ↓
本次数据标记为空或失败，不写错误数据
```

注意：

- DOM 是补充，不是伪造数据来源。
- 未命中接口时记录疑似 URL，供后续补规则。
- 字段缺失不强行推测。
- 空数据、登录失效、字段缺失必须显式返回原因。

### 未命中接口记录

如果未命中规则的响应内容出现以下关键词，应记录为候选接口：

```text
pv
uv
order
amount
score
review
visitor
```

记录内容：

- URL。
- 页面模块。
- HTTP 状态。
- 响应摘要。
- 采集时间。
- 是否含敏感字段。

## 11. 数据质量规则

| 场景 | 处理方式 |
| --- | --- |
| 没有订单号 | 不写明细订单 |
| 没有评分 | 不标记差评 |
| 没有金额 | 不计算均价 |
| 没有日期 | 不参与日报统计 |
| 没有广告成本 | 不计算 ROI |
| 登录失效 | 返回 `needs_login` 或明确失败状态 |
| 接口未命中 | 记录候选接口，不写假数据 |
| 平台私有评分 | 保存后台值和影响因素，不反推公式 |

## 12. 客户可理解价值

| 价值 | 说明 |
| --- | --- |
| 减少人工工作 | 原来需要每天登录多个后台、截图、抄数、填表；系统可自动完成大部分采集 |
| 提高数据准确性 | 直接读取后台结构化数据，减少人工录入错误 |
| 统一数据口径 | 将曝光、浏览、访客、订单、转化率、间夜、收入、评分、差评数等指标标准化 |
| 支持长期分析 | 形成平台、门店、房型、广告、点评等趋势分析 |
| 支持 AI 决策 | 为调价、渠道优化、差评处理、广告预算和异常门店识别提供结构化输入 |

## 13. 推荐交付标准

### 第一阶段：基础可用

- 支持单门店携程登录。
- 支持浏览器 Profile 保存登录态。
- 暂不纳入携程点评和美团点评默认采集。
- 支持流量采集。
- 支持订单采集。
- 支持数据入库。
- 支持基础日志。

### 第二阶段：稳定运行

- 支持多门店独立 Profile。
- 支持接口监听规则表。
- 支持 DOM 兜底补充。
- 支持失败重试。
- 支持未命中接口记录。
- 支持数据去重。
- 支持异常数据标记。

### 第三阶段：经营报表

- 支持昨日概况。
- 支持日报汇总。
- 支持企业微信推送。
- 支持差评预警。
- 支持广告效果统计。
- 支持门店维度对比。

### 第四阶段：智能分析

- 渠道转化趋势。
- 差评原因归类。
- 竞品排名变化。
- 广告 ROI 分析。
- 收益管理建议。
- AI 自动生成运营动作。

## 14. 迁移到其他 OTA 平台

迁移到美团、飞猪、同程等平台时，核心框架不变，只替换平台差异项。

| 模块 | 需要替换内容 |
| --- | --- |
| 登录页 | 新平台登录地址 |
| 数据页面 | 新平台点评、订单、流量、广告页面 |
| 接口规则表 | 新平台接口 URL 特征 |
| 字段解析器 | 新平台 JSON 字段映射 |
| DOM 兜底规则 | 新平台页面结构 |
| 入库映射 | 统一到宿析OS标准字段 |

## 15. 一句话版本

> 我们的 OTA 数据采集方式，不是简单爬网页，而是在客户授权账号下，用真实浏览器进入携程等商家后台，自动监听页面加载出来的经营数据接口，将订单、流量、点评、评分、广告等数据统一清洗、去重、入库，最终用于日报、经营分析、差评预警和 AI 运营决策。
