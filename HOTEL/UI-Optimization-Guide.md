# 宿析OS UI 优化实施指南

## 📁 新增文件

1. **`public/ui-design-system.md`** - 设计系统核心规范
2. **`public/enhanced-components.css`** - 增强组件样式库

## 🚀 快速实施

### 步骤 1：在 index.html 中引入新样式
在 `<head>` 中添加：
```html
<link rel="stylesheet" href="enhanced-components.css">
```

### 步骤 2：使用新组件

#### 按钮
```html
<!-- 主要按钮 -->
<button class="btn btn-primary">
  <i class="fas fa-plus"></i> 新增
</button>

<!-- 次要按钮 -->
<button class="btn btn-secondary">取消</button>

<!-- 成功按钮 -->
<button class="btn btn-success">保存成功</button>

<!-- 危险按钮 -->
<button class="btn btn-danger">删除</button>

<!-- 幽灵按钮 -->
<button class="btn btn-ghost">查看详情</button>

<!-- 按钮尺寸 -->
<button class="btn btn-primary btn-sm">小按钮</button>
<button class="btn btn-primary btn-lg">大按钮</button>

<!-- 图标按钮 -->
<button class="btn btn-icon btn-primary">
  <i class="fas fa-edit"></i>
</button>
```

#### 表单
```html
<div class="form-group">
  <label class="form-label form-label-required">酒店名称</label>
  <input type="text" class="form-input" placeholder="请输入酒店名称">
  <p class="form-help">酒店名称不能超过50个字符</p>
</div>

<!-- 验证状态 -->
<input class="form-input is-valid" type="text" value="验证通过">
<input class="form-input is-invalid" type="text" value="验证失败">
<p class="form-error"><i class="fas fa-exclamation-circle"></i> 请填写必填项</p>
```

#### 卡片
```html
<div class="card">
  <div class="card-header">
    <h3 class="card-title">酒店概览</h3>
    <p class="card-subtitle">实时数据统计</p>
  </div>
  <div class="card-body">
    <p>卡片内容区域</p>
  </div>
  <div class="card-footer">
    <span class="text-gray-500">最后更新: 10分钟前</span>
  </div>
</div>

<!-- 数据卡片 -->
<div class="card card-metric">
  <div class="metric-icon" style="background: var(--primary-100); color: var(--primary-600);">
    <i class="fas fa-bed"></i>
  </div>
  <div class="metric-value">85.3%</div>
  <div class="metric-label">入住率</div>
</div>
```

#### 表格
```html
<div class="table-container">
  <table class="table table-striped">
    <thead>
      <tr>
        <th><input type="checkbox"></th>
        <th>酒店名称</th>
        <th>入住率</th>
        <th>状态</th>
        <th>操作</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><input type="checkbox"></td>
        <td>希尔顿酒店</td>
        <td>85%</td>
        <td><span class="badge badge-success">营业中</span></td>
        <td>
          <button class="btn btn-icon btn-ghost">
            <i class="fas fa-edit"></i>
          </button>
        </td>
      </tr>
    </tbody>
  </table>
</div>
```

#### 侧边栏
```html
<nav class="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">宿析OS</div>
  </div>
  <ul class="sidebar-menu">
    <li class="sidebar-item">
      <a href="#" class="sidebar-link active">
        <i class="fas fa-chart-line sidebar-icon"></i>
        <span>仪表盘</span>
      </a>
    </li>
    <li class="sidebar-item">
      <a href="#" class="sidebar-link">
        <i class="fas fa-hotel sidebar-icon"></i>
        <span>酒店管理</span>
        <span class="sidebar-badge">12</span>
      </a>
    </li>
  </ul>
</nav>
```

#### 加载状态
```html
<!-- 骨架屏 -->
<div class="skeleton-card">
  <div class="skeleton skeleton-text" style="width: 40%;"></div>
  <div class="skeleton skeleton-text"></div>
  <div class="skeleton skeleton-text"></div>
  <div class="skeleton skeleton-text" style="width: 60%;"></div>
</div>

<!-- 加载动画 -->
<div class="spinner"></div>
```

## 🎨 色彩系统使用

```css
/* 使用 CSS 变量 */
.my-element {
  color: var(--primary-500);
  background: var(--gray-100);
  border-radius: var(--radius-lg);
}

/* 使用 Tailwind 类 */
<div class="text-primary-500 bg-gray-100 rounded-lg">
  内容
</div>
```

## 📱 响应式断点

```css
/* 移动端优先 */
@media (min-width: 640px) { /* sm */ }
@media (min-width: 768px) { /* md */ }
@media (min-width: 1024px) { /* lg */ }
@media (min-width: 1280px) { /* xl */ }
```

## ♿ 可访问性

- 所有按钮有清晰的焦点状态
- 表单元素有标签关联
- 色彩对比度符合 WCAG AA 标准
- 支持键盘导航
- 触摸目标最小 44px

## 🔄 迁移现有组件

将现有样式替换为新系统：

| 旧类名 | 新类名 |
|--------|--------|
| `btn-primary` | `btn btn-primary` |
| `btn-success` | `btn btn-success` |
| `btn-danger` | `btn btn-danger` |
| `btn-secondary` | `btn btn-secondary` |
| `input-field` | `form-input` |
| `card` | `card` (保持) |
| `badge-success` | `badge badge-success` |
| `badge-danger` | `badge badge-error` |
| `badge-warning` | `badge badge-warning` |
| `badge-info` | `badge badge-info` |
| `table-container` | `table-container` (保持) |

## ✅ 检查清单

- [ ] 引入 enhanced-components.css
- [ ] 更新按钮组件
- [ ] 更新表单组件
- [ ] 更新卡片组件
- [ ] 更新表格组件
- [ ] 更新侧边栏
- [ ] 添加加载状态
- [ ] 测试响应式布局
- [ ] 测试键盘导航
- [ ] 检查色彩对比度
