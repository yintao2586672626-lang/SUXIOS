# 美团云 PMS 采集能力吸收记录

## 来源指纹

- 材料：`企业微信推送功能包_朋友版_20260812.zip`
- 材料版本：`2026-08-12`
- SHA-256：`2d28e8fb8802d93d9c1bb2f5d9e0f78e8002853c3b85b82373410a7d11339953`
- 静态完整性：包内 `SHA256SUMS.txt` 的 40 个文件全部匹配
- 许可证：未提供；因此只吸收可验证行为，不复制或分发朋友包源代码
- 原包依赖：`chrome-remote-interface@0.34.0`、`xlsx@0.18.5`
- 宿析OS复用方式：继续使用现有 `playwright-core`、云浏览器 Profile、正式保存与精确回读链，不引入原包依赖

## 吸收范围

来源保持为独立 `meituan_cloud_pms`，事实范围为全酒店当日实时住宿经营事实，不能并入或替代美团 OTA 渠道事实，也不覆盖订单来了 PMS。

采集器在已授权、已登录、独立 Profile 的 `pms.meituan.com` 页面上下文中并发执行三个只读同源请求：

1. 酒店身份：`GET /hotelpms/api/v1/property/hotel/getHotelInfo`
2. 经营概览：`POST /hotelpms/api/v1/report/home/workbench/businessOverview`
3. 房型汇总：`POST /hotelpms/api/v1/report/home/workbench/room`

身份使用接口返回的 `hotelName`/酒店 ID，不再以页面文本包含门店名作为正式身份事实。保存前继续校验业务日期、房费、ADR、RevPAR、已售/总房量、首页可售与房型可售差值；原始响应、Cookie、Token 和请求头不进入输出或数据库。

## 失败与放行

- 身份接口不可用：`meituan_cloud_identity_api_failed`
- 实际酒店与绑定酒店不一致：`meituan_cloud_hotel_identity_mismatch`
- 登录失效、日期不一致、指标缺失或对账失败：沿用现有阻断状态
- 只有正式保存后精确回读为 `readback_verified` 才能进入下游

## 当前阶段

- 本地阶段：`integrated`，解析、身份失败、保存/回读与页面入口的聚焦测试通过
- 真实账号阶段：`unverified_runtime`；本机酒店 80 当前没有美团云 PMS 绑定、授权 Profile 或历史采集，不能声称已经抓到线上数据
