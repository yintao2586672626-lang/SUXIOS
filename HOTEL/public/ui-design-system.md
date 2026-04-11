# 宿析OS UI 设计系统

## 🎨 设计基础

### 色彩系统
| 名称 | 色值 | 用途 |
|------|------|------|
| primary-500 | #3B82F6 | 主要按钮、链接 |
| primary-600 | #2563EB | 主要按钮悬停 |
| success-500 | #10B981 | 成功状态 |
| error-500 | #EF4444 | 错误状态 |
| warning-500 | #F59E0B | 警告状态 |

### 字体系统
- 主要字体: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'PingFang SC', 'Microsoft YaHei', sans-serif
- 字体大小: 12px / 14px / 16px / 18px / 20px / 24px / 30px

### 间距系统 (8px 单位)
- xs: 4px | sm: 8px | md: 16px | lg: 24px | xl: 32px

### 圆角
- sm: 6px | md: 8px | lg: 12px | xl: 16px | 2xl: 20px

## 🧱 组件规范

### 按钮
```css
.btn-primary {
  background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
  color: white;
  padding: 0.625rem 1.25rem;
  border-radius: 8px;
  font-weight: 500;
  transition: all 0.3s ease;
  box-shadow: 0 4px 14px rgba(59,130,246,0.35);
}
```

### 卡片
```css
.card {
  background: white;
  border-radius: 16px;
  border: 1px solid #E5E7EB;
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
  transition: all 0.3s ease;
}
```

### 表格
```css
.table-container {
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid #E5E7EB;
}
.table-container thead {
  background: linear-gradient(180deg, #F8FAFC 0%, #F1F5F9 100%);
}
```

## 📱 响应式断点
- sm: 640px | md: 768px | lg: 1024px | xl: 1280px

## ♿ 可访问性
- 色彩对比度 ≥ 4.5:1
- 触摸目标 ≥ 44px
- 键盘导航支持
