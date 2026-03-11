<?php

namespace Plugin\Bepusdt;

use App\Contracts\PaymentInterface;
use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin implements PaymentInterface
{
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
        ];
    }

    /**
     * 创建 BEpusdt 支付订单
     */
    public function pay($order): array
    {
        $apiUrl   = rtrim($this->getConfig('api_url'), '/');
        $apiToken = $this->getConfig('api_token');
        $fiat     = $this->getConfig('fiat', 'CNY');
        $tradeType = $this->getConfig('trade_type');

        $amount = $order['total_amount'] / 100;

        // 如果指定了 trade_type，使用 create-transaction（直接跳收银台）
        // 否则使用 create-order（让用户自选币种）
        $endpoint = $tradeType ? '/api/v1/order/create-transaction' : '/api/v1/order/create-order';

        $params = [
            'order_id'     => $order['trade_no'],
            'amount'       => $amount,
            'notify_url'   => $order['notify_url'],
            'redirect_url' => $order['return_url'],
            'fiat'         => $fiat,
            'name'         => 'Xboard - ' . $order['trade_no'],
        ];

        if ($tradeType) {
            $params['trade_type'] = $tradeType;
        }

        $params['signature'] = $this->sign($params, $apiToken);

        $response = $this->httpPost($apiUrl . $endpoint, $params);

        if (!$response || ($response['status_code'] ?? 0) !== 200) {
            $msg = $response['message'] ?? '未知错误';
            \Log::error('[BEpusdt] 创建订单失败', ['response' => $response, 'params' => $params]);
            throw new \App\Exceptions\ApiException('BEpusdt 创建订单失败：' . $msg);
        }

        $paymentUrl = $response['data']['payment_url'] ?? '';
        if (!$paymentUrl) {
            throw new \App\Exceptions\ApiException('BEpusdt 未返回支付链接');
        }

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

        if (empty($params['signature'])) {
            \Log::warning('[BEpusdt] 回调缺少签名');
            return false;
        }

        $receivedSign = $params['signature'];
        $expectSign   = $this->sign($params, $apiToken);

        if ($receivedSign !== $expectSign) {
            \Log::warning('[BEpusdt] 签名验证失败', [
                'received' => $receivedSign,
                'expected' => $expectSign,
            ]);
            return false;
        }

        // status=2 表示支付成功
        $status = (int) ($params['status'] ?? 0);
        if ($status !== 2) {
            \Log::info('[BEpusdt] 订单状态非成功', ['status' => $status, 'order_id' => $params['order_id'] ?? '']);
            return false;
        }

        \Log::info('[BEpusdt] 支付成功回调', [
            'order_id'  => $params['order_id'],
            'trade_id'  => $params['trade_id'],
            'amount'    => $params['amount'],
            'actual'    => $params['actual_amount'],
            'tx_hash'   => $params['block_transaction_id'] ?? '',
        ]);

        return [
            'trade_no'      => $params['order_id'],
            'callback_no'   => $params['trade_id'],
            'custom_result' => 'success',
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
            $filtered[$key] = $value;
        }

        ksort($filtered);

        $str = '';
        foreach ($filtered as $key => $value) {
            if ($str !== '') $str .= '&';
            $str .= "{$key}={$value}";
        }

        $str .= $apiToken;

        return md5($str);
    }

    // ═══════════════════════════════════════════════════════════
    //  HTTP 请求
    // ═══════════════════════════════════════════════════════════

    private function httpPost(string $url, array $data): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            \Log::error('[BEpusdt] HTTP 请求失败', [
                'url'   => $url,
                'error' => curl_error($ch),
            ]);
            curl_close($ch);
            return null;
        }

        curl_close($ch);

        $result = json_decode($response, true);
        if (!is_array($result)) {
            \Log::error('[BEpusdt] 响应解析失败', ['response' => $response, 'http_code' => $httpCode]);
            return null;
        }

        return $result;
    }
}
