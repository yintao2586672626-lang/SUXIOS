# 项目配置备忘录

## 运行环境
- **Web服务器**: PHP 内置服务器 (端口 8090)
- **PHP版本**: 8.2+
- **数据库**: MySQL (XAMPP)
- **项目路径**: C:\Users\Admin\Desktop\JDXM\JDSJ\HOTEL

## 数据库配置
```
类型: MySQL
数据库名: hotelx
用户名: root
密码: (空)
主机: 127.0.0.1
端口: 3306
字符集: utf8
```

## 启动命令

### 方式一：PHP 内置服务器（推荐）
```powershell
cd C:\Users\Admin\Desktop\JDXM\JDSJ\HOTEL\public
C:\xampp\php\php.exe -S 0.0.0.0:8090 router.php
```

### 方式二：使用启动脚本
```powershell
cd C:\Users\Admin\Desktop\JDXM\JDSJ\HOTEL
start_server.bat
```
然后修改端口为 8090

## 访问地址
```
前台页面: http://localhost:8090
API接口:  http://localhost:8090/api
```

## 登录账号
```
超级管理员:
  用户名: admin
  密码: admin123

门店经理:
  用户名: manager1
  密码: (需自行测试)

店员:
  用户名: staff1
  密码: (需自行测试)
```

## 注意事项
1. 启动前请确保 XAMPP MySQL 服务已运行
2. 如果端口 8090 被占用，可以改用其他端口如 8080
3. 数据库密码为空，如果之前设置了密码需要更新 .env 文件

## 相关文件
- 配置文件: .env
- 启动脚本: start_server.bat, run.bat
