# BEpusdt 加密货币支付插件

为 [Xboard](https://github.com/cedar2025/Xboard) 对接 [BEpusdt](https://github.com/v03413/BEpusdt) 加密货币收款网关。

## 功能特点

- 多链多币种收款：USDT、USDC、TRX、ETH 等
- 两种下单模式：指定币种直付 / 收银台让用户自选
- 自动汇率换算（CNY/USD/EUR/GBP/JPY）
- 出站 HTTP 请求带指数退避重试（Octane 友好的 `usleep`）
- 入站回调全程诊断日志（IP/UA/order_id/trade_id），出问题秒级定位
- 时序安全的签名比较（`hash_equals`）

## 支持的加密货币

BEpusdt 支持多链多币种，常用值：

| 币种 | 网络 | `trade_type` |
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
git clone https://github.com/Shannon-x/xboard-plugin-bepusdt.git plugins/Bepusdt
```

管理后台 → 插件管理 → 安装 → 启用。

## 配置

### 1. 部署 BEpusdt

```bash
docker run -d --restart=unless-stopped -p 8080:8080 v03413/bepusdt:latest
```

在 BEpusdt 后台配置收款钱包地址。

### 2. Xboard 添加支付方式

支付配置 → 添加支付方式 → 选择 **BEpusdt** → 填写：

| 配置项 | 说明 | 示例 |
|--------|------|------|
| API 地址 | BEpusdt 网关地址 | `https://pay.example.com` |
| API Token | BEpusdt 后台的对接令牌 | `your_api_token` |
| 交易类型 | 指定币种，留空让用户自选 | `usdt.trc20` 或留空 |
| 法币类型 | 计价货币 | `CNY` |
| 请求超时（秒） | 出站 HTTP 超时 | `30` |

### 3. ⚠️ 关键：检查"通知域名"字段

支付配置的编辑页里，除了插件本身的字段，**Xboard 框架还有一个内置的"通知域名（notify_domain）"字段**——它决定了 BEpusdt 回调通知到哪个域名。

- **单域名部署**（前端、后端都用同一个域名）：可以留空，回退使用站点 URL
- **前后端域名分离部署**（例如站点 URL 是面向用户的前端域名 `https://www.example.com`，后端 API 在另一个域名 `https://api.example.com`）：**必须填入后端 API 的完整地址** `https://api.example.com`，否则回调会被发到前端域名 → 404 → 订单永远停在待支付状态

> 💡 这是本插件最容易踩坑的配置！详见下方[常见故障排查](#常见故障排查)。

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

1. 筛选非空、非 `signature` 参数
2. 按 key ASCII 升序排序
3. `key=value` 用 `&` 连接
4. 末尾追加 API Token（无 `&`）
5. MD5 取小写

回调验签使用 `hash_equals()` 做时序安全比较。

---

## 常见故障排查

### ❌ 用户付款成功，订单永远是"待支付"，Xboard 日志里完全看不到回调记录

**症状**

- 日志里只有 `[BEpusdt] 订单创建成功` 这类出站请求
- 完全没有 `[BEpusdt] 支付成功回调` / `[BEpusdt] 签名验证失败` / `[BEpusdt] 回调缺少签名` 任何一条入站记录
- 多笔 USDT 订单到期被取消，但用户截图链上交易确实成功

**根因（99% 是这个）**

支付方式的"通知域名（`notify_domain`）"字段没填，导致 BEpusdt 回调被发到了错误的域名（通常是 Xboard 后台的"站点 URL"，而这个 URL 在前后端分离部署下指向前端域名，前端 nginx 不路由 `/api/...` → 404 → 请求根本进不了 Xboard 应用）。

**诊断步骤**

1. 后台 → 支付配置，看 BEpusdt 那条"通知地址"列实际显示的 URL
2. 在服务器上 `curl` 一下这个 URL：

   ```bash
   curl -i -X POST -H 'Content-Type: application/json' -d '{"signature":"x"}' \
     '<复制下来的通知地址>'
   ```

   - 返回 **HTTP 422** + JSON body `{"status":"fail","message":"verify error"}` → 路由正常，问题不在这里
   - 返回 **HTTP 404** + nginx 默认页 → **域名错了**，按下面修复

**修复**

方案 A（推荐，零代码）：管理后台 → 支付配置 → 编辑 BEpusdt → 填"通知域名"为后端 API 的完整地址（如 `https://api.example.com` 或 `https://your-backend-domain.com`）→ 保存。

方案 B（直接改库）：

```sql
UPDATE v2_payment 
   SET notify_domain = 'https://your-backend-domain.com',
       updated_at    = UNIX_TIMESTAMP()
 WHERE payment = 'BEpusdt';
```

> **同步排查**：这个坑对所有支付方式都成立。如果你的 Xboard 上还有 Stripe、PayPal 等其它支付方式没填 `notify_domain`，它们的回调可能也早就坏了，只是用户少没人反馈。建议一次性把所有支付方式的"通知域名"都核对一遍。

### ❌ 创建订单时 BEpusdt 网关超时

**症状**

```
[BEpusdt] HTTP 请求失败 {"error":"Operation timed out after 30002 milliseconds..."}
[BEpusdt] 请求失败，准备重试 {"attempt":1,...}
```

**可能原因**

- BEpusdt 网关本身慢或挂了 → 看 BEpusdt 服务自身的状态
- 出站网络抖动 → 插件已经带重试（最多 3 次，指数退避，上限 3 秒），通常会自愈
- SSL 证书问题（`unable to get local issuer certificate`）→ 插件已设 `CURLOPT_SSL_VERIFYPEER=false` 兜底；长期建议在容器内补 `ca-certificates`

**调整**

后台编辑 BEpusdt 支付方式 → 调高"请求超时（秒）"。默认 30 秒，最低 10 秒。

### ❌ 回调签名验证失败

**症状**

```
[BEpusdt] 签名验证失败 {"ip":"...","received":"...","expected":"..."}
```

**排查**

1. 确认 Xboard 这边填的 API Token 与 BEpusdt 后台"系统管理 → 基本设置 → API 设置 → 对接令牌"完全一致（区分大小写、不要带前后空格）
2. 如果是金额带尾零的订单（如 `100.00`、`28.80`），Go 端浮点字符串化与 PHP 可能不一致——但 BEpusdt 主流版本会避免，正常付款不会触发
3. 抓 raw body 看实际收到的字段：日志里 `received` 是回调来的签名，`expected` 是插件用本地 token 算的签名；两边都贴出来对比

## 系统要求

- Xboard >= 1.0.0
- PHP >= 8.2
- 已部署 BEpusdt 网关

## 更新日志

### v1.1.0

- 新增：入站回调全程诊断日志（IP / UA / order_id / trade_id），出问题秒级定位
- 新增：创建订单成功也记录一条 INFO 日志（含最终 `notify_url`），便于回调失败时排查域名
- 新增：Content-Type 异常时回退解析 raw body，避免反代改 header 导致静默失败
- 改进：签名比较改为 `hash_equals()`，防 MD5 时序侧信道
- 改进：`callback_no` 加 `trade_id` 缺失兜底，回退到 `block_transaction_id`
- 改进：`sleep()` → `usleep()` 上限 3 秒，避免在 Octane 下长时间阻塞 worker
- 改进：`ksort` 显式 `SORT_STRING`，与 BEpusdt Go 端 `sort.Strings` 行为对齐
- 改进：签名输入丢弃数组类型 value，防御嵌套结构导致的字符串拼接错误
- 文档：README 加入"常见故障排查"章节，重点解释 `notify_domain` 配置陷阱

### v1.0.0

- 首版发布，支持 BEpusdt 全部下单与回调流程
- HTTP 重试机制（最多 3 次指数退避）
- 双下单模式（`create-transaction` / `create-order`）

## 开源协议

MIT License
