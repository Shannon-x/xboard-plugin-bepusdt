# BEpusdt 加密货币支付插件

为 Xboard 对接 [BEpusdt](https://github.com/v03413/BEpusdt) 加密货币收款网关。

## 支持的加密货币

BEpusdt 支持多链多币种：

| 币种 | 网络 | trade_type |
|------|------|-----------|
| USDT | TRON (TRC20) | `usdt.trc20` |
| USDT | Ethereum (ERC20) | `usdt.erc20` |
| USDT | BSC (BEP20) | `usdt.bsc` |
| USDT | Polygon | `usdt.polygon` |
| USDC | 各网络 | `usdc.trc20` 等 |
| TRX | TRON | `tron.trx` |
| ETH | Ethereum | `eth.erc20` |
| 更多... | | 详见 BEpusdt 文档 |

## 安装

```bash
cd /path/to/xboard
git clone https://github.com/Shannon-x/xboard-plugin-bepusdt.git plugins/BEpusdt
```

管理后台 → 插件管理 → 安装 → 启用。

## 配置

### 1. 部署 BEpusdt

```bash
docker run -d --restart=unless-stopped -p 8080:8080 v03413/bepusdt:latest
```

在 BEpusdt 后台配置收款钱包地址。

### 2. Xboard 配置

支付配置 → 添加支付方式 → 选择 **BEpusdt** → 填写：

| 配置项 | 说明 | 示例 |
|--------|------|------|
| API 地址 | BEpusdt 网关地址 | `https://pay.example.com` |
| API Token | BEpusdt 后台的对接令牌 | `your_api_token` |
| 交易类型 | 指定币种，留空让用户自选 | `usdt.trc20` 或留空 |
| 法币类型 | 计价货币 | `CNY` |

### 3. 回调地址

在 Xboard 添加支付方式后，通知地址会自动生成。BEpusdt 的回调由 Xboard 创建订单时通过 `notify_url` 参数传递给 BEpusdt，**无需在 BEpusdt 后台额外配置回调地址**。

## 支付流程

```
用户下单 → 选择 USDT 支付
    ↓
Xboard 调用 BEpusdt create-order API
    ↓
BEpusdt 返回收银台 URL
    ↓
用户跳转收银台，选择币种/网络
    ↓
用户转账加密货币
    ↓
BEpusdt 链上确认 → 回调 Xboard notify_url
    ↓
插件验签 → OrderService::paid() → 订单完成
```

## 两种下单模式

- **指定交易类型**（填了 `trade_type`）：调用 `create-transaction`，用户直接看到收款地址和金额
- **用户自选**（`trade_type` 留空）：调用 `create-order`，用户在收银台自由选择 USDT/USDC/TRX 等币种和网络

## 签名算法

与 BEpusdt 官方文档完全一致：
1. 筛选非空、非 signature 参数
2. 按 key ASCII 升序排序
3. `key=value` 用 `&` 连接
4. 末尾追加 API Token
5. MD5 取小写

## 系统要求

- Xboard >= 1.0.0
- PHP >= 8.2
- 已部署 BEpusdt 网关

## 开源协议

MIT License
