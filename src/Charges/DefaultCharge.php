<?php

/*
 * This file is part of ibrand/pay.
 *
 * (c) 果酱社区 <https://guojiang.club>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace iBrand\Component\Pay\Charges;

use Carbon\Carbon;
use iBrand\Component\Pay\Contracts\PayChargeContract;
use iBrand\Component\Pay\Exceptions\GatewayException;
use iBrand\Component\Pay\Models\Charge;
use Yansongda\Pay\Exceptions\GatewayException as PayException;
use Yansongda\Pay\Pay;

class DefaultCharge extends BaseCharge implements PayChargeContract
{
    /**
     * 创建支付请求
     *
     * @param array  $data   支付数据
     * @param string $type   业务类型
     * @param string $app    支付参数APP，config payments 数组中的配置项名称
     * @param Charge $charge model.
     *
     * @return mixed
     *
     * @throws GatewayException
     */
    public function create(array $data, $type = 'default', $app = 'default', Charge $charge = null)
    {
        $this->validateParams($data);

        if (is_null($charge) && !in_array($data['channel'], ['wx_pub', 'wx_pub_qr', 'wx_lite', 'alipay_wap', 'alipay_pc_direct', 'wx_app', 'wx_wap', 'offline_pay', 'baidu_cashier'])) {
            throw new \InvalidArgumentException("Unsupported channel [{$data['channel']}]");
        }

        if (is_null($charge)) {
            $charge = Charge::where('order_no', $data['order_no'])->where('paid', true)->first();

            if ($charge) {
                throw new GatewayException('订单：'.$data['order_no'].'已支付');
            }

            $modelData = array_merge(['app' => $app, 'type' => $type], array_only($data, ['channel', 'order_no', 'client_ip', 'subject', 'amount', 'body', 'extra', 'time_expire', 'metadata', 'description']));
            $payModel = Charge::create($modelData);
        } else {
            $payModel = $charge;
            $data = $charge->toArray();
        }

        try {
            $credential = null;
            $out_trade_no = null;
            switch ($data['channel']) {
                case 'wx_pub':
                case 'wx_pub_qr':
                case 'wx_lite':
                case 'wx_app':
                case 'wx_wap':
                    $config = config('ibrand.pay.default.wechat.'.$app);
                    $config['notify_url'] = route('pay.wechat.notify', ['app' => $app]);
                    $credential = $this->createWechatCharge($data, $config, $out_trade_no);
                    break;
                case 'alipay_wap':
                case 'alipay_pc_direct':
                    $config = config('ibrand.pay.default.alipay.'.$app);
                    $config['notify_url'] = route('pay.alipay.notify', ['app' => $app]);
                    $credential = $this->createAlipayCharge($data, $config, $out_trade_no);
                    break;
                case 'baidu_cashier':
                    $config = config('ibrand.pay.default.baidu.'.$app);
                    $credential = $this->createBaiduCharge($data, $config);
            }

            $payModel->credential = $credential;
            $payModel->out_trade_no = $out_trade_no;
            $payModel->save();

            return $payModel;
        } catch (\Yansongda\Pay\Exceptions\Exception $exception) {
            \Log::info($exception->getMessage());

            throw  new GatewayException('支付通道错误');
        }
    }

    /**
     * @param $data
     * @param $config
     *
     * @return array|null
     */
    protected function createWechatCharge($data, $config, &$out_trade_no)
    {
        $out_trade_no = $this->getOutTradeNo($data['order_no'], $data['channel']);

        $chargeData = [
            'body' => mb_strcut($data['body'], 0, 32, 'UTF-8'),
            'out_trade_no' => $out_trade_no,
            'total_fee' => abs($data['amount']),
            'spbill_create_ip' => $data['client_ip'],
        ];

        if (isset($data['time_expire'])) {
            $chargeData['time_expire'] = $data['time_expire'];
        }

        if (isset($data['metadata'])) {
            $chargeData['attach'] = json_encode($data['metadata']);
        }

        switch ($data['channel']) {
            case 'wx_pub_qr':
                $pay = Pay::wechat($config)->scan($chargeData);

                return ['wechat' => $pay];
            case 'wx_wap':
                $mweb_url = Pay::wechat($config)->wap($chargeData)->getTargetUrl();
                $pay = sprintf("%s&redirect_url=%s", $mweb_url,  urlencode($data['extra']['successUrl']));
//                $pay = $this->getDeeplink($mweb_url, $chargeData['spbill_create_ip']);

                return ['wechat' => $pay];
            case 'wx_pub':
                $chargeData['openid'] = $data['extra']['openid'];
                $pay = Pay::wechat($config)->mp($chargeData);

                return ['wechat' => $pay];

            case 'wx_lite':
                $chargeData['openid'] = $data['extra']['openid'];
                $pay = Pay::wechat($config)->miniapp($chargeData);

                return ['wechat' => $pay];
	        case 'wx_app':
		        $pay = Pay::wechat($config)->app($chargeData);

		        return ['wechat' => json_decode($pay->getContent(), true)];
            default:
                return null;
        }
    }

    public function createAlipayCharge($data, $config, &$out_trade_no)
    {
        $out_trade_no = $this->getOutTradeNo($data['order_no'], $data['channel']);

        $chargeData = [
            'body' => mb_strcut($data['body'], 0, 32, 'UTF-8'),
            'out_trade_no' => $out_trade_no,
            'total_amount' => number_format($data['amount'] / 100, 2, '.', ''),
            'subject' => mb_strcut($data['subject'], 0, 32, 'UTF-8'),
            'client_ip' => $data['client_ip'],
        ];

        if (isset($data['time_expire']) && ($gap = strtotime($data['time_expire']) - Carbon::now()->timestamp) > 0) {
            $chargeData['timeout_express'] = floor($gap / 60).'m';
        }

        if (isset($data['metadata'])) {
            $chargeData['passback_params'] = json_encode($data['metadata']);
        }

        if (isset($data['extra']['failUrl'])) {
            $chargeData['quit_url'] = $data['extra']['failUrl'];
        }

        if (isset($data['extra']['successUrl'])) {
            $chargeData['success_url'] = $data['extra']['successUrl'];
            $config['return_url'] = $data['extra']['successUrl'];
        }

        if ('alipay_pc_direct' == $data['channel']) {
            $ali_pay = Pay::alipay($config)->web($chargeData);

            return [
                'alipay' => html_entity_decode($ali_pay),
            ];
        }

        if ('alipay_wap' == $data['channel']) {
            $ali_pay = Pay::alipay($config)->wap($chargeData);

            return [
                'alipay' => html_entity_decode($ali_pay),
            ];
        }
    }

    public function find($charge_id)
    {
        $charge = Charge::where('charge_id', $charge_id)->first();

        $config = config('ibrand.pay.default.wechat.'.$charge->app);

        $order = [
            'out_trade_no' => $charge->out_trade_no,
        ];

        if ('wx_lite' == $charge->channel) {
            $order['type'] = 'miniapp';
        }

        if ('alipay_pc_direct' == $charge->channel || 'alipay_wap' == $charge->channel) {
            $config = config('ibrand.pay.default.alipay.'.$charge->app);

            $result = Pay::alipay($config)->find($order);

            if ('TRADE_SUCCESS' == $result['trade_status'] || 'TRADE_FINISHED' == $result['trade_status']) {
                $charge->transaction_meta = json_encode($result);
                $charge->transaction_no = $result['trade_no'];
                $charge->time_paid = Carbon::now();
                $charge->paid = 1;
                $charge->save();
            } else {
                $charge->transaction_meta = json_encode($result);
                $charge->save();
            }

            return $charge;
        }

        try {
            $result = Pay::wechat($config)->find($order);
            $charge->transaction_meta = json_encode($result);
            $charge->transaction_no = $result['transaction_id'];
            $charge->time_paid = Carbon::createFromTimestamp(strtotime($result['time_end']));
            $charge->paid = 1;
            $charge->save();

            return $charge;
        } catch (PayException $exception) {
            $result = $exception->raw;
            if ('FAIL' == $result['return_code']) {
                $charge->failure_code = $result['return_code'];
                $charge->failure_msg = $result['return_msg'];
                $charge->save();

                return $charge;
            }

            if ('FAIL' == $result['result_code'] || 'SUCCESS' != $result['trade_state']) {
                $charge->failure_code = $result['err_code'];
                $charge->failure_msg = $result['err_code_des'];
                $charge->save();
            }
        }
    }

    public function createBaiduCharge($data, $config)
    {
        $chargeData = [
            'appKey' => $config['app_key'],
            'dealId' => $config['deal_id'],
            'body' => mb_strcut($data['body'], 0, 32, 'UTF-8'),
            'tpOrderId' => $data['order_no'],
            'totalAmount' => number_format($data['amount'] / 100, 2, '.', ''),
            'dealTitle' => mb_strcut($data['subject'], 0, 32, 'UTF-8'),
            'client_ip' => $data['client_ip'],
            'signFieldsRange' => 1,
        ];
        $chargeData['rsaSign'] = $this->genSignWithRsa($chargeData,$config['private_key']);

//        $bizInfoArr = [
//            "tpData" => [
//                "appKey" => $chargeData['appKey'],
//                "dealId" => $chargeData['dealId'],
//                "tpOrderId" => $chargeData['tpOrderId'],
//                "rsaSign" =>  $chargeData['rsaSign'],
//                "totalAmount" => $chargeData['totalAmount'],
//                "returnData"=> [
//                    "bizKey1"=> "第三方的字段1取值",
//                    "bizKey2"=> "第三方的字段2取值"
//                ]
//            ],
//        ];
//        $chargeData['bizInfo'] = json_encode($bizInfoArr); // 订单详细信息，需要是一个可解析为JSON Object的字符串 可以为空 {}
        $chargeData['bizInfo'] = '{}'; // 订单详细信息，需要是一个可解析为JSON Object的字符串 可以为空 {}

        return ['baidu' => $chargeData];
    }

    /**
     * 获取微信支付中间页deepLink参数
     * @param string $url 微信返回的mweb_url
     * @param string $ip 用户端IP
     * @return false|string
     */
    private function getDeeplink(string $url, string $ip)
    {
        $headers = array("X-FORWARDED-FOR:$ip", "CLIENT-IP:$ip");
        ob_start();
        $ch = curl_init();
        curl_setopt ($ch, CURLOPT_URL, $url);
        curl_setopt ($ch, CURLOPT_HTTPHEADER , $headers );
        curl_setopt ($ch, CURLOPT_REFERER, "m.foridom.com.cn");
        curl_setopt( $ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Linux; Android 6.0.1; OPPO R11s Build/MMB29M; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/55.0.2883.91 Mobile Safari/537.36');
        curl_exec($ch);
        curl_close ($ch);
        $out = ob_get_contents();

        ob_clean();
        $a = preg_match('/weixin:\/\/wap.*/',$out, $str);
        if ($a) {
            return substr($str[0], 0, strlen($str[0])-1);
        } else {
            return '';
        }
    }




}
