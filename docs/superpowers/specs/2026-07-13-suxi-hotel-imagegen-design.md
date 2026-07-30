# 宿析酒店修图 Skill 设计说明

## 目标

在 `HOTEL/.agents/skills/suxi-hotel-imagegen/` 创建一个项目内 Skill，把酒店图片请求稳定路由为事实型 OTA 修图、品牌创意图或视频分镜，并调用现有 `imagegen` 能力完成实际位图编辑。

Skill 的价值不是复刻龙门 AI 的模型、接口或工作流，而是沉淀可复用的酒店视觉判断：场景识别、输入图角色、房型事实保护、酒店专用提示词、视觉回检、渠道标签和失败状态。

## 事实依据

- 龙门 AI 公开案例展示了酒店后期的高频动作：日转夜、室内打光、阳光氛围、外立面去杂物/材质优化和修床；这些只证明公开能力方向，不证明其内部模型或算法。
- OpenAI Codex 官方 `imagegen` Skill 提供生成/编辑路由、参考图角色、编辑不变量、非覆盖保存、视觉复检和输出路径报告范式。
- Anthropic 官方 `skill-creator` 强调渐进披露、真实 eval、基线对比和触发描述测试。
- GitHub `awesome-copilot` 的 Agent Skills 指南支持 `SKILL.md + references + assets/scripts` 的按需加载结构。
- 携程公开规则要求真实高清住宿照片，并把盗用其他酒店图片、修改关键图片信息误导消费者列为图片违规。

## 模式

| 模式 | 输入 | 允许动作 | 强制标签 | 渠道边界 |
| --- | --- | --- | --- | --- |
| `ota_fact_edit` | 同酒店、同房型真实原图 | 曝光、白平衡、透视、噪点、轻污渍、临时杂物、床品整理、有限高光恢复、真实尺寸放大 | `ota_fact_preserving_candidate` | 现场人员核验前不得写“可直接上传” |
| `brand_creative` | 已授权酒店图或明确创意素材 | 黄昏、蓝调、虚拟阳光、民宿风、扩图、重氛围、概念改造 | `brand_creative_not_for_ota` | 不得作为 OTA 实景或房型事实图 |
| `video_storyboard` | 同酒店已核验图片集 | 画面排序、镜头语言、时长、字幕安全区、封面和视频提示词包 | `storyboard_only` | 没有已授权视频提供方时不声称生成视频 |

结构、床型、设施、面积、窗景或永久环境一旦需要改变，只能进入 `brand_creative`，不能继续挂 `ota_fact_edit` 标签。

## 图片角色

每张输入图必须只有一个显式角色：

- `edit_target`：唯一事实底图。
- `scene_source`：视频分镜中单个镜头的事实底图；每张图只约束自己的镜头。
- `style_reference`：只借鉴色调和质感。
- `lighting_reference`：只借鉴光线。
- `composition_reference`：只借鉴构图节奏。
- `brand_identity_reference`：保护 Logo、品牌色和文字。
- `previous_output`：上一轮结果，不自动升级为事实底图。

OTA 模式禁止跨酒店、跨门店或跨房型融合家具、装修、景观和设施。

静态修图只有一个 `edit_target`；视频分镜可以有多个 `scene_source`，但不能跨空间补绘或伪造连续空间关系。

## 受保护事实

默认保护：酒店与房型归属、墙体和空间几何、门窗数量与位置、床型与床数、家具与设备、卫浴配置、真实窗景、固定材质、楼层/视角、招牌与门牌、Logo、消防设施、烟感、插座、镜面/玻璃反射关系。

原图看不清的内容保持 `unverified`；不得用生成细节伪装成恢复事实。

## 能力覆盖

V1 覆盖用户截图中的业务入口，但按风险重新分组：

- 客房：自然光/打光 × 被子平整/蓬松、修脏、清晰度放大。
- 卫生间与公区：色温、过曝、反光、临时杂物和轻污渍。
- 外立面：白天/夜晚基础精修、临时杂物、真实亮灯、清晰度放大。
- 创意：日转夜、黄昏、蓝调、阳光、民宿风、扩图、地拍转高视角。
- 通用编辑：局部编辑、多图风格参考；OTA 模式不允许跨房型事实融合。
- 视频：单图/多图分镜与提示词包；V1 不接第三方视频账号或自动发布。

“8K”只表示通过文件尺寸检查的输出像素，不等于原生 8K 摄影，也不等于真实细节被恢复。

## 执行闭环

1. 用 `view_image` 打开所有目标图并标注角色。
2. 记录酒店、房型/空间、图片来源、目标渠道和用户要求；缺失项标为 `unverified`。
3. 选择唯一模式；若用户目标与渠道冲突，事实边界优先。
4. 从场景参考中读取一个配方，生成短而明确的编辑提示词。
5. 在每轮提示中重复“只改什么”和“必须保留什么”。
6. 使用现有 `imagegen` Skill/内置图像工具；不写死供应商 API、模型、密钥或外部路由。
7. 能重新取得输出文件时，用 `view_image` 与原图对照；不能重开时标记 `not_visually_verified`。
8. 一次只修一个回检问题，避免重写整段提示导致漂移。
9. 输出状态、模式、标签、修改清单、受保护事实、未验证项、视觉检查结果和允许渠道。
10. 不自动发布到携程、美团或其他平台。

## 文件结构

```text
HOTEL/.agents/skills/suxi-hotel-imagegen/
├── SKILL.md
├── agents/openai.yaml
├── references/
│   ├── hotel-image-modes.md
│   ├── prompt-recipes.md
│   ├── visual-qa.md
│   ├── output-contract.md
│   └── source-evidence.md
└── evals/evals.json
```

`SKILL.md` 只保留路由和闭环；场景配方、回检、契约和来源按需读取。V1 不增加脚本、业务表、页面、账号接入或素材资产。

## 状态契约

- `ready_to_edit`：输入和模式已明确，尚未生成。
- `generated_unverified`：已生成但未重新打开或未完成人工现场核验。
- `review_required`：发现文字、设施、几何、反射或渠道风险。
- `storyboard_only`：只生成视频分镜包。
- `blocked_missing_source`：缺少真实编辑底图。
- `blocked_provider`：请求实际视频但没有已授权提供方。
- `failed`：工具失败或输出不可用。

OTA 图片在现场复核前最高只能是 `generated_unverified`，不能由 Skill 单方面升级为“已验证可发布”。

## 验收

1. 酒店修图、客房修床、外立面、OTA 图片、民宿风、日转夜和图生视频分镜能触发。
2. 普通 UI 图片、OTA 文案、入住率分析和 SVG 编辑不触发。
3. 每个编辑任务都声明图片角色、模式、修改项和不变量。
4. 城市景换海景、跨酒店融合、虚构浴缸等请求不会被标为 OTA 事实图。
5. 没有视觉回检时状态明确为 `not_visually_verified`/`generated_unverified`。
6. 不包含第三方密钥、API 地址、内部路由、账号信息、完整提示词库或版权图片。
7. 通过官方 `quick_validate.py`，并用 baseline/with-skill 场景做独立前向测试。
