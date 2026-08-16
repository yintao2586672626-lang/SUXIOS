# 宿析云浏览器查看通道

新增门店时，宿析只保存门店、平台公开门店 ID 和加密后的浏览器 Profile 状态；账号、密码、短信码、验证码都由用户直接输入平台原页面，不进入宿析接口或数据库。

查看链路：

1. 已登录用户调用 `POST /api/cloud-browser-profiles/open-login`。
2. 服务端签发一次性登录票据并调用仅监听 `127.0.0.1:8787` 的网关。
3. 页面只收到 `/cloud-browser-viewer/...` 相对地址和 15 分钟 HttpOnly Cookie。
4. Nginx 对 noVNC 的每个资源和 WebSocket 请求执行 `auth_request`；静态资源走
   loopback noVNC，`/cloud-browser-viewer/websockify` 必须转入网关生命周期代理。
   auth 子请求同时产生仅供 Nginx 与网关使用的 Profile/会话摘要；网关只接受与当前
   活跃登录槽精确匹配的摘要，且这些响应头不会返回浏览器。
5. 用户完成或取消登录后，网关先断开该会话的全部 WebSocket，再停止浏览器、
   封存 Profile；因此旧查看页不能跨会话继续看到下一门店桌面。

安装顺序必须是先发布包含授权 API 的应用，再执行：

```bash
sudo bash /var/www/suxios/current/deploy/remote-browser/install_secure_remote_browser.sh /var/www/suxios/current
sudo bash /var/www/suxios/current/deploy/nginx/install_cloud_browser_viewer.sh /var/www/suxios/current
sudo bash /var/www/suxios/current/deploy/remote-browser/verify_secure_remote_browser.sh
```

`5900`、`6080`、`8787`、按需 CDP 端口都必须保持 loopback-only，不添加公网防火墙规则。未带有效查看 Cookie 请求 `/cloud-browser-viewer/vnc.html` 必须返回 `401`。
