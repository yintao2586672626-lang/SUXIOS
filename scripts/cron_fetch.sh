#!/bin/bash
# 线上数据自动获取定时检查
# 应用内部负责判断：历史固定数据保底每天一次，实时快照默认每2小时一次
#
# 安装方法:
# 1. 编辑 crontab: crontab -e
# 2. 添加以下行:
#    * * * * * /workspace/projects/hotel-admin/scripts/cron_fetch.sh >> /app/work/logs/bypass/cron_fetch.log 2>&1
#

cd /workspace/projects/hotel-admin
/usr/bin/php scripts/auto_fetch_online_data.php
