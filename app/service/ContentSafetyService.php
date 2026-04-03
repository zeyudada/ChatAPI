<?php

namespace app\service;

class ContentSafetyService
{
    private const TMS_HOST = 'tms.tencentcloudapi.com';
    private const IMS_HOST = 'ims.tencentcloudapi.com';
    private const TMS_ACTION = 'TextModeration';
    private const IMS_ACTION = 'ImageModeration';
    private const API_VERSION = '2020-12-29';

    private $secretId;
    private $secretKey;
    private $defaultRegion;
    private $textRegion;
    private $imageRegion;
    private $textBizType;
    private $imageBizType;
    private $reviewAsBlock;
    private $publicRoot;
    private $projectRoot;

    public function __construct()
    {
        $this->secretId = (string) (config('startadmin.tencent_cloud_secret_id') ?? '');
        $this->secretKey = (string) (config('startadmin.tencent_cloud_secret_key') ?? '');
        $this->defaultRegion = (string) (config('startadmin.tencent_cloud_region') ?? 'ap-guangzhou');
        $this->textRegion = (string) (config('startadmin.tencent_tms_region') ?? $this->defaultRegion);
        $this->imageRegion = (string) (config('startadmin.tencent_ims_region') ?? $this->defaultRegion);
        $commonBizType = (string) (config('startadmin.tencent_content_security_biz_type') ?? '');
        $this->textBizType = (string) (config('startadmin.tencent_tms_biz_type') ?? $commonBizType);
        $this->imageBizType = (string) (config('startadmin.tencent_ims_biz_type') ?? $commonBizType);
        $this->reviewAsBlock = intval(config('startadmin.tencent_content_security_block_review') ?? 1) === 1;
        $this->projectRoot = rtrim(str_replace('\\', '/', root_path()), '/');
        $this->publicRoot = $this->projectRoot . '/public';
    }

    public function moderateText(string $content, array $context = []): array
    {
        $local = $this->inspectLocalText($content);
        if (!$local['ok']) {
            return $local;
        }

        if (!$this->isTencentEnabled()) {
            return $this->passResult('腾讯云内容安全未配置，已通过本地规则检查', [
                'checked_by' => ['local'],
                'cloud_enabled' => false,
            ]);
        }

        $payload = [
            'Content' => base64_encode($content),
            'DataId' => $this->buildDataId('text', $context),
        ];
        if ($this->textBizType !== '') {
            $payload['BizType'] = $this->textBizType;
        }
        $payload = $this->filterNullValues($payload);

        $response = $this->callTencentApi(
            self::TMS_HOST,
            'tms',
            self::TMS_ACTION,
            $this->textRegion,
            $payload
        );

        return $this->normalizeCloudResult('text', $response);
    }

    public function moderateImage(string $image, array $context = []): array
    {
        $local = $this->inspectLocalImage($image);
        if (!$local['ok']) {
            return $local;
        }

        if (!$this->isTencentEnabled()) {
            return $this->passResult('腾讯云内容安全未配置，已通过本地规则检查', [
                'checked_by' => ['local'],
                'cloud_enabled' => false,
            ]);
        }

        $payload = [
            'DataId' => $this->buildDataId('image', $context),
        ];
        if ($this->imageBizType !== '') {
            $payload['BizType'] = $this->imageBizType;
        }

        $imagePayload = $this->buildImagePayload($image);
        if (!$imagePayload['ok']) {
            return $imagePayload;
        }

        $payload = array_merge($payload, $imagePayload['payload']);
        $payload = $this->filterNullValues($payload);

        $response = $this->callTencentApi(
            self::IMS_HOST,
            'ims',
            self::IMS_ACTION,
            $this->imageRegion,
            $payload
        );

        return $this->normalizeCloudResult('image', $response);
    }

