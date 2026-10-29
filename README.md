# Laravel E签宝 V3

E签宝 SaaS API V3 的 PHP 客户端，提供文件上传、合同模板、签署流程、实名认证、印章管理和回调验签能力，支持 Laravel 服务容器、Facade 及纯 PHP 项目。

本包为第三方集成包。接口参数沿用 E签宝 V3 官方字段，统一处理请求签名、HTTP 通信、响应解析和异常。

## 功能

- **文件管理**：本地文件上传、文件转换状态查询、模板生成文件、关键字定位及签名核验。
- **合同模板**：模板查询，以及制作、编辑、预览页面链接。
- **签署流程**：发起签署、获取签署链接、查询状态、催签、撤销、完结及获取文件下载地址。
- **身份与印章**：个人和企业认证、授权信息、印章创建、查询及授权管理。
- **Laravel 集成**：服务自动发现、单例客户端、配置发布、Facade 及回调验签中间件。
- **统一通信**：HMAC-SHA256 请求签名、完整响应返回、分类异常及自定义 Guzzle 客户端。

SDK 负责与 E签宝通信；业务权限、主体与模板关联、流程记录、事件幂等、队列和文件归档由接入项目实现。

## 环境要求

- PHP `^7.4 || ^8.0`，启用 `hash`、`json` 扩展。
- Guzzle `^7.0.1`，由 Composer 安装。
- Laravel 集成测试基于 Laravel 8 组件；其他版本尚未逐一验证。核心客户端可以独立于 Laravel 使用。
- 已开通相应 API 权限的 E签宝应用及其 App ID、App Secret。

本包对接 SaaS API V3 普通签署流程。V2 接口及 PaaS SDK3 需要使用相应的接入方案。

## 安装

包在 Packagist 登记并发布对应版本后，可执行：

```bash
composer require joba-tech/laravel-esign-v3:^1.0
```

