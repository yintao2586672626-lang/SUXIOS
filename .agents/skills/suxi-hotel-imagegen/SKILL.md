---
name: suxi-hotel-imagegen
description: Use when 宿析OS需要处理酒店或民宿实拍修图、客房修床、卫生间/公区精修、外立面、OTA上架图、日转夜、民宿风、扩图、多图参考或酒店图生视频分镜；也用于判断事实修图与品牌创意图边界。
---

# 宿析酒店修图

## 核心原则

先保护酒店事实，再追求画面效果。OTA 成片必须源于同酒店、同房型或同空间的真实图片；AI 输出在现场人员复核前只是候选稿，不是已验证事实。

**REQUIRED SUB-SKILL:** 实际生成或编辑位图时使用 `imagegen`。本 Skill 只增加酒店领域路由、提示词、不变量、回检和渠道边界，不复制或替代底层图像工具。

## 任务路由

每次只选择一个模式：

| 模式 | 选择条件 | 标签 | 最高可用状态 |
| --- | --- | --- | --- |
| `ota_fact_edit` | 编辑真实酒店/房型图，目标是 OTA、官网实景或事实展示 | `ota_fact_preserving_candidate` | `generated_unverified`，人工核验后才可发布 |
| `brand_creative` | 黄昏、蓝调、虚拟阳光、民宿风、扩图、结构/景观/材质概念改造 | `brand_creative_not_for_ota` | 品牌创意渠道，不得作为 OTA 实景 |
| `video_storyboard` | 同酒店单图或多图生成镜头顺序、提示词、封面与时长方案 | `storyboard_only` | 没有已授权视频提供方时不得声称生成视频 |

若请求改变面积、床型、窗景、设施、结构、永久环境或真实装修，把任务转为 `brand_creative`；不允许保留 OTA 事实标签。

详细判定读取 [hotel-image-modes.md](references/hotel-image-modes.md)。

## 工作流

1. **检查输入**
   - 用 `view_image` 打开每张本地图片；不要只信文件名或用户给的分类。
   - 记录酒店/门店、房型或空间、图片来源、拍摄/提供日期、目标渠道；未知项写 `unverified`。
   - 视频分镜逐图核对酒店绑定：未知时只做内部草案；确认混入其他酒店时拆分任务或阻塞合并。
   - 没有真实编辑底图却要求修真实酒店时，返回 `blocked_missing_source`。

2. **标记图片角色**
   - `edit_target`：唯一事实底图。
   - `scene_source`：视频分镜中单个镜头的事实底图；每张图只约束自己的镜头。
   - `style_reference`：只借鉴色调与质感。
   - `lighting_reference`：只借鉴光线。
   - `composition_reference`：只借鉴构图节奏。
   - `brand_identity_reference`：保护 Logo、门牌、字体或品牌色。
   - `previous_output`：上一轮结果，不自动升级为事实底图。
   - 同一图片不要同时承担事实底图和跨酒店风格素材两种角色；静态修图仍只允许一个 `edit_target`。
   - 同一分镜的全部 `scene_source` 必须属于同一家酒店；未核验时不得交给视频提供方或发布渠道。

3. **声明修改与不变量**
   - 先写 `change_only`：本轮唯一允许变化。
   - 再写 `must_preserve`：酒店、房型、几何、床型、门窗、家具、设施、窗景、固定材质、文字、Logo、消防设施、镜面/玻璃反射关系。
   - 原图看不清的细节保持未知，不用生成内容冒充恢复。

4. **选择场景配方**
   - 从 [prompt-recipes.md](references/prompt-recipes.md) 只读取当前场景配方。
   - 用户提示已具体时只做结构化整理；提示宽泛时只补对结果必要的摄影和材质描述。
   - 每轮重复关键不变量；一次只修一个问题。

5. **执行**
   - 按 `imagegen` 的当前内置工具路径执行，不在本 Skill 写死模型、供应商、API、密钥或外部路由。
   - 原图永不覆盖；使用带版本的新文件名。
   - 多张不同资产分别执行，不把“批量”理解为跨房型融合。

6. **回检**
   - 能取得输出路径时重新用 `view_image` 打开，与事实底图逐项对照。
   - 按 [visual-qa.md](references/visual-qa.md) 检查请求变化、受保护事实、生成伪影、文字和物理一致性。
   - 若当前图像工具或环境不允许重新打开结果，写 `not_visually_verified`；不得声称视觉 QA 通过。
   - 发现问题只提交一个针对性修正，再回检。

7. **交付**
   - 按 [output-contract.md](references/output-contract.md) 返回状态、模式、标签、角色、修改项、不变量、未验证项、视觉 QA 和允许渠道。
   - 不假设携程/美团当前图片尺寸、比例或码率；用户未给且未从当前官方规则核实时写 `channel_spec_unverified`。
   - 不自动上传或发布到携程、美团、官网、小红书或其他渠道。

## 不可越过的事实边界

- 不把城市景换成海景后标为真实房型图。
- 不把竞品酒店、其他门店或其他房型的家具、窗景、装修、设施融合进 OTA 图。
- 不删除烟感、消防标识、插座、固定设备或永久环境来误导消费者。
- 不把扩图、日转夜、虚拟阳光、地拍转高视角当作现场实拍事实。
- 不把“输出像素达到 8K”写成“原生 8K”或“真实细节已恢复”。
- 不因用户、管理者或发布时间压力降低标签和人工复核要求。

可继续完成同一目标的安全版本：事实型基础精修、明确标注的创意效果图、改造概念图、视频分镜包或重拍清单。

## 快速选择

| 请求 | 路由 |
| --- | --- |
| 客房自然光、修床、窗户高光、轻污渍 | `ota_fact_edit`，保护床型、布草纹理、窗景和固定设施 |
| 卫生间去临时杂物、修正色温和镜面高光 | `ota_fact_edit`，保护洁具数量、镜面反射和标识 |
| 外立面去临时车辆/垃圾、基础曝光 | `ota_fact_edit`；永久高架、道路、电线杆不得移除 |
| 日转夜、全楼亮灯、晚霞、民宿风、扩图 | `brand_creative` |
| 竞品图只参考色调 | 可用 `style_reference`；不得迁移竞品事实 |
| 5 张图做 10 秒竖屏视频，但无视频提供方 | `video_storyboard` + `blocked_provider` |

## 示例

输入：“把这张真实大床房做自然光、被子平整版，准备上携程。”

执行摘要：

```yaml
mode: ota_fact_edit
label: ota_fact_preserving_candidate
input_roles:
  - image: Image 1
    role: edit_target
change_only:
  - 平衡自然光和白平衡
  - 整理现有被子的大褶皱
must_preserve:
  - 房间几何、床型、家具、门窗、窗景、设施和布草纹理
status_before_human_review: generated_unverified
```

只有酒店现场人员确认同酒店、同房型、同设施且无误后，才可进入渠道发布流程。

## 参考资料

- 模式与风险：[hotel-image-modes.md](references/hotel-image-modes.md)
- 酒店场景配方：[prompt-recipes.md](references/prompt-recipes.md)
- 视觉回检：[visual-qa.md](references/visual-qa.md)
- 输出契约：[output-contract.md](references/output-contract.md)
- 公开依据与不复制边界：[source-evidence.md](references/source-evidence.md)
