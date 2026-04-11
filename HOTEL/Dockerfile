# 宿析OS Dockerfile
# PHP 8.2 + Nginx 官方镜像

FROM php:8.2-fpm-alpine

# 安装 Nginx 和 SQLite
RUN apt-get update && apt-get install -y \
    nginx \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 配置 Nginx
COPY nginx.conf /etc/nginx/nginx.conf
COPY php-local.ini /usr/local/etc/php/conf.d/php.ini

# 创建必要的目录
RUN mkdir -p /var/www/hotel/runtime \
    && mkdir -p /var/www/hotel/public \
    && mkdir -p /var/log/nginx \
    && chown -R www-data:www-data /var/www/hotel

# 复制应用文件
COPY . /var/www/hotel

# 暴露端口
EXPOSE 8080

# 启动 Nginx + PHP-FPM
CMD service nginx start && php-fpm && tail -f /dev/null
