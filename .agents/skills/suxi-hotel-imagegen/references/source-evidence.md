# 公开来源与提炼边界

## 目录

- 公开来源
- 可迁移方法
- 不复制内容

## 公开来源

### 酒店视觉能力样本

- [龙门 AI 酒店摄影作品集](https://www.lmai888.com/showcase)：公开展示日转夜、一键打光、一键阳光、外立面精修和修床等能力方向。

该站点只作为业务能力分类样本。公开页面不能证明内部模型、完整提示词、工作流实现、人工审核或转化效果。

### 官方 Agent Skill 范式

- [OpenAI Codex imagegen Skill](https://github.com/openai/codex/blob/main/codex-rs/skills/src/assets/samples/imagegen/SKILL.md)
- [OpenAI imagegen prompting reference](https://github.com/openai/codex/blob/main/codex-rs/skills/src/assets/samples/imagegen/references/prompting.md)
- [Anthropic skill-creator](https://github.com/anthropics/skills/blob/main/skills/skill-creator/SKILL.md)
- [Agent Skills specification](https://github.com/agentskills/agentskills/blob/main/docs/specification.mdx)
- [GitHub Agent Skills instructions](https://github.com/github/awesome-copilot/blob/main/instructions/agent-skills.instructions.md)

### OTA 真实性依据

- [携程住宿上线常见问题](https://hotels.ctrip.com/hoh/list-your-property/faq.html)：要求真实高清照片和准确反映住宿状况。
- [携程酒店商家经营规则](https://pages.ctrip.com/hotels/IBU/pages/hotelspecification.html)：把盗用其他酒店图片、为误导消费者而修改图片关键信息等列为图片违规。

平台规则可能更新。实际发布前必须核对当前后台规则和酒店业务经理意见。

## 可迁移方法

- 生成与编辑分流。
- 每张输入图显式标注角色。
- 编辑提示重复 `change_only` 和 `must_preserve`。
- 原图非覆盖保存。
- 生成后重新打开并检查不变量。
- SKILL.md 只保留核心闭环，详细知识按需读取。
- 使用 baseline/with-skill eval 验证触发和行为。

## 不复制内容

- 不复制第三方模型、接口、内部路由、节点 ID、账号、Cookie、令牌或整套源码。
- 不复制第三方完整提示词库、付费课程、受版权保护图片、Logo 或客户素材。
- 不把公开功能名称当成内部算法证据。
- 不写死易变化的模型名、供应商降级路径或平台图片规格。
- 不宣称修图本身已经证明提高点击率、转化率、订单或酒店整体收入。

