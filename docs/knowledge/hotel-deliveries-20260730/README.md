# 三套酒店交付包的参考知识

用户于2026-10-06要求学习吸纳三套20260730交付包和部署/使用手册，随后要求五遍深入自查自纠。当前知识包为1.1，六条整体成熟度为understood，仅点名的纯函数子机制有合成复现证据。固定版本原文在versions/v1.1，旧内容原文在知识中心保留。详细处置和当前知识ID见 [验收报告](../../capability-absorption/2026-10-06-hotel-deliveries.md)，每轮发现、纠正和边界见 [五轮报告](../../capability-absorption/2026-10-06-hotel-deliveries-five-pass.md)。

- [广告质量分型与真实零值](autox-ad-quality.md)
- [报表字段来源与兜底反例](autox-report-provenance.md)
- [建议、审批、发送与执行回读](watchdog-strategy.md)
- [事件、周期简报与纠错候选](watchdog-events-learning.md)
- [采集身份与最小证据](h5-collection-evidence.md)
- [点评查单与指标反例](h5-review-and-metrics.md)

全部是 `reference_only`，本次六条由等级C和blocked_uses共同约束，决策、任务草稿和外部动作不放行；布尔描述本身不是现有知识门的通用硬约束。方法储存完成不等于正式功能吸纳；没有导入真实经营数据或启用原包程序。源码/文档运行宣称、阈值、版本和固定示例不得作为当前事实或宿析默认值。

来源原件保留在用户提供的临时目录，本目录不复制包含敏感内容的原包或手册。来源路径与SHA256见 source-manifest.json；仅审过的纯函数副本在任务输出目录，可定向复验。
