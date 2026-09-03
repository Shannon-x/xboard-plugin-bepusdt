<?php

namespace Plugin\Bepusdt;

use App\Contracts\PaymentInterface;
use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    // 超时与重试是「同步阻塞在用户 checkout 请求里」的：最坏等待 ≈ timeout×retries+退避。
    // 30s×3 次曾让用户干等 90 秒后误以为支付挂了而取消订单，钱却打到已取消订单的收款
    // 会话上（2026-07-14 事故）。15s×2 次把最坏等待压到 ~33s，网关慢时尽快明确报错让
    // 用户重试，而不是让他自己去点取消。
    private const MAX_RETRIES       = 2;
    private const RETRY_BASE_DELAY  = 1;
    private const DEFAULT_TIMEOUT   = 15;
    private const CONNECT_TIMEOUT   = 10;

    /** 网关 trade_id 的缓存 TTL：迟到支付人工核对/对账/撤单都可能用到，保留 24h。 */
    private const TRADE_ID_CACHE_TTL = 86400;

    /**
     * 会话复用的安全余量（秒）：距网关自报的会话到期时间不足这么久时，宁可重建也不复用，
     * 避免把一条即将失效的支付链接发给用户。
     */
    private const SESSION_REUSE_MARGIN = 60;

    /** 网关未返回 expiration_time 时的保守会话时长（秒）。 */
    private const SESSION_FALLBACK_TTL = 600;

    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            $methods['BEpusdt'] = [
                'name'        => 'USDT 加密货币支付',
                'icon'        => '₮',
                'plugin_code' => $this->getPluginCode(),
                'type'        => 'plugin',
            ];
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'api_url' => [
                'label'       => 'API 地址',
                'type'        => 'string',
                'required'    => true,
                'description' => 'BEpusdt 网关地址（如 https://pay.example.com）',
            ],
            'api_token' => [
                'label'       => 'API Token',
                'type'        => 'string',
                'required'    => true,
                'description' => '对接令牌',
            ],
            'trade_type' => [
                'label'       => '交易类型',
                'type'        => 'string',
                'default'     => '',
                'description' => '如 usdt.trc20。留空则由用户在收银台选择',
            ],
            'fiat' => [
                'label'       => '法币类型',
                'type'        => 'string',
                'default'     => 'CNY',
                'description' => 'CNY / USD / EUR / GBP / JPY',
            ],
            'timeout' => [
                'label'       => '请求超时（秒）',
                'type'        => 'string',
                'default'     => '15',
                'description' => 'HTTP 请求超时时间，默认 15 秒（同步阻塞在用户支付请求里，不宜过长）',
            ],
        ];
    }

    /**
     * 创建 BEpusdt 支付订单
     */
    public function pay($order): array
    {
        $apiUrl    = rtrim($this->getConfig('api_url'), '/');
        $apiToken  = $this->getConfig('api_token');
        $fiat      = $this->getConfig('fiat', 'CNY');
        $tradeType = $this->getConfig('trade_type');
        $timeout   = max(10, (int) $this->getConfig('timeout', self::DEFAULT_TIMEOUT));

        // notify_url 由框架 PaymentService::pay() 计算，且会优先采用 Payment.notify_domain
        // 列的值（管理后台支付方式的"通知域名"字段），用于前后端域名分离场景。
        $notifyUrl = $order['notify_url'] ?? '';

        $amount = $order['total_amount'] / 100;

        // ── 会话复用 ─────────────────────────────────────────────────────────────
        // 支付页会以数秒一次的频率反复调用 checkout，使同一订单在网关侧被反复重建会话
        // （2026-09-03 实测：单个订单 18 分钟内触发 155 次 create-order）。
        // BEpusdt 按「会话开始之后到账」匹配转账，会话每重建一次，匹配窗口起点就往后挪一次，
        // 用户早先付的那笔便永远落在窗口之前 —— 于是网关收了钱、40 秒内归集了钱，订单却
        // 一直停在待支付，20 分钟后按过期取消（工单 #2625 实证，用户 10.899 USDT 被吞）。
        // 因此在网关自报的 expiration_time 有效期内复用已有会话，不再重复创建。
        $sessionKey = 'bepusdt:session:' . $order['trade_no'];
        try {
            $cached = \Cache::get($sessionKey);
            if (is_array($cached)
                && !empty($cached['payment_url'])
                && ((int) ($cached['expires_at'] ?? 0)) - self::SESSION_REUSE_MARGIN > time()
            ) {
                \Log::info('[BEpusdt] 复用未过期的支付会话', [
                    'trade_no'   => $order['trade_no'],
                    'trade_id'   => $cached['trade_id'] ?? '',
                    'expires_in' => (int) $cached['expires_at'] - time(),
                ]);
                return ['type' => 1, 'data' => $cached['payment_url']];
            }
        } catch (\Throwable $e) {
            // 缓存不可用时退回正常创建流程，不影响支付
        }

        $endpoint = $tradeType ? '/api/v1/order/create-transaction' : '/api/v1/order/create-order';

        $params = [
            'order_id'     => $order['trade_no'],
            'amount'       => $amount,
            'notify_url'   => $notifyUrl,
            'redirect_url' => $order['return_url'],
            'fiat'         => $fiat,
            'name'         => 'Xboard - ' . $order['trade_no'],
        ];

        if ($tradeType) {
            $params['trade_type'] = $tradeType;
        }

        $params['signature'] = $this->sign($params, $apiToken);

        $response = $this->httpPostWithRetry($apiUrl . $endpoint, $params, $timeout);

        if (!$response || ($response['status_code'] ?? 0) !== 200) {
            $msg = $response['message'] ?? '未知错误';
            \Log::error('[BEpusdt] 创建订单失败', ['response' => $response, 'params' => $params]);
            throw new \App\Exceptions\ApiException('BEpusdt 创建订单失败：' . $msg);
        }

        $paymentUrl = $response['data']['payment_url'] ?? '';
        if (!$paymentUrl) {
            throw new \App\Exceptions\ApiException('BEpusdt 未返回支付链接');
        }

        // 记住网关侧 trade_id：迟到支付人工核对、对账、必要时调 cancel-transaction 撤单
        // 都需要它，而回调之外没有别的途径再拿到（网关按 order_id 复建会话时 trade_id 不变）。
        $gatewayTradeId = (string) ($response['data']['trade_id'] ?? '');
        if ($gatewayTradeId !== '') {
            try {
                \Cache::put('bepusdt:trade_id:' . $order['trade_no'], $gatewayTradeId, self::TRADE_ID_CACHE_TTL);
            } catch (\Throwable $e) {
                // 缓存不可用不影响支付主流程
            }
        }

        // 缓存整个会话供上面的复用分支使用。TTL 取网关自报的 expiration_time（实测 1199s），
        // 缺失或异常时回退到保守值 —— 宁可多建一次会话，也不能把已失效的链接发给用户。
        $expiresIn = (int) ($response['data']['expiration_time'] ?? 0);
        if ($expiresIn <= 0 || $expiresIn > 3600) {
            $expiresIn = self::SESSION_FALLBACK_TTL;
        }
        try {
            \Cache::put($sessionKey, [
                'payment_url' => $paymentUrl,
                'trade_id'    => $gatewayTradeId,
                'expires_at'  => time() + $expiresIn,
            ], $expiresIn);
        } catch (\Throwable $e) {
            // 缓存不可用不影响支付主流程
        }

        \Log::info('[BEpusdt] 订单创建成功', [
            'trade_no'   => $order['trade_no'],
            'trade_id'   => $gatewayTradeId,
            'notify_url' => $notifyUrl,
            'expires_in' => $expiresIn,
        ]);

        return [
            'type' => 1,
            'data' => $paymentUrl,
        ];
    }

    /**
     * 验证 BEpusdt 回调签名并返回订单信息
     */
    public function notify($params): array|bool
    {
        $apiToken = $this->getConfig('api_token');

        // 兜底：若控制器传入的 $params 为空（例如 Content-Type 异常导致 $request->input() 失效），
        // 回退到 raw body 自行解析，保证回调最大可能落地。
        if (!is_array($params) || empty($params)) {
            $raw = '';
            try {
                $raw = (string) request()->getContent();
            } catch (\Throwable $e) {
                // ignore
            }
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded) && !empty($decoded)) {
                $params = $decoded;
                \Log::warning('[BEpusdt] $params 为空，已回退解析 raw body', [
                    'raw_preview' => mb_substr($raw, 0, 500),
                ]);
            }
        }

        $ctx = $this->collectCallbackContext($params);

        if (empty($params['signature'])) {
            \Log::warning('[BEpusdt] 回调缺少签名', $ctx);
            return false;
        }

        $receivedSign = (string) $params['signature'];
        $expectSign   = $this->sign($params, $apiToken);

        if (!hash_equals($expectSign, $receivedSign)) {
            \Log::warning('[BEpusdt] 签名验证失败', $ctx + [
                'received' => $receivedSign,
                'expected' => $expectSign,
            ]);
            return false;
        }

        $status = (int) ($params['status'] ?? 0);
        if ($status !== 2) {
            // BEpusdt Go 端目前只在成功时主动推送，status≠2 实际不会到达；
            // 保留分支以防协议变化，但降到 debug 避免日志噪音。
            \Log::debug('[BEpusdt] 订单状态非成功', $ctx + ['status' => $status]);
            return false;
        }

        \Log::info('[BEpusdt] 支付成功回调', $ctx + [
            'amount'  => $params['amount']        ?? null,
            'actual'  => $params['actual_amount'] ?? null,
            'tx_hash' => $params['block_transaction_id'] ?? '',
        ]);

        $tradeNo = (string) ($params['order_id'] ?? '');
        // 回调里的 amount 是创建支付时下发的法币金额（已纳入签名，不可被篡改），单位元。
        $paidAmount = (int) round(((float) ($params['amount'] ?? 0)) * 100);

        // 金额绑定（防欠额开通）：核心订单在此自校验"实付法币额 ≥ 订单应付额"。
        // 充值订单不在 v2_order 表（由 RechargeNotifyController 用下方返回的 paid_amount 自行校验），此处查不到即跳过。
        if ($tradeNo !== '' && $paidAmount > 0) {
            $order = \App\Models\Order::where('trade_no', $tradeNo)->first();
            if ($order && $paidAmount < (int) $order->total_amount) {
                \Log::error('[BEpusdt] 实付金额低于订单应付额，拒绝开通', $ctx + [
                    'paid_amount'  => $paidAmount,
                    'order_amount' => (int) $order->total_amount,
                ]);
                return false;
            }
        }

        return [
            'trade_no'      => $tradeNo,
            'callback_no'   => (string) ($params['trade_id'] ?? ($params['block_transaction_id'] ?? '')),
            'paid_amount'   => $paidAmount,
            'custom_result' => 'success',
        ];
    }

    // ═══════════════════════════════════════════════════════════
    //  内部辅助
    // ═══════════════════════════════════════════════════════════

    private function collectCallbackContext(array $params): array
    {
        $req = null;
        try {
            $req = request();
        } catch (\Throwable $e) {
            // ignore
        }
        return [
            'ip'       => $req?->ip(),
            'ua'       => $req?->userAgent(),
            'order_id' => $params['order_id'] ?? null,
            'trade_id' => $params['trade_id'] ?? null,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    //  签名算法（与 BEpusdt 官方文档一致）
    // ═══════════════════════════════════════════════════════════

    /**
     * BEpusdt MD5 签名
     * 1. 筛选非空、非 signature 参数
     * 2. 按 key ASCII 升序排序
     * 3. key=value 用 & 连接
     * 4. 末尾追加 api_token（无 & 分隔）
     * 5. MD5 取小写
     */
    private function sign(array $params, string $apiToken): string
    {
        $filtered = [];
        foreach ($params as $key => $value) {
            if ($key === 'signature') continue;
            if ($value === null || $value === '') continue;
            if (is_array($value)) continue; // 防御：嵌套结构不参与签名
            $filtered[$key] = $value;
        }

        ksort($filtered, SORT_STRING);

        $str = '';
        foreach ($filtered as $key => $value) {
            if ($str !== '') $str .= '&';
            $str .= "{$key}={$value}";
        }

        $str .= $apiToken;

        return md5($str);
    }

    // ═══════════════════════════════════════════════════════════
    //  HTTP 请求（带重试 + 指数退避）
    // ═══════════════════════════════════════════════════════════

    private function httpPostWithRetry(string $url, array $data, int $timeout): ?array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $startTime = microtime(true);
            $result    = $this->httpPost($url, $data, $timeout);
            $elapsed   = round(microtime(true) - $startTime, 2);

            if ($result !== null) {
                if ($attempt > 1) {
                    \Log::info('[BEpusdt] 重试成功', [
                        'attempt' => $attempt,
                        'elapsed' => "{$elapsed}s",
                        'url'     => $url,
                    ]);
                }
                return $result;
            }

            $lastError = $elapsed;

            if ($attempt < self::MAX_RETRIES) {
                $delaySec = self::RETRY_BASE_DELAY * (2 ** ($attempt - 1));
                // 上限 3 秒避免在 Octane 下长时间阻塞 worker
                $delaySec = min($delaySec, 3);
                \Log::warning('[BEpusdt] 请求失败，准备重试', [
                    'attempt'    => $attempt,
                    'max'        => self::MAX_RETRIES,
                    'elapsed'    => "{$elapsed}s",
                    'retry_in'   => "{$delaySec}s",
                    'url'        => $url,
                ]);
                usleep((int) ($delaySec * 1_000_000));
            }
        }

        \Log::error('[BEpusdt] 已达最大重试次数，请求最终失败', [
            'max_retries'  => self::MAX_RETRIES,
            'last_elapsed' => "{$lastError}s",
            'url'          => $url,
        ]);

        return null;
    }

    private function httpPost(string $url, array $data, int $timeout): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TCP_KEEPALIVE  => 1,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);

        if (curl_errno($ch)) {
            \Log::error('[BEpusdt] HTTP 请求失败', [
                'url'     => $url,
                'error'   => curl_error($ch),
                'errno'   => curl_errno($ch),
                'elapsed' => "{$totalTime}s",
            ]);
            curl_close($ch);
            return null;
        }

        curl_close($ch);

        $result = json_decode($response, true);
        if (!is_array($result)) {
            \Log::error('[BEpusdt] 响应解析失败', [
                'response'  => mb_substr((string) $response, 0, 500),
                'http_code' => $httpCode,
                'elapsed'   => "{$totalTime}s",
            ]);
            return null;
        }

        return $result;
    }
}
