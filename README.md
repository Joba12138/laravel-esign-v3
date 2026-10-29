# joba-tech/laravel-esign-v3

E签宝 SaaS API V3 PHP 服务封装，面向[普通签署流程](https://open.esign.cn/doc/opendoc/pdf-sign3/tv8gsiqwk2z00wwi)，不集成 PaaS SDK3。保留分模块调用方式，统一处理 HMAC-SHA256 请求签名、文件上传、响应异常和回调验签。核心 Client 不依赖 Laravel，Laravel 项目可直接通过容器或 Facade 使用。

支持 PHP 7.4 / 8.x、Guzzle 7。Laravel 集成测试使用 Laravel 8 的独立组件；其他 Laravel 版本尚未逐一验证。核心可在没有 Laravel 的纯 PHP 项目中使用。

本项目是独立 Composer 包，当前版本为 `v1.0.0`。版本由 Git tag 决定，`composer.json` 不硬编码版本。公开安装命令需在仓库推送并登记 Packagist 后使用；具体步骤见 [发布说明](docs/RELEASING.md)。真实 E签宝沙盒签署和回调联调尚未完成。

## 安装

仓库推送并登记 Packagist 后，在业务项目中安装：

```bash
composer require joba-tech/laravel-esign-v3:^1.0
```

本地开发、尚未登记 Packagist 时，可在业务项目通过 path repository 引入本包。将 `url` 替换为本包的实际目录：

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-esign-v3",
            "options": {
                "versions": {"joba-tech/laravel-esign-v3": "1.0.0"}
            }
        }
    ],
    "require": {"joba-tech/laravel-esign-v3": "^1.0"}
}
```

把包目录放在对应路径后执行 `composer update joba-tech/laravel-esign-v3`。发布到独立仓库时以本目录为仓库根目录，不需要当前业务项目的代码。

## Laravel 配置

支持 Laravel 包自动发现，可发布配置：

```bash
php artisan vendor:publish --provider="Joba\Esign\Laravel\ServiceProvider" --tag=esign-config
```

在 `.env` 配置自己的应用信息，默认连接沙盒：

```dotenv
ESIGN_APP_ID=your-app-id
ESIGN_APP_SECRET=your-app-secret
ESIGN_SANDBOX=true
ESIGN_TIMEOUT=30
ESIGN_CONNECT_TIMEOUT=10
ESIGN_UPLOAD_TIMEOUT=120
ESIGN_CALLBACK_TOLERANCE=0
```

正式应用使用相应的正式 app ID、secret，并设置 `ESIGN_SANDBOX=false`。默认沙盒地址为 `https://smlopenapi.esign.cn`，正式地址为 `https://openapi.esign.cn`；特殊接入地址可以通过 `ESIGN_BASE_URL` 覆盖。修改已缓存的配置后重新执行 `php artisan config:cache`。

```php
use Joba\Esign\Client;
use Joba\Esign\Laravel\Facades\Esign;

$esign = app(Client::class); // 也支持 app('esign')
$detail = $esign->signFlow->detail('your-sign-flow-id');
$sameDetail = Esign::signFlow()->detail('your-sign-flow-id');
```

包内 Provider 已完成单例注册；业务项目直接使用 `app('esign')`，不需要再创建继承
`Client` 的业务 Service 或在 `AppServiceProvider` 重复绑定客户端。

不启用包自动发现的 Laravel 项目可手动注册 `Joba\Esign\Laravel\ServiceProvider`。旧 V2 包也使用 `esign` 配置名和容器别名，因此迁移时需要移除旧注册或自行隔离配置、绑定。

## 纯 PHP 使用

```php
require __DIR__ . '/vendor/autoload.php';

use Joba\Esign\Client;

$esign = new Client([
    'app_id' => 'your-app-id',
    'app_secret' => 'your-app-secret',
    'sandbox' => true,
    'timeout' => 30,
    'connect_timeout' => 10,
    'upload_timeout' => 120,
    'callback_tolerance' => 0,
]);
```

可通过构造函数第二个参数注入 `GuzzleHttp\ClientInterface`，方便测试或配置自己的 HTTP handler。每个 Client 独立保存配置，不需要申请、缓存或刷新 V2 access token。

SDK 会禁用注入客户端的默认 headers、auth、query 和正文选项，防止它们覆盖签名或泄漏至上传地址；自定义 handler、中间件与代理属于调用方信任的传输层，不应改写签名后的请求。

## 上传并创建签署流程

需要分别申请上传地址或印章图片上传凭据时，使用 `file->createUploadUrl($body)`、`file->createFileKey($body)`，参数保留官方字段。`file->upload()` 和 `file->uploadSealImage()` 会自动计算文件元数据、申请地址并完成上传。

