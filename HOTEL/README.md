# 宿析OS

## 系统概述
基于 ThinkPHP 8.1 开发的酒店管理系统，不仅覆盖酒店日常运营，更通过深度数据分析赋能收益与决策。支持多酒店数据隔离、分级权限管理、动态配置的日报表与月任务填报。

## 技术栈
- **后端**: ThinkPHP 8.1
- **数据库**: MySQL 5.7+ / SQLite
- **前端**: Vue 3 (CDN) + Tailwind CSS
- **认证**: Token (Cache)
- **Excel处理**: PHP 原生 HTML 表格 (导出) + Python (导入解析)

## 目录结构
```
hotel-admin/
├── app/                    # 应用目录
│   ├── controller/         # 控制器
│   ├── model/             # 模型
│   ├── middleware/        # 中间件
│   └── command/           # 命令行脚本
├── config/                # 配置文件
├── public/                # 公共目录（入口文件）
├── route/                 # 路由定义
├── runtime/               # 运行时目录（SQLite数据库）
├── .env                   # 环境配置
├── database_mysql.sql     # MySQL数据库导出文件
└── README.md              # 本文件
```

## 安装说明

### 环境要求
- PHP 8.0+
- MySQL 5.7+ (或 SQLite)
- Python 3.x (Excel导入功能需要)
- Composer

### 安装步骤

#### 1. 复制项目文件
将整个 `hotel-admin` 目录复制到你的 Web 服务器根目录。

#### 2. 安装依赖
```bash
cd hotel-admin
composer install
```

#### 3. 配置数据库

**方式一：使用 MySQL**
编辑 `.env` 文件：
```env
APP_DEBUG = true

# MySQL 配置
DB_TYPE = mysql
DB_HOST = 127.0.0.1
DB_NAME = hotel_admin
DB_USER = root
DB_PASS = your_password
DB_PORT = 3306
DB_CHARSET = utf8mb4

DEFAULT_LANG = zh-cn
```

创建数据库并导入：
```bash
mysql -u root -p -e "CREATE DATABASE hotel_admin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p hotel_admin < database_mysql.sql
```

**方式二：使用 SQLite**
编辑 `.env` 文件：
```env
APP_DEBUG = true

# SQLite 配置
DB_TYPE = sqlite
DB_NAME = hotel_admin.db

DEFAULT_LANG = zh-cn
```

初始化数据库：
```bash
php think db:init
```

#### 4. 配置Web服务器

**Nginx 配置示例：**
```nginx
server {
    listen 80;
    server_name hotel-admin.test;
    root /path/to/hotel-admin/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

**Apache 配置：**
项目已包含 `.htaccess` 文件，只需启用 `mod_rewrite` 模块即可。

#### 5. 启动开发服务器（可选）
```bash
php think run --port 5000
```

## 默认账号

| 用户名 | 密码 | 角色 |
|--------|------|------|
| admin | admin123 | 超级管理员 |
| manager1 | manager123 | 门店管理员 |
| staff1 | staff123 | 店员 |

## 功能模块

### 1. 酒店管理
- 酒店的增删改查
- 启用/禁用酒店（禁用后关联用户权限自动失效）
- 仅超级管理员可操作

### 2. 用户管理
- 用户增删改查
- 角色分配（超级管理员/门店管理员/店员）
- 酒店关联
- 店长只能管理自己酒店的店员

### 3. 角色管理（仅超级管理员）
- 角色增删改查
- 权限配置

### 4. 日报表管理
- 日报表填写、查看、编辑、删除
- 月累计数据计算
- Excel导入导出
- 数据校验

### 5. 月任务管理
- 月度目标设定
- 与日报表联动对比

### 6. 线上数据获取
- 自动获取线上平台数据
- Cookies管理
- 数据记录查看

### 7. 操作日志（仅超级管理员）
- 登录日志
- 操作记录
- 错误追踪

### 8. 系统配置（仅超级管理员）
- 系统名称、Logo配置
- 菜单名称自定义

## 权限说明

### 角色权限
| 权限 | 超级管理员 | 门店管理员 | 店员 |
|------|-----------|-----------|------|
| 酒店管理 | ✓ | - | - |
| 用户管理 | ✓ | 仅本酒店店员 | - |
| 角色管理 | ✓ | - | - |
| 日报表填写 | ✓ | ✓ | ✓ |
| 日报表编辑 | ✓ | ✓ | ✓ |
| 日报表删除 | ✓ | ✓ | - |
| 月任务填写 | ✓ | ✓ | ✓ |
| 线上数据获取 | ✓ | ✓ | ✓ |
| 操作日志 | ✓ | - | - |
| 系统配置 | ✓ | - | - |

### 酒店禁用影响
当酒店被禁用后：
- 关联该酒店的用户所有权限自动失效
- 用户无法查看、填写、编辑该酒店的任何数据
- 启用酒店后权限自动恢复

## 开发说明

### 编码规范
- PSR-12 编码规范
- 严格类型声明

### API 接口
所有 API 接口位于 `/api` 路径下，需要 Token 认证（登录接口除外）。

请求头：
```
Authorization: {token}
Content-Type: application/json
```

## 更新日志

### v1.0.0
- 初始版本发布
- 完成基础功能模块
- 实现多酒店权限隔离
- 完善操作日志记录
