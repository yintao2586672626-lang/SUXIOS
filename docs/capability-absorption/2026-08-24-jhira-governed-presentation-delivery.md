# JHIRA 演示方法吸纳：宿析OS证据治理交付件

## 来源与处置

- 来源仓库：`https://github.com/moyusheng0916-eng/JHIRA-YUSHENG-PPT`
- 固定提交：`4dc9898c86ef3c4589c903e69ad12f6e398dcf28`
- 来源树 SHA-256：`8bfc490509e9fb46a44a81dc0f753355ce3b6c5c9b4e9737e929136431334fdd`
- Skill 树 SHA-256：`cee95b70b70ccd899a058f31fb918a4e9a45b6da50c4ef318368cd07e10f2497`
- 许可证：来源未提供开源许可证。
- 处置状态：`reference_only`。未安装来源 Skill、未运行来源构建脚本、未复制来源代码、品牌或配色。

静态审查发现来源包没有依赖锁，示例构建依赖环境内的 Playwright、
`@oai/artifact-tool`、macOS Chrome 路径与 `unzip`，并存在不由机器证据支持的
固定视觉 `PASS` 文案。因此只吸收方法，不继承其安全、可重放或质量声明。

## 吸收的最高价值能力

将“同一份规范驱动 HTML 与 PPTX、页脚页码、来源说明、跨格式 QA”的思路，
改造成宿析OS原生的证据治理交付链：

1. 从已保存且按租户/酒店精确读取的 AI 经营日报建立 PresentationSpec。
2. 将事实、派生指标、辅助判断、待确认动作、人工决定和未知项分层。
3. HTML 与可编辑、无宏 PPTX 只消费同一规格指纹，渲染阶段不重算指标。
4. 规格和 ZIP 制品都正式保存并精确回读；浏览器下载前再次校验字节数和
   SHA-256。
5. 实际离线浏览器和 PPTX 栅格化检查替代来源中的固定 `PASS` 文案。

## 宿析OS原生边界

- 摘要只能来自证据台账计数和已声明范围，不复制自由文本日报摘要。
- 异常信号和 AI 解读的自由文本不依赖关键词黑名单：默认统一降为
  `UNKNOWN / hypothesis_review_required`，正文只展示“观察到线索、尚不能归因”的
  受控模板；信号标题只接受受控机器码映射，未知类型显示“异常信号 N”。
  `type/code/key/label/name/evidence/message/description` 原值都不重发，只合并为
  SHA-256 供回到原日报人工复核。
- 人工决定必须有支持的决定状态、记录 ID、正操作者 ID 和合法记录时间；空占位不进入台账。
- 正式制品 POST 必须绑定精确 `presentation_spec_id` 与
  `expected_spec_fingerprint`；漂移返回 409。
- 制品在单事务内以 `rendered_pending_readback` 写入，精确核对租户、酒店、
  日报、受众、规格和字节后才升级为 `rendered_and_readback_verified`；失败回滚。
- 浏览器在每个异步阶段核对当前日报、酒店、受众、规格 ID 和指纹；切换上下文
  会取消旧响应，旧响应不能触发下载。
- 读路径必须从已授权酒店行解析正租户 ID；缺失时直接失败，不能退化为仅按
  酒店/日报过滤。规格与制品回读同时核对行身份和规格内嵌身份。
- 培训稿移除结构化酒店/日报身份、精确业务日期和人工决定，并散列来源身份；
  自由文本仍标记为需要人工内容复核。
- 所有发布、外部消息、OTA/PMS 写入保持 `false`，不从“生成成功”推导授权。

## 本地闭环与证据层级

- 入口：AI 经营日报“结果交付件”。
- 操作：选择 owner / expert / training，生成正式演示包。
- 保存：PresentationSpec 与 ZIP artifact 持久化。
- 回显：最新或指定历史 artifact 精确读回；浏览器验真后才下载。
- 真实样本：日报 `32`、酒店 `80`、业务日期 `2026-07-29`，规格 `15`、制品
  `21`，adapter `2026-08-24.5`、renderer `2026-08-24.5`。
- 规格指纹：`9624ca1c32db6d1c24f0446dba781e916aef2c7bb173af58740227ab09ca224f`。
- ZIP SHA-256：`69fcf2fbae60475418aea6e46d08539529e13cd39cd878e3b09687a5d67d385a`。
- HTML：8 页，离线、零外部请求、零页面错误、零溢出、键盘翻页通过。
- PPTX：8 页由本机 PowerPoint 实际打开并导出；90 个文本框与全部形状边界
  检查为零问题，HTML/PPTX 蒙太奇逐页复核一致。
- 当前成熟度：`integration_pass`（本机数据库、服务、浏览器组件和双格式渲染）。
- 未证明：GitHub 持久化、部署、真实账号点击、生产环境、酒店经营效果和
  `field_validated`。
- 每份新制品的 manifest 仍保持 `human_review_status=pending`；样本视觉复核不能
  自动替代未来制品的人工审阅。
