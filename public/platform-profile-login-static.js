window.SUXI_PLATFORM_PROFILE_LOGIN_STATIC = (() => {
    const buildPlatformProfileLoginPayload = (options = {}) => {
        const builder = window.SUXI_OTA?.buildPlatformProfileLoginPayload;
        if (typeof builder !== 'function') {
            throw new Error('平台 Profile 登录静态契约未就绪');
        }
        return builder(options);
    };

    return Object.freeze({ buildPlatformProfileLoginPayload });
})();
