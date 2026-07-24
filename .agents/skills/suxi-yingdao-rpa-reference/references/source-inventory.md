# 来源与边界

## 当前快照

- 来源：影刀官方公开 RPA 帮助文档菜单
- 菜单接口：https://api.yingdao.com/api/noauth/v1/yddoc/menus/getMenuTreeByBrandCode?brandCode=rpa&languageCode=zh-CN
- 核对时间：2026-07-23T16:27:04.052Z
- 帮助中心顶层栏目：10
- 帮助中心文档：3682
- 帮助中心文件夹：487
- 帮助中心全部节点：4169
- 指令根 ID：`711200729240932352`
- 指令文档：2527
- 标准指令分支：406
- 自定义指令分支：2121
- 帮助中心目录 SHA-256：`3128492c9a1392862cf03926e27ca2a1567abd5b880032b0a8d483e501b0ad83`
- 指令目录 SHA-256：`816f1cdd465c8ea54b2c9ef47113de2494c79527592fc88cbc0640189e2cac9a`
- 文档正文更新时间：未知；菜单接口没有提供可证明的编辑时间

## 抓取边界

- robots.txt：https://www.yingdao.com/robots.txt
- `/yddoc/` 状态：未被禁止
- 许可状态：`restricted_or_unknown`
- 用户协议：https://www.yingdao.com/html/user_license.html

本地只保存菜单元数据和原创映射，不保存完整正文、图片、HTML、截图或示例代码。robots 允许不等于取得复制或转载许可。

“自定义指令”分支的公开菜单没有提供逐条作者或许可字段，因此只能确认页面存在，不能把该分支全部表述为影刀官方内置指令。

## 更新规则

运行 `scripts/refresh-catalog.mjs` 只刷新帮助中心与指令目录元数据及 robots.txt。目录 SHA 变化时，先查看新增、删除或改名的条目；正文只在真实任务命中后通过 `scripts/fetch-doc-outline.mjs` 单页读取。
