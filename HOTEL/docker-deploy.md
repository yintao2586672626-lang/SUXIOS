# 宿析OS Docker 部署配置

## Docker 部署方案 (PHP + Nginx + SQLite)

### 文件说明
- `Dockerfile` - 应用镜像构建
- `docker-compose.yml` - 容器编排
- `nginx.conf` - Nginx 配置
- `php-local.ini` - PHP 配置

## 快速部署

### 1. 上传项目到服务器
```bash
# 在服务器上创建目录
mkdir -p /opt/hotel
# 使用 scp 或 rsync 上传整个 HOTEL 文件夹
```

### 2. 构建并启动
```bash
cd /opt/hotel
docker-compose up -d --build
```

### 3. 访问应用
```
http://服务器IP:8080
```

### 4. 管理命令
```bash
# 查看日志
docker-compose logs -f

# 重启
docker-compose restart

# 停止
docker-compose down
```

## 数据持久化
- SQLite 数据库: `./runtime/hotel_admin.db` → 容器内 `/var/www/hotel/runtime`
- 日志文件: `./runtime/logs/` → 容器内 `/var/www/hotel/runtime/logs`
