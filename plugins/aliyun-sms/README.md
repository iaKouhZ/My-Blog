# aliyun-sms 插件接入指引

本插件通过阿里云**号码认证服务（Dypnsapi）**发送短信验证码，签名客户端为手写
HMAC-SHA1 RPC 实现（`AliyunRpc.php`，仅依赖 cURL），不依赖官方 SDK 与 Composer。
插件**预装但默认禁用**，在后台「插件管理」中启用并配置后生效。

## 1. 工作方式

采用**本地生成码模式**（官方 SendSmsVerifyCode 文档的 TemplateParam 直传值方式）：
验证码由内核生成（6 位数字），经 `TemplateParam` 以具体值下发（如
`{"code":"123456","min":"10"}`，而非 `##code##` 占位符）。官方文档明确：该模式下
阿里云接口无法校验验证码，因此**核验完全由内核本地表（`verify_codes`）执行**，
错误容忍次数、有效期、原子消费等安全策略与其它渠道完全一致；
阿里云仅承担短信通道职责（端点 `dypnsapi.aliyuncs.com`）。

## 2. 阿里云控制台准备

1. 开通**号码认证服务**。
2. 签名与模板：**不支持自定义签名**。官方接口 `SendSmsVerifyCode` 仅接受
   号码认证控制台“赠送签名配置”页面选择的**系统赠送签名**，且必须搭配
   **赠送模板**（默认 `100001`）下发；无需审核，但签名/模板内容由平台提供。
   方案名 SchemeName **可留空**（官方文档：不填则使用默认方案）。
3. 创建 RAM 子用户并授权（最小权限原则）：
   仅授予 `dypns:SendSmsVerifyCode`（本地生成码模式不再调用核验接口，
   无需 `dypns:CheckSmsVerifyCode`），建议使用自定义策略仅包含上述 Action，资源范围 `*`。
4. 为该子用户创建 AccessKey，取得 AccessKeyId / AccessKeySecret。

## 3. 后台配置项

| 配置 | 说明 |
|---|---|
| AccessKeyId / AccessKeySecret | RAM 子用户密钥；Secret 保存后不回显、不落日志 |
| SignName | 填号码认证控制台的**赠送签名**（不支持自定义签名，需搭配赠送模板） |
| TemplateCode | 赠送模板 CODE，默认 `100001` |
| SchemeName | 方案名（≤20 字符，**可留空**；不填时使用阿里云默认方案） |
| ValidTime | 有效期（分钟；传给阿里云时已自动换算为秒） |
| Interval | 云端侧重发间隔（秒） |
| 错误容忍次数 | 1-5，缺省 2；核验由内核本地表执行，此配置直接生效 |

> AccessKeyId / AccessKeySecret 保存时会自动剔除空白字符；若仍报
> `SignatureDoesNotMatch`，请确认该 Secret 与 AccessKeyId 属于同一 RAM 用户且未被轮换作废。

## 4. 错误码对照

`MOBILE_NUMBER_ILLEGAL`（手机号非法）、`BUSINESS_LIMIT_CONTROL`（频控）、
`FREQUENCY_FAIL`（频率限制）、`INVALID_PARAMETERS`（参数错误）、
`FUNCTION_NOT_OPENED`（服务未开通）、`SignatureDoesNotMatch`（网关签名校验失败，
通常为 AccessKeySecret 错误或含多余空白）等已映射为中文友好提示；
其余错误码原样附在提示中，完整列表见阿里云官方文档。

## 5. 安全说明

- 本站频控（60 秒重发间隔、每日 ≤10 条）由内核在发送前强制执行；
  发送成功后经 `VerificationService::recordSent()` 写入可核验行（used=0）支撑频控统计与本地核验；有效期采用插件配置，内核登记上限为 60 分钟。
- 核验策略与其它渠道完全一致：错误容忍次数（声明者配置、内核钳制 1-5）、
  有效期、原子消费（一次性）、错满即作废。
- 审计日志只记录发送结果、场景、API 错误码与核验成败；手机号自动脱敏，
  **不会**记录明文验证码、AccessKeySecret。