    private function inspectLocalText(string $content): array
    {
        $normalized = $this->normalizeTextForInspection($content);
        $rules = [
            'xss_script' => '/<\s*script\b/i',
            'xss_event' => '/on(?:error|load|click|mouseover|focus|submit|mouseenter)\s*=/i',
            'xss_protocol' => '/(?:javascript|vbscript|data\s*:\s*text\/html)/i',
            'html_dangerous_tag' => '/<\s*(?:iframe|object|embed|svg|meta|link|style|img)\b/i',
            'sql_injection' => '/(?:union\s+select|select\s+.*from|sleep\s*\(|benchmark\s*\(|updatexml\s*\(|extractvalue\s*\(|information_schema|xp_cmdshell|or\s+1\s*=\s*1)/i',
            'command_injection' => '/(?:\|\||&&|;\s*(?:cat|curl|wget|bash|sh|powershell|cmd|whoami|net\s+user)|`.+?`|\$\(.+?\))/i',
            'path_traversal' => '/(?:\.\.\/|\.\.\\\\|%2e%2e%2f|%2e%2e\\\\)/i',
            'ssrf' => '/(?:127\.0\.0\.1|0\.0\.0\.0|localhost|169\.254\.169\.254|metadata\.google\.internal|100\.100\.100\.200|file\s*:|gopher\s*:|dict\s*:|php\s*:)/i',
        ];

        $hits = [];
        foreach ($rules as $name => $pattern) {
            if (preg_match($pattern, $normalized)) {
                $hits[] = $name;
            }
        }

        if ($hits) {
            return $this->blockResult('检测到疑似 XSS/注入/渗透载荷，消息已拦截', [
                'source' => 'local',
                'hits' => $hits,
            ]);
        }

        return $this->passResult('本地文本规则检测通过', [
            'source' => 'local',
            'hits' => [],
        ]);
    }

    private function inspectLocalImage(string $image): array
    {
        $normalized = $this->normalizeTextForInspection($image);
        $rules = [
            'dangerous_protocol' => '/^(?:javascript|vbscript|data|file|gopher|dict|php):/i',
            'xss_payload' => '/(?:<\s*script\b|onerror\s*=|onload\s*=|<\s*svg\b|<\s*img\b)/i',
            'path_traversal' => '/(?:\.\.\/|\.\.\\\\|%2e%2e%2f|%2e%2e\\\\)/i',
            'internal_address' => '/(?:127\.0\.0\.1|0\.0\.0\.0|localhost|169\.254\.169\.254|metadata\.google\.internal|100\.100\.100\.200)/i',
        ];

        $hits = [];
        foreach ($rules as $name => $pattern) {
            if (preg_match($pattern, $normalized)) {
                $hits[] = $name;
            }
        }

        if ($hits) {
            return $this->blockResult('检测到疑似恶意图片地址或注入载荷，图片已拦截', [
                'source' => 'local',
                'hits' => $hits,
            ]);
        }

        return $this->passResult('本地图片规则检测通过', [
            'source' => 'local',
            'hits' => [],
        ]);
    }

    private function buildImagePayload(string $image): array
    {
        $localPath = $this->resolveLocalImagePath($image);
        if ($localPath && is_file($localPath)) {
            $content = @file_get_contents($localPath);
            if ($content === false || $content === '') {
                return $this->blockResult('图片内容安全检测失败，图片文件读取异常', [
                    'source' => 'local',
                    'path' => $localPath,
                ]);
            }

            return [
                'ok' => true,
                'payload' => [
                    'FileContent' => base64_encode($content),
                ],
                'message' => '图片已转为本地文件内容送审',
                'detail' => [
                    'path' => $localPath,
                ],
            ];
        }

        if (preg_match('/^https?:\/\//i', $image)) {
            return [
                'ok' => true,
                'payload' => [
                    'FileUrl' => $image,
                ],
            ];
        }

        return $this->blockResult('图片内容安全检测失败，无法定位图片文件', [
            'source' => 'local',
            'image' => $image,
        ]);
    }

