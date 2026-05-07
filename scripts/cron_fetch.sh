#!/bin/bash
# 线上数据自动获取定时任务
# 每天早上10点执行
#
# 安装方法:
# 1. 编辑 crontab: crontab -e
# 2. 添加以下行:
#    0 10 * * * /workspace/projects/hotel-admin/scripts/cron_fetch.sh >> /app/work/logs/bypass/cron_fetch.log 2>&1
#

cd /workspace/projects/hotel-admin
/usr/bin/php scripts/auto_fetch_online_data.php
