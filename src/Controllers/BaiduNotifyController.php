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
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Yansongda\Pay\Pay;

class BaiduNotifyController extends Controller
{
    public function notify($app)
    {
        $config = config('ibrand.pay.default.baidu.'.$app);

        $data =  $_POST;

        $userId     = $data['userId']; //百度用户ID
        $orderId    = $data['orderId']; //百度平台订单ID【幂等性标识参数】(用于重入判断)
        $unitPrice  = $data['unitPrice']; //单位：分
        $count      = $data['count']; //数量
        $totalMoney = $data['totalMoney']; //订单的实际金额，单位：分
        $payMoney   = $data['payMoney']; //扣除各种优惠后用户还需要支付的金额，单位：分
        $dealId     = $data['dealId']; //百度收银台的财务结算凭证
        $payTime    = $data['payTime']; //支付完成时间，时间戳
        $payType    = $data['payType']; //支付渠道值
        $partnerId  = $data['partnerId']; //支付平台标识值
        $status     = $data['status']; //1：未支付；2：已支付；-1：订单取消
        $tpOrderId  = $data['tpOrderId']; //业务方唯一订单号
        $returnData = $data['returnData']; //业务方下单时传入的数据
        $rsaSign    = $data['rsaSign']; //全部参数参与签名

        unset($data['rsaSign']); // rsaSign 不需要参与签名
        $data['sign'] = $rsaSign;
        $check_sign = $this->checkSignWithRsa($data, $config['public_key']);
        if ($check_sign){// 验签失败
            Log::info("百度支付验签: " . $check_sign);
            return 'failed';
        };

        if($status == 2) {
            // 如果订单已支付，进行业务处理并返回核销信息
            $charge = \iBrand\Component\Pay\Models\Charge::where('channel', 'baidu_cashier')->where('order_no', $tpOrderId)->first();

            if (!$charge) {
                return response('支付失败', 500);
            }

            $charge->transaction_meta = json_encode($check_sign);
            $charge->transaction_no = $dealId;
            $charge->time_paid = Carbon::createFromTimestamp($payTime);
            $charge->paid = 1;
            $charge->save();

            if ($charge->amount !== intval($totalMoney * 100)) {
                return response('支付失败', 500);
            }

            PayNotify::success($charge->type, $charge);

            // 需要返回的响应


            $ret['errno'] = 0;
            $ret['msg']   = 'success';
            $ret['data']  = json_encode(['isConsumed'=>2]);
            echo json_encode($ret);
        }

        return response('baidu notify fail.', 500);
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