本地开发可使用 [Composer path repository](#本地开发)。

## Laravel 接入

### 1. 配置应用

Laravel 自动发现并注册服务。发布配置文件到 `config/esign.php`：

```bash
php artisan vendor:publish --provider="Joba\Esign\Laravel\ServiceProvider" --tag=esign-config
```

该命令仅发布 `config/esign.php`，不生成中间件。旧版本已发布的文件不会自动删除，迁移方式见[回调验签](#回调验签)。

在 `.env` 中配置应用凭据：

```dotenv
ESIGN_APP_ID=your-app-id
ESIGN_APP_SECRET=your-app-secret
ESIGN_SANDBOX=true

ESIGN_TIMEOUT=30
ESIGN_CONNECT_TIMEOUT=10
ESIGN_UPLOAD_TIMEOUT=120
ESIGN_CALLBACK_TOLERANCE=0
```

默认使用沙盒环境。正式环境需配置正式应用凭据，并设置 `ESIGN_SANDBOX=false`。客户端内置地址为：

- 沙盒：`https://smlopenapi.esign.cn`
- 正式：`https://openapi.esign.cn`

如需指定其他接入地址，可设置 `ESIGN_BASE_URL`；值必须是无路径、查询参数和凭据的 HTTPS 源地址。

三个超时配置的单位均为秒。`ESIGN_CALLBACK_TOLERANCE` 是可选的回调时间偏差窗口，单位为秒；默认 `0` 表示关闭时间窗口检查，签名校验仍然启用，以支持延迟到达的回调。

使用配置缓存时，修改后执行 `php artisan config:cache`，并重新加载常驻队列进程。

### 2. 获取客户端

```php
use Joba\Esign\Client;
use Joba\Esign\Laravel\Facades\Esign;

$esign = app('esign');

// 类绑定和字符串别名指向同一客户端实例。
$client = app(Client::class);

$response = $esign->signFlow->detail('your-sign-flow-id');
$response = Esign::signFlow()->detail('your-sign-flow-id');
```

也可在控制器或服务中通过类型提示注入 `Joba\Esign\Client`。关闭包自动发现的项目需手动注册 `Joba\Esign\Laravel\ServiceProvider`。

## 纯 PHP 接入

```php
use Joba\Esign\Client;

require __DIR__ . '/vendor/autoload.php';

$esign = new Client([
    'app_id' => getenv('ESIGN_APP_ID'),
    'app_secret' => getenv('ESIGN_APP_SECRET'),
    'sandbox' => true,
    'timeout' => 30,
    'connect_timeout' => 10,
    'upload_timeout' => 120,
    'callback_tolerance' => 0,
]);
```

`sandbox` 必须传入布尔值。客户端使用应用凭据计算 V3 请求签名，无需管理 V2 access token。

## 调用约定

资源模块支持属性和方法两种访问方式：

```php
$response = $esign->file->detail('your-file-id');
$response = $esign->file()->detail('your-file-id');
```

所有 API 方法返回完整响应数组，保留平台的 `code`、`message` 和 `data` 字段：

```php
$response = $esign->template->detail('your-doc-template-id');
$template = $response['data'];
```

参数数组使用官方 V3 字段名。下文示例中的文件路径、手机号、姓名、模板控件和签署坐标均需替换为实际业务数据；发起主体、签署角色及印章授权需按应用权限配置。

## 文件上传

`upload()` 自动计算文件大小和 Base64 MD5、申请上传地址，并以 PUT 方式上传原始文件内容：

```php
$response = $esign->file->upload('/absolute/path/contract.pdf', '合同.pdf');
$fileId = $response['data']['fileId'];

$fileInfo = $esign->file->detail($fileId);
```

上传方法接受可读、非空的本地文件路径，默认 MIME 类型为 `application/pdf`。上传和文件转换是不同阶段；上传成功后，需通过 `detail()` 确认文件可用。`fileStatus` 为 `2`（上传完成）或 `5`（PDF 转换完成）时可用于签署。

上传需要转换为 PDF 的文件：

```php
$response = $esign->file->upload(
    '/absolute/path/contract.docx',
    '合同.docx',
    true,
    'application/octet-stream'
);
```

SDK 不轮询转换结果。接入项目可使用延迟任务查询文件状态，并设置等待上限。

如需自行组织上传流程，使用 `file->createUploadUrl($params)` 申请文件上传地址，或使用 `file->createFileKey($params)` 申请印章图片上传凭据。印章图片也可通过 `file->uploadSealImage($localPath)` 完成上传。

## 合同模板

### 创建与维护模板

上传底稿并确认文件可用后，获取模板制作页面：

```php
$response = $esign->template->createUrl([
    'docTemplateName' => '告知书模板',
    'docTemplateType' => 0,
    'fileId' => $fileId,
    'redirectUrl' => 'https://admin.example.com/contracts/templates',
]);

$docTemplateId = $response['data']['docTemplateId'];
$createUrl = $response['data']['docTemplateCreateUrl'];
```

保存 `docTemplateId`，并引导管理员打开制作页面、配置控件和保存模板。接口返回制作链接时，模板配置流程尚未完成。

查询、编辑和预览模板：

```php
$templates = $esign->template->search(['pageNum' => 1, 'pageSize' => 20]);
$detail = $esign->template->detail($docTemplateId);

$edited = $esign->template->editUrl($docTemplateId, [
    'redirectUrl' => 'https://admin.example.com/contracts/templates',
]);
$editUrl = $edited['data']['docTemplateEditUrl'];

$previewed = $esign->template->previewUrl(['docTemplateId' => $docTemplateId]);
$previewUrl = $previewed['data']['docTemplatePreviewUrl'];
```

页面链接应在使用时获取，过期后重新申请。页面打开或嵌入方式、模板来源限制及环境隔离规则，以所用模板服务的接入要求为准。

### 根据模板生成文件

```php
// 按模板控件准备填充参数，再生成本次签署使用的文件。
$response = $esign->file->createByTemplate($templateFillParameters);
```

`$templateFillParameters` 按模板 ID、控件标识及官方接口要求组装。生成文件后，应查询文件状态，再使用生成的文件 ID 创建签署流程。

如需独立的可视化签署位置配置页面，可调用 `file->createSealPositionUrl($params)`；定位结果通过 `GET_SEAL_POSITION` 回调接收。该功能用于配置签署位置，签署流程仍需单独创建。

## 签署流程

### 创建流程

以下示例为已就绪的 PDF 文件添加一名个人签署人。手机号、姓名与坐标需替换为实际值：

```php
$response = $esign->signFlow->createByFile([
    'docs' => [
        ['fileId' => $fileId, 'fileName' => '告知书.pdf'],
    ],
    'signFlowConfig' => [
        'signFlowTitle' => '告知书签署',
        'autoFinish' => true,
    ],
    'signers' => [
        [
            'signerType' => 0,
            'psnSignerInfo' => [
                'psnAccount' => 'your-signer-mobile',
                'psnInfo' => ['psnName' => '实际签署人姓名'],
            ],
            'signFields' => [
                [
                    'fileId' => $fileId,
                    'normalSignFieldConfig' => [
                        'autoSign' => false,
                        'signFieldStyle' => 1,
                        'signFieldPosition' => [
                            'positionPage' => '1',
                            'positionX' => 100,
                            'positionY' => 200,
                        ],
                    ],
                ],
            ],
        ],
    ],
]);

$signFlowId = $response['data']['signFlowId'];
```

企业发起、多人签署、自动用章等场景需补充相应的发起人、企业和签署人参数。SDK 按原字段发送参数，业务系统负责校验主体、权限及签署规则。

### 获取签署链接

```php
$response = $esign->signFlow->signUrl($signFlowId, [
    'clientType' => 'ALL',
    'needLogin' => false,
    'operator' => ['psnAccount' => 'your-signer-mobile'],
    'urlType' => 2,
]);

$signUrl = $response['data']['shortUrl'];
```

签署完成后通过回调或 `signFlow->detail($signFlowId)` 确认流程状态。页面跳转本身不能作为签署成功凭证。

### 获取签署文件

确认流程已完结后，获取文件下载地址：

```php
$response = $esign->signFlow->downloadUrls($signFlowId);
$files = $response['data']['files'];
```

该方法封装 GET 下载地址接口，返回下载链接及文件信息，文件内容由接入项目下载。链接应作为临时访问凭据使用；需要长期保存时，将签署后的原始文件归档到业务存储中。

## 回调验签

### Laravel 中间件

包自动注册 `esign.signature` 路由中间件。将其挂载到业务项目的 API 回调路由：

```php
use App\Http\Controllers\EsignCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/esign/callback', [EsignCallbackController::class, 'store'])
    ->middleware('esign.signature');
```

在 E签宝应用中配置该路由的完整公网 HTTPS 地址，并订阅需要接收的事件。回调路由应允许平台直接访问，避免用户登录或 CSRF 校验阻止请求。

中间件使用原始正文、原始查询参数及平台签名头验签，通过后将事件数据写入请求属性：

```php
$payload = $request->attributes->get('esign_callback');
```

- 签名无效：返回 HTTP `401`。
- 签名合法但正文不是有效 JSON 对象：返回 HTTP `400`。
- 校验通过：继续执行业务控制器，由业务逻辑确认接收结果。

中间件不会提前应答或吞掉业务异常。控制器应先将事件可靠持久化，再返回成功；耗时操作可交给队列处理。接收方应实现事件去重和状态幂等，避免重复回调导致重复执行。

中间件类为 `Joba\Esign\Laravel\Middleware\VerifyWebhookSignature`，可直接通过别名使用，无需发布或继承。

旧中间件 `VerifyEsignCallback` 已移除。升级前，将回调路由及 `app/Http/Kernel.php` 中对旧类或本地继承类的引用替换为 `esign.signature`（直接使用类名时改为 `VerifyWebhookSignature::class`），再删除旧版生成的 `app/Http/Middleware/VerifyEsignCallback.php`。如果本地类添加过自定义逻辑，先将该逻辑迁移到业务中间件。

### 手动验签

需要自行接入 HTTP 层时，可调用验证器：

```php
// 以下变量来自收到的 HTTP 请求，正文必须保留原始字节。
$valid = $esign->callback()->verify($rawBody, $signature, $timestamp, $query);

// 验签通过后解析 JSON；失败时抛出异常。
$payload = $esign->callback()->parse($rawBody, $signature, $timestamp, $query);
```

`$signature` 和 `$timestamp` 分别来自 `X-Tsign-Open-Signature`、`X-Tsign-Open-Timestamp` 请求头。Laravel 使用 `$request->getContent()` 获取原始正文；查询参数从原始 `QUERY_STRING` 解析，避免输入清理中间件改变签名数据。重新编码后的 JSON 和 `$request->all()` 不适合作为验签输入。

## 响应与异常

HTTP 错误、平台业务失败及协议错误通过异常返回：

```php
use Joba\Esign\Exceptions\EsignException;

try {
    $response = $esign->signFlow->detail($signFlowId);
} catch (EsignException $exception) {
    $businessCode = $exception->getBusinessCode();
    $httpStatus = $exception->getHttpStatus();
    $requestId = $exception->getRequestId();
    $platformResponse = $exception->getResponseData();
}
```

`TransportException`、`InvalidResponseException` 和 `CallbackVerificationException` 均继承 `EsignException`，可按需分别捕获。参数错误可能抛出 `InvalidArgumentException`，本地文件处理还可能抛出文件相关异常。

SDK 不自动重试请求。创建签署流程等写操作超时后，应先核对平台执行结果，再决定是否重试；业务幂等不能仅依赖重复发送相同参数。日志中应控制凭据、身份信息、原始回调和临时链接的记录范围。

## 扩展调用

### 资源模块

- `file`：文件上传、转换状态、模板生成文件、定位和核验。
- `template`：模板查询、详情及制作、编辑、预览页面。
- `signFlow`：签署流程、签署链接、流程操作和文件下载地址。
- `identity`：个人及企业认证、身份信息和授权信息。
- `seal`：个人及企业印章创建、查询、管理和授权。
- `organization`：企业成员及管理员管理。
- `order`：下单链接、余量、订单查询和管理链接。

`signflow` 是 `signFlow` 的兼容别名。具体方法见 [`src/Resources`](src/Resources)。

### 统一请求入口

尚未提供命名方法的接口，可使用 `request()`；正文和查询参数分别传入：

```php
$response = $esign->request('GET', '/v3/files/' . rawurlencode($fileId));

$response = $esign->request('POST', '/v3/psn-auth-url', $authParameters);

$response = $esign->request('GET', '/v3/persons/identity-info', null, [
    'psnAccount' => 'your-signer-mobile',
]);
```

路径以 `/v3/` 开头，路径参数需 URL 编码，查询参数通过第四个参数传入。`null` 正文表示不发送正文。SDK 统一完成 JSON 序列化和签名；查询值仅支持标量，不支持数组或重复键。

### 自定义 HTTP 客户端

`Client` 构造函数的第二个参数接受 `GuzzleHttp\ClientInterface`，可用于注入测试 handler、代理及其他传输配置。SDK 会覆盖默认 headers、auth、query 和正文选项，以保持请求与签名一致；自定义中间件应保留签名后的请求内容。

## 本地开发

将本包与业务项目放在相邻目录，在业务项目的 `composer.json` 中合并以下配置：

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-esign-v3",
            "options": {
                "symlink": true,
                "versions": {
                    "joba-tech/laravel-esign-v3": "1.0.0"
                }
            }
        }
    ],
    "require": {
        "joba-tech/laravel-esign-v3": "^1.0"
    }
}
```

执行 `composer update joba-tech/laravel-esign-v3`。路径仓库中的版本映射用于本地依赖解析，正式发行版本由 Git tag 决定。

从旧包迁移时，应移除旧包的重复服务注册，检查 `esign` 配置名和容器别名是否冲突。Composer 包名为 `joba-tech/laravel-esign-v3`，PHP 命名空间为 `Joba\Esign`。

## 测试

在本包的源码仓库中执行：

```bash
composer install
composer validate --strict
composer test
```

现有测试使用模拟 HTTP 响应，覆盖签名、参数编码、文件上传、资源路由、Laravel 集成和回调验签，不调用真实 E签宝环境。真实沙盒完整签署及回调联调尚未完成，接入项目仍需验证自身应用权限、模板、签署和下载流程。

## 参考资料

- [E签宝开放平台](https://open.esign.cn/)
- [更新日志](CHANGELOG.md)
- [资源模块源码](src/Resources)

## 许可证

本项目采用 [MIT License](LICENSE)。