以下示例创建一个个人签署人的单文档流程，手机号、姓名、文件路径、坐标需替换为实际值。字段按照当前[精简版发起签署文档](https://open.esign.cn/doc/opendoc/pdf-sign3/nxhgcl3bfgqz8qlz)编写；指定 `psnAccount` 时必须同时提供 `psnInfo.psnName`，使用前确认自己的应用权限和签署人认证要求。

```php
$upload = $esign->file->upload('/absolute/path/contract.pdf', '合同.pdf');
$fileId = $upload['data']['fileId'];

// 按官方文件状态查询结果，只有 2（上传完成）或 5（PDF 转换完成）可签署。
$fileInfo = $esign->file->detail($fileId);
if (!in_array((int) ($fileInfo['data']['fileStatus'] ?? -1), [2, 5], true)) {
    // 由业务延迟任务再次查询；不要在 HTTP 请求里长时间阻塞等待。
    throw new \RuntimeException('文件尚未处理完成，请稍后查询状态再创建签署流程。');
}

$created = $esign->signFlow->createByFile([
    'docs' => [
        ['fileId' => $fileId, 'fileName' => '合同.pdf'],
    ],
    'signFlowConfig' => [
        'signFlowTitle' => '合同签署',
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

$signFlowId = $created['data']['signFlowId'];
$link = $esign->signFlow->signUrl($signFlowId, [
    'clientType' => 'ALL',
    'needLogin' => false,
    'operator' => ['psnAccount' => 'your-signer-mobile'],
    'urlType' => 2,
]);
$signUrl = $link['data']['shortUrl'];

// 签署结果通过回调或主动查询确认。
$flow = $esign->signFlow->detail($signFlowId);
```

收到回调或查询确认全部签署完成、流程已完结后，再获取文件下载地址：

```php
$downloads = $esign->signFlow->downloadUrls($signFlowId);
```

`upload()` 自动计算文件大小及 Base64 MD5、获取上传 URL，再用 PUT 上传原始文件流。上传请求不携带应用的签名鉴权头。Word 等需转换的文件可以调用：

```php
$upload = $esign->file->upload(
    '/absolute/path/contract.docx',
    '合同.docx',
    true,
    'application/octet-stream'
);
```

上传函数不轮询转换结果；调用方需结合 `file->detail()` 判断可用状态。[官方文件详情文档](https://open.esign.cn/doc/opendoc/pdf-sign3/qz4aip)规定 `fileStatus` 为 `2` 或 `5` 时可签署，建议处理中的文件间隔 3 秒查询，最多等待 90 秒；可用队列延迟重试实现。SDK 仅接受可读的本地文件路径，默认 PDF MIME 类型。

## 拖章定位页面

用 `file->createSealPositionUrl()` 获取可视化设置签署位置的页面链接：

```php
$positioning = $esign->file->createSealPositionUrl([
    'customBizNum' => 'contract-layout-1001',
    'fileId' => $fileId,
    'signerRoles' => ['甲方签署区', '乙方签署区'],
    'clientType' => 'ALL',
    'urlType' => 2,
]);
$positioningUrl = $positioning['data']['getSealPositionUrl'];
```

该接口获取定位页面，不创建签署流程。用户拖章并提交后，平台通过 `GET_SEAL_POSITION` 回调返回顶层 `customBizNum` 和 `components`。请在开放平台应用配置中设置消息推送地址并订阅“获取签章位置信息”；该请求不传 `notifyUrl`。链接有效期一天，成功保存一次后失效。完整流程与回调示例见[拖章定位说明](docs/SEAL_POSITION.md)。

## 自有后台管理合同模板

SaaS 接入可以在自己的业务后台展示模板列表、放置制作、编辑和预览按钮。后台页面、菜单、业务权限及模板和组织/业务类型的关联由自己的系统维护；E签宝提供模板数据接口和可视化模板页面链接，不会自动生成业务后台。

```php
// 模板列表用于展示在自己的后台；pageSize 最大为 20。
$result = $esign->template->search(['pageNum' => 1, 'pageSize' => 20]);
$templates = $result['data']['docTemplates'];
$total = $result['data']['total'];

// 先上传底稿并确认 fileStatus 为 2 或 5。
$created = $esign->template->createUrl([
    'docTemplateName' => '劳动合同模板',
    'docTemplateType' => 0, // 0：PDF；1：支持动态表格的 HTML 模板。
    'fileId' => $fileId,
    'redirectUrl' => 'https://your-admin.example.com/esign/templates',
]);
$docTemplateId = $created['data']['docTemplateId']; // 保存，供编辑和填充使用。
$createUrl = $created['data']['docTemplateCreateUrl'];

$edited = $esign->template->editUrl($docTemplateId, [
    'redirectUrl' => 'https://your-admin.example.com/esign/templates',
]);
$editUrl = $edited['data']['docTemplateEditUrl'];

$previewed = $esign->template->previewUrl(['docTemplateId' => $docTemplateId]);
$previewUrl = $previewed['data']['docTemplatePreviewUrl'];
```

创建接口返回 ID 和制作页面链接，用户还需要打开该页面、添加控件并保存模板；后续编辑、预览按钮分别使用对应的链接。链接可从后台按钮打开或跳转，是否支持特定内嵌方式需另外核实，不能假定可任意嵌入 iframe。

[模板列表](https://open.esign.cn/doc/opendoc/pdf-sign3/mghz1g)默认每页 20 条，最大 20 条。[制作页面](https://open.esign.cn/doc/opendoc/pdf-sign3/xagpot)和[编辑页面](https://open.esign.cn/doc/opendoc/pdf-sign3/lgb2go)链接有效期为 24 小时，[预览页面](https://open.esign.cn/doc/opendoc/pdf-sign3/le6t3e7fbsrdmtx6)链接有效期为 30 分钟；过期后重新获取。

接口制作的模板不会同步到 E签宝 SaaS 官网，沙盒与正式环境也不互通。编辑、预览接口仅支持通过 API 制作页面创建的模板，不支持直接编辑或预览官网生成的模板。列表文档说明新版模板服务支持查询官网制作的模板；实际能查询的范围取决于应用及模板服务版本，不应据此承诺官网所有模板都可编辑。

## 资源模块

模块可以通过属性或方法访问，例如 `$esign->file`、`$esign->file()`。`signflow` 为 `signFlow` 的兼容别名。参数数组直接使用 V3 官方字段，不会自动改写成 V2 字段。

- `file`：申请上传地址和文件 Key、拖章定位页面、文件详情、模板生成文件、关键字坐标、签名核验、本地文件和印章图片上传。
- `template`：文档模板列表、制作/编辑/预览链接、模板控件详情。
- `signFlow`：基于文件发起签署、签署链接、批量签署链接、流程详情和列表、撤销、催签、完结、文件下载地址、签署区增删、区块链存证和解约链接。
- `identity`：个人/企业认证链接、身份信息、授权信息。
- `seal`：个人及企业印章模板创建、图片创建、查询、管理链接和授权。
- `organization`：企业成员、管理员查询和添加成员。
- `order`：下单链接、余量、订单查询和管理链接。

例如：

```php
$auth = $esign->identity->personAuthUrl($officialRequestBody);
$template = $esign->template->detail('your-doc-template-id');
$members = $esign->organization->members('your-org-id', $officialQuery);
```

这些示例中的 `$officialRequestBody`、`$officialQuery` 由业务按照当前平台文档准备。尚未提供命名方法的 V3 接口可以走统一请求入口：

```php
$response = $esign->request('GET', '/v3/files/your-file-id');
$response = $esign->request('POST', '/v3/psn-auth-url', $officialRequestBody);

// 查询参数使用第四个参数，路径参数请先 rawurlencode()。
$response = $esign->request('GET', '/v3/persons/identity-info', null, [
    'psnAccount' => 'your-signer-mobile',
]);
```

请求入口会统一处理查询排序、JSON 序列化及请求签名。`null` 表示不发送请求正文；需要 JSON 对象时传入关联数组。请使用命名资源方法或经过核实的 V3 接口路径。

签名遵循[官方签名鉴权文档](https://open.esign.cn/doc/opendoc/dev-guide3/tggw2e)：查询参数按 ASCII 键排序，签名使用解码后的值，空值仅拼接键；发送时使用 URL 编码。SDK 仅接受标量查询值，不接受数组或重复键。

## 响应与异常

成功时返回完整平台响应数组，例如 `['code' => 0, 'message' => '成功', 'data' => [...]]`，不自动剥离 `data`。失败响应、协议错误或传输错误抛出 `EsignException`；本地参数错误可能抛出 `InvalidArgumentException`。

```php
use Joba\Esign\Exceptions\EsignException;

try {
    $response = $esign->signFlow->detail($signFlowId);
    $data = $response['data'];
} catch (EsignException $exception) {
    $businessCode = $exception->getBusinessCode();
    $httpStatus = $exception->getHttpStatus();
    $requestId = $exception->getRequestId();
    $platformResponse = $exception->getResponseData();
    // 按业务需要分类处理；不要直接将身份信息或签名头写入日志。
}
```

SDK 不自动重试请求。创建签署流程等写操作遇到超时，应先查询平台结果，避免重复创建。业务可使用平台支持的外部业务标识实现幂等。

## 回调验签

Laravel 中务必使用 `$request->getContent()` 获取原始正文，先验签和解析，再执行持久化或业务状态变更。重新 JSON 编码会改变签名字节。查询参数需传入实际 query，不能传入混合了正文的 `$request->all()`。

包自动注册 `esign.signature` 中间件别名，直接挂到项目自己的 API 回调路由上。
无需发布文件、创建本地继承类或在 Kernel 中重复注册。该路由不应要求用户登录或 CSRF token。

```php
use App\Http\Controllers\EsignCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/esign/callback', [EsignCallbackController::class, 'store'])
    ->middleware('esign.signature');
```

也可直接使用包内完整类名
`Joba\Esign\Laravel\Middleware\VerifyWebhookSignature::class` 作为路由中间件。
只有需要定制行为时才在业务项目自行继承这个类。包不再提供 `esign-middleware` 发布模板，
也不需要 `resources/stubs` 目录。旧的
`Joba\Esign\Laravel\Middleware\VerifyEsignCallback` 保留为兼容类，
已有业务项目引用它或继承它都可继续使用。

中间件读取 `X-Tsign-Open-Signature`、`X-Tsign-Open-Timestamp`、原始正文和原始 query。
验签失败返回 HTTP 401，签名合法但 JSON 不是有效对象时返回 HTTP 400；通过后将解析结果放到
`$request->attributes->get('esign_callback')` 并继续业务处理。不要从已经被 Laravel 全局输入中间件
清理过的 `$request->all()` 获取签名数据。中间件不会提前返回成功或吞掉业务处理异常。

例如在 `EsignCallbackController::store` 中将事件交给持久化的数据库队列后确认接收：
`ProcessEsignCallback` 由业务项目实现并实现 Laravel 的 `ShouldQueue` 接口，数据库队列也需由
项目配置、迁移和启动 worker。任务内按实际事件标识及流程状态去重。

```php
use App\Jobs\ProcessEsignCallback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

public function store(Request $request)
{
    $payload = $request->attributes->get('esign_callback');

    // 数据库队列持久化失败时让异常继续抛出，不返回成功应答。
    Bus::dispatch((new ProcessEsignCallback($payload))
        ->onConnection('database')
        ->onQueue('esign-callbacks'));

    return response()->json(['code' => '200', 'msg' => 'success'], 200);
}
```

不使用中间件时，也可使用 `app('esign')->callback()->verify($rawBody, $signature, $timestamp, $query)`
单独取得验签布尔结果，或用 `parse()` 验签并解析正文。应从原始 `QUERY_STRING` 通过 `parse_str()`
提取 query，避免 Laravel 的 `TrimStrings` 和 `ConvertEmptyStringsToNull` 改变参与签名的值。
按照[官方回调文档](https://open.esign.cn/doc/opendoc/notify3/sblzg8)（2026-10-23 更新），签名为毫秒时间戳、按键排序后的 query 值、原始正文拼接后计算的 HMAC-SHA256 十六进制字符串。

官方约定 HTTP 200–299 表示接收成功，建议正文为 `{"code":"200","msg":"success"}`，应在 5 秒内返回。平台失败时最多重试 16 次。只有可靠地持久化回调或投递至持久化队列后才能返回成功；耗时业务处理交由任务完成。

官方文档未要求回调时间戳的过期窗口。`callback_tolerance` 默认 `0`，保持签名校验，同时接受延迟重试；如业务确需限制时间偏差，可显式配置正数秒数并结合平台重试周期设计。时间窗口不能代替去重，接收方始终需要实现事件幂等。SDK 不注册业务路由和队列任务。

## 测试与发版

包目录内执行：

```bash
composer install
composer validate --strict
composer test
```

测试通过 mock HTTP 响应验证签名、编码、上传和资源路由，不访问真实 E签宝环境。单元测试通过不能替代使用自己应用完成沙盒合同创建、签署、下载和回调验签的联调。

本项目已按独立仓库整理。上传 Git 仓库并提交 Packagist 的步骤见 [发布说明](docs/RELEASING.md)。测试从本包的 `vendor/autoload.php` 加载依赖，不回退到其他业务项目。Git 源码包含测试；发行归档按 `.gitattributes` 排除测试、开发依赖及本地配置。真实沙盒联调仍需使用自己的应用参数完成。

本实现参考用户提供的 `SaaSAPI_V3_Demo_PHP`（2022 年）、旧 `laravel-esign` 的分模块结构，以及当前 SaaS 普通签署流程与回调文档。未将旧 V2 的账户接口、Token 机制或 PaaS SDK3 接口混入 SaaS V3；生产前仍应结合当前开放平台文档核实权限、必填字段和状态定义。

## License

MIT。
