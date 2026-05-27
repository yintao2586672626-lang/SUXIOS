# 美团浏览器抓取方案

## 定位

美团数据抓取以浏览器 Profile + response 监听为主，旧的后端直连 API/Cookie 抓取保留为兼容入口。

原因：美团 ebooking 的签名、登录态、iframe/SPA 页面变化较频繁，单纯由后端拼接口参数不可稳定实现完整抓取。

当前默认重点是流量、订单、经营数据和广告。美团点评先暂缓，只保留显式手动启用能力。

## 登录态

- Profile 目录：`storage/meituan_profile_{store_id}`
- 首次运行会打开美团登录页，人工登录后 Profile 自动保留。
- 后续运行复用同一 Profile，不在 Git 中保存登录态。

## 页面顺序

| 步骤 | 页面 |
| --- | --- |
| 登录验证 | `https://me.meituan.com/ebooking/` |
| 流量 iframe | `https://me.meituan.com/ebooking/merchant/ebIframe?iUrl=%2Febooking%2Fdata-center%2Findex.html` |
| 新流量 SPA | `https://eb.meituan.com/newhb-sub-app/data-center-pc/home/index.html` |
| 广告 | 通过 `--ads-url` 指定，监听 `cureShops` |
| 订单 | `https://me.meituan.com/ebooking/merchant/ebIframe?iUrl=%2Febooking%2Forder-eb%2Findex.html%23%2Fcheckin` |
| 点评（暂缓） | `https://me.meituan.com/ebooking/merchant/comment-manage-react#/home` |

## 命中规则

| 数据 | 优先来源 | 命中关键词 |
| --- | --- | --- |
| 流量 | response + DOM/HTML | `businessData`、`traffic`、`peerTrends` |
| 广告 | response | `cureShops` |
| 订单 | response + DOM/HTML | `/orders/list`、`/order/unhandled/count` |
| 点评（暂缓） | response + DOM | `queryGeneralCommentInfo`、`commentsInfo`、`comments/statistics` |

## 入库映射

项目实际使用 `online_daily_data`，不新增 `reviews`、`traffic_data`、`orders` 表。

| 来源数据 | `data_type` | 核心映射 |
| --- | --- | --- |
| 流量 | `traffic` | `list_exposure=exposure_count`、`detail_exposure=page_views/click_count`、`flow_rate=conversion_rate`、`raw_data` 保留关键词排名等 |
| 广告 | `advertising` | `list_exposure=exposure_count`、`detail_exposure=click_count`、`flow_rate=conversion_rate`、`raw_data` 保留广告和关键词数据 |
| 订单 | `order` | `amount=total_amount`、`quantity=room_count*nights`、`book_order_num=order_count/1`、`data_value=avg_price`、订单详情进 `raw_data` |
| 点评（暂缓） | `review` | 仅显式手动启用时保存，不进入默认自动采集 |

## 使用

### 页面直接抓取

在系统页面进入 `美团ebooking数据获取 -> 浏览器抓取`，选择数据归属酒店后点击 `开始抓取并入库`。

系统会使用酒店配置中的 `POI ID / Store ID` 启动本机浏览器抓取脚本；首次运行需要在弹出的美团窗口完成人工登录，登录态会保存到 `storage/meituan_profile_{store_id}`，后续复用。

只抓取并生成 JSON：

```bash
npm run meituan:capture -- --store-id=68471 --system-hotel-id=1
```

抓取后提交到后端入库：

```bash
npm run meituan:capture -- --store-id=68471 --system-hotel-id=1 --submit=true --token=YOUR_LOGIN_TOKEN
```

如果当前环境没有 `npm`，可直接使用 Node：

```bash
node scripts/meituan_browser_capture.mjs --store-id=68471 --system-hotel-id=1
```

默认等同于：

```bash
node scripts/meituan_browser_capture.mjs --store-id=68471 --sections=traffic,orders
```

## 后端接口

`POST /api/online-data/capture-meituan-browser`

直接由后端启动本机浏览器抓取脚本，抓取完成后入库。页面按钮 `开始抓取并入库` 使用该接口。

`POST /api/online-data/save-meituan-captured-data`

请求体：

```json
{
  "system_hotel_id": 1,
  "payload": {
    "store_id": "68471",
    "poi_id": "68471",
    "reviews": [],
    "traffic": [],
    "ads": [],
    "orders": []
  }
}
```

返回：

```json
{
  "saved_count": 4,
  "row_count": 4,
  "counts": {
    "advertising": 1,
    "order": 1,
    "review": 1,
    "traffic": 1
  }
}
```
