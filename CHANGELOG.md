# 更新日志

## Unreleased

- Composer 包名改为 `joba-tech/laravel-esign-v3`，PHP 命名空间 `Joba\Esign` 与 `app('esign')` 调用保持兼容。

- 回调验签中间件命名为 `VerifyWebhookSignature`，自动注册 `esign.signature` 路由别名，默认无需发布或继承。
- 保留 `VerifyEsignCallback` 兼容类，已有引用和本地继承类可继续使用。
- 移除 `esign-middleware` 发布入口及 `resources/stubs` 模板，保留配置发布。
- 更新回调接入文档，并覆盖新中间件、旧类兼容及不同 Router 启动顺序的别名注册测试。

## 1.0.0 - 2026-10-27

- 提供 E签宝开放平台 V3 HMAC-SHA256 请求签名、统一响应与异常。
- 封装文件、文档模板、签署流程、实名认证、印章、企业成员和订单资源。
- 支持自有后台查询模板列表、获取制作/编辑/预览页面链接及检索文件关键字坐标；模板来源和环境按 SaaS 平台规则隔离。
- 支持本地文件及印章图片上传、原始回调报文验签和可选时间窗口；默认接受延迟重试，由业务处理事件幂等。
- 提供框架无关 PHP Client、Laravel 自动发现、配置发布和 Facade。
- 支持通过 `esign-middleware` 发布 Laravel 回调验签中间件；保留原始正文和 query，向业务传递已验签回调数据。
- 默认沙盒环境；不自动重试创建流程等有副作用的请求。
- 显式声明直接使用的 PSR-7 依赖，提供独立 Composer 安装、Laravel 集成测试及 Packagist 发布说明。
- 新增 `file->createSealPositionUrl()`，对接 `POST /v3/files/get-seal-position-url` 拖章定位页面接口。
- 文件上传凭据方法统一命名为 `createUploadUrl()`、`createFileKey()`，上传助手改用新名称。
- 补充 `GET_SEAL_POSITION` 回调、应用事件订阅和定位链接使用说明。
- 增加新接口、签名及定位回调数据结构测试。

面向 SaaS API V3 普通签署流程，不集成 PaaS SDK3。接口依据随项目提供的 2022 年官方 V3 demo 整理，回调协议按 2026-10-23 更新的官方文档核实。本版本准备为独立 Git 仓库及 `v1.0.0` 标签。Packagist 登记由维护者完成；真实沙盒完整签署及回调联调尚未执行。
