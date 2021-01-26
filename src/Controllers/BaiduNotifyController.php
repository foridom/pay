<?php

/*
 * This file is part of ibrand/pay.
 *
 * (c) 果酱社区 <https://guojiang.club>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace iBrand\Component\Pay\Controllers;

use Carbon\Carbon;
use iBrand\Component\Pay\Facades\PayNotify;
use iBrand\Component\Pay\Models\BaiduPay;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Yansongda\Pay\Pay;

class BaiduNotifyController extends Controller
{
    public function notify($app)
    {
        $config = config('ibrand.pay.default.baidu.'.$app);

        $baidupay = new BaiduPay($config);

        $result = $baidupay->notify();
        if ($result) {
            // 这里回调处理订单操作
            // 如果订单已支付，进行业务处理并返回核销信息
            $charge = \iBrand\Component\Pay\Models\Charge::where('channel', 'baidu_cashier')->where('order_no', $result['tpOrderId'])->orderByDesc('created_at')->first();

            if (!$charge) {
                return response('支付失败', 500);
            }

            $charge->transaction_meta = json_encode($result);
            $charge->transaction_no = $result['orderId'];
            $charge->time_paid = Carbon::createFromTimestamp($result['payTime']);
            $charge->paid = 1;
            $charge->save();

            if ($charge->amount !== (int)($result['totalMoney'])) {
                return response('支付失败', 500);
            }

            PayNotify::success($charge->type, $charge);

            // 以验证返回支付成功后的信息，可直接对订单进行操作，已通知百度支付成功
            $baidupay->success(); // 支付返还成功，通知结果
        } else {
            // 支付失败
            $baidupay->error(); // 支付失败，返回状态（无论支付成功与否都需要通知百度）
        }
    }

    /**
     * 公钥校验签名
     * @param array $assocArr
     * @param bool $rsaPubKeyStr
     * @return bool
     */
    private function checkSignWithRsa(array $assocArr ,$rsaPubKeyStr = true){
        if (!isset($assocArr['sign']) || empty($assocArr) || empty($rsaPubKeyStr)) {
            return false;
        }

        $sign = $assocArr['sign'];
        unset($assocArr['sign']);

        if (empty($assocArr)) {
            return false;
        }

        ksort($assocArr); //按字母升序排序
        $parts = array();
        foreach ($assocArr as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        $str    = implode('&', $parts);
        $sign   = base64_decode($sign);
        $pubKey = $rsaPubKeyStr;  // 公钥串
        $pubKey = chunk_split($pubKey, 64, "\n");
        $pubKey = "-----BEGIN PUBLIC KEY-----\n$pubKey-----END PUBLIC KEY-----\n";
        $result = (bool)openssl_verify($str, $sign,$pubKey);
        return $result;
    }

}