    private function resolveLocalImagePath(string $image): ?string
    {
        $image = trim($image);
        if ($image === '') {
            return null;
        }

        $candidates = [];
        $imagePath = preg_replace('/[?#].*$/', '', $image);
        $imagePath = str_replace('\\', '/', $imagePath);

        if (preg_match('/^https?:\/\//i', $imagePath)) {
            $urlPath = parse_url($imagePath, PHP_URL_PATH);
            if (!is_string($urlPath) || $urlPath === '') {
                return null;
            }
            $imagePath = $urlPath;
        }

        $trimmed = ltrim($imagePath, '/');
        if ($trimmed !== '') {
            $candidates[] = $this->publicRoot . '/' . $trimmed;
            $candidates[] = $this->projectRoot . '/' . $trimmed;
        }

        if (strpos($trimmed, 'uploads/') === 0) {
            $subPath = substr($trimmed, strlen('uploads/'));
            $candidates[] = $this->projectRoot . '/uploads/' . $subPath;
        }

        foreach ($candidates as $candidate) {
            $candidate = str_replace('\\', '/', $candidate);
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function buildDataId(string $prefix, array $context): string
    {
        $parts = [
            $prefix,
            $context['room_id'] ?? '0',
            $context['user_id'] ?? '0',
            date('YmdHis'),
            substr(sha1(json_encode($context) . microtime(true)), 0, 10),
        ];

        return implode('_', $parts);
    }

    private function normalizeCloudResult(string $scene, array $response): array
    {
        if (!$response['ok']) {
            return $this->blockResult($response['message'] ?: '内容安全检测请求失败', [
                'source' => 'tencent',
                'scene' => $scene,
                'detail' => $response['detail'] ?? [],
            ]);
        }

        $data = $response['data'] ?? [];
        $suggestion = ucfirst(strtolower((string) ($data['Suggestion'] ?? 'Pass')));
        $label = (string) ($data['Label'] ?? '');

        if ($suggestion === 'Review' && $this->reviewAsBlock) {
            return $this->blockResult($scene === 'text' ? '文本命中人工复审策略，暂不允许发送' : '图片命中人工复审策略，暂不允许发送', [
                'source' => 'tencent',
                'scene' => $scene,
                'suggestion' => $suggestion,
                'label' => $label,
                'data' => $data,
            ]);
        }

        if ($suggestion === 'Block') {
            return $this->blockResult($scene === 'text' ? '文本内容未通过合规检测' : '图片内容未通过合规检测', [
                'source' => 'tencent',
                'scene' => $scene,
                'suggestion' => $suggestion,
                'label' => $label,
                'data' => $data,
            ]);
        }

        return $this->passResult('腾讯云内容安全检测通过', [
            'source' => 'tencent',
            'scene' => $scene,
            'suggestion' => $suggestion,
            'label' => $label,
            'data' => $data,
        ]);
    }

    private function callTencentApi(string $host, string $service, string $action, string $region, array $payload): array
    {
        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return [
                'ok' => false,
                'message' => '内容安全请求体编码失败',
                'detail' => [],
            ];
        }

        $hashedPayload = hash('sha256', $body);
        $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:{$host}\n";
        $signedHeaders = 'content-type;host';
        $canonicalRequest = implode("\n", [
            'POST',
            '/',
            '',
            $canonicalHeaders,
            $signedHeaders,
            $hashedPayload,
        ]);

        $credentialScope = $date . '/' . $service . '/tc3_request';
        $stringToSign = implode("\n", [
            'TC3-HMAC-SHA256',
            $timestamp,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $secretDate = hash_hmac('sha256', $date, 'TC3' . $this->secretKey, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);

        $authorization = sprintf(
            'TC3-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->secretId,
            $credentialScope,
            $signedHeaders,
            $signature
        );

        $headers = [
            'Authorization: ' . $authorization,
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Version: ' . self::API_VERSION,
            'X-TC-Region: ' . $region,
        ];

        $response = $this->postJson('https://' . $host, $body, $headers);
        $json = json_decode($response['body'] ?? '', true);

        if (!is_array($json)) {
            return [
                'ok' => false,
                'message' => '内容安全服务返回异常',
                'detail' => [
                    'http' => $response['detail']['http_code'] ?? 0,
                    'body' => $response['body'] ?? '',
                ],
            ];
        }

        $result = $json['Response'] ?? [];
        if (!empty($result['Error'])) {
            return [
                'ok' => false,
                'message' => (string) ($result['Error']['Message'] ?? '腾讯云内容安全检测失败'),
                'detail' => [
                    'code' => $result['Error']['Code'] ?? '',
                    'request_id' => $result['RequestId'] ?? '',
                ],
            ];
        }

        return [
            'ok' => true,
            'data' => $result,
        ];
    }

    private function postJson(string $url, string $body, array $headers): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'header' => '',
                'body' => '',
                'detail' => [
                    'http_code' => 0,
                    'error' => $error,
                ],
            ];
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $detail = curl_getinfo($ch);
        curl_close($ch);

        return [
            'header' => substr($response, 0, $headerSize),
            'body' => substr($response, $headerSize),
            'detail' => $detail,
        ];
    }

    private function normalizeTextForInspection(string $content): string
    {
        $normalized = $content;
        for ($i = 0; $i < 2; $i++) {
            $decoded = rawurldecode($normalized);
            if ($decoded === $normalized) {
                break;
            }
            $normalized = $decoded;
        }

        $normalized = html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($normalized);
    }

    private function filterNullValues(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->filterNullValues($value);
            }
            if ($payload[$key] === null || $payload[$key] === [] || $payload[$key] === '') {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    private function isTencentEnabled(): bool
    {
        return $this->secretId !== '' && $this->secretKey !== '';
    }

    private function passResult(string $message, array $detail = []): array
    {
        return [
            'ok' => true,
            'message' => $message,
            'detail' => $detail,
        ];
    }

    private function blockResult(string $message, array $detail = []): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'detail' => $detail,
        ];
    }
}
