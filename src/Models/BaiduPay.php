<?php

namespace iBrand\Component\Pay\Models;

class BaiduPay
{
    private static $config = array(
        'deal_id'       => '', // 百度收银台的财务结算凭证
        'app_key'       => '', // 表示应用身份的唯一ID
        'private_key'   => '', // 私钥原始字符串
        'public_key'    => '', // 平台公钥
        'notify_url'    => '', // 支付回调地址
    );

    /**
     * [__construct 构造函数]
     * @param [type] $config [传递支付相关配置]
     */
    public function __construct($config=NULL){
        $config && self::$config = $config;
    }

    /**
     * [xcxPay 百度小程序支付]
     * @param  [type]  $order [订单信息数组]
     * @return [type]         [description]
     * $order = array(
     *      'body'          => '', // 产品描述
     *      'total_amount'  => '', // 订单金额（分）
     *      'order_sn'      => '', // 订单编号
     * );
     */
    public static function xcxPay($order)
    {
        if(!is_array($order) || count($order) < 3)
            die("数组数据信息缺失！");

        $config = self::$config;
        $requestParamsArr = array(
            'appKey'    => $config['app_key'],
            'dealId'    => $config['deal_id'],
            'tpOrderId' => $order['order_sn'],
            'totalAmount' => $order['total_amount'],
        );
        $rsaSign = self::makeSign($requestParamsArr, $config['private_key']);  // 声称百度支付签名
        $bizInfo = array(
            'tpData' => array(
                "appKey"        => $config['app_key'],
                "dealId"        => $config['deal_id'],
                "tpOrderId"     => $order['order_sn'],
                "rsaSign"       => $rsaSign,
                "totalAmount"   => $order['total_amount'],
                "returnData"    => '',
                "displayData"   => '',
                "dealTitle"     => $order['body'],
                "dealSubTitle"  => $order['body'],
                "dealThumbView" => "https://b.bdstatic.com/searchbox/icms/searchbox/img/swan-logo.png",
            ),
            "orderDetailData"   => ''
        );

        $bdOrder = array(
            'dealId'        => $config['deal_id'],
            'appKey'        => $config['app_key'],
            'totalAmount'   => $order['total_amount'],
            'tpOrderId'     => $order['order_sn'],
            'dealTitle'     => $order['body'],
            'signFieldsRange' => 1,
            'rsaSign'       => $rsaSign,
            'bizInfo'       => json_encode($bizInfo),
        );
        return $bdOrder;
    }

    /**
     * [refund baidu支付退款]
     * @param  [type] $order [订单信息]
     * @param  [type] $type  [退款类型]
     * $order = array(
     *      'body'          => '', // 退款原因
     *      'total_amount'  => '', // 退款金额（分）
     *      'order_sn'      => '', // 订单编号
     *      'access_token'  => '', // 获取开发者服务权限说明
     *      'order_id'      => '', // 百度收银台订单 ID
     *      'user_id'       => '', // 百度收银台用户 id
     * );
     */
    public static function refund($order=[], $type=1)
    {
        $config = self::$config;

        $data = array(
            'access_token'      => $order['access_token'], // 获取开发者服务权限说明
            'applyRefundMoney'  => $order['total_amount'], // 退款金额，单位：分。
            'bizRefundBatchId'  => $order['order_sn'], // 开发者退款批次
            'isSkipAudit'       => 1, // 是否跳过审核，不需要百度请求开发者退款审核请传 1，默认为0； 0：不跳过开发者业务方审核；1：跳过开发者业务方审核。
            'orderId'           => $order['order_id'], // 百度收银台订单 ID
            'refundReason'      => $order['body'], // 退款原因
            'refundType'        => $type, // 退款类型 1：用户发起退款；2：开发者业务方客服退款；3：开发者服务异常退款。
            'tpOrderId'         => $order['order_sn'], // 开发者订单 ID
            'userId'            => $order['user_id'], // 百度收银台用户 id
        );

        $array = ['errno'=>0, 'msg'=>'success', 'data'=> ['isConsumed'=>2] ];

        $url = 'https://openapi.baidu.com/rest/2.0/smartapp/pay/paymentservice/applyOrderRefund';
        $response = self::post_curl($url, $data);
        $result = json_decode($response, true);
        // // 显示错误信息
        // if ($result['msg']!='success') {
        //     return false;
        //     // die($result['msg']);
        // }
        return $result;
    }

    /**
     * [notify 回调验证]
     * @return [array] [返回数组格式的notify数据]
     */
    public static function notify()
    {
        $data = request()->all(); // 获取xml
        $config = self::$config;
        if (!$data || empty($data['rsaSign']))
            die('暂无回调信息');

        $result = self::checkSign($data, $config['public_key']); // 进行签名验证
        // 判断签名是否正确  判断支付状态
        if ($result && $data['status']==2) {
            return $data;
        } else {
            return false;
        }
    }

    /**
     * [success 通知支付状态]
     */
    public static function success()
    {
        $array = ['errno'=>0, 'msg'=>'success', 'data'=> ['isConsumed'=>2] ];
        die(json_encode($array));
    }

    /**
     * [error 通知支付状态]
     */
    public static function error()
    {
        $array = ['errno'=>0, 'msg'=>'success', 'data'=> ['isErrorOrder'=>1, 'isConsumed'=>2] ];
        die(json_encode($array));
    }

    /**
     * [makeSign 使用私钥生成签名字符串]
     * @param  array  $assocArr     [入参数组]
     * @param  [type] $rsaPriKeyStr [私钥原始字符串，不含PEM格式前后缀]
     * @return [type]               [签名结果字符串]
     */
    public static function makeSign(array $assocArr, $rsaPriKeyStr)
    {
        $sign = '';
        if (empty($rsaPriKeyStr) || empty($assocArr)) {
            return $sign;
        }

        if (!function_exists('openssl_pkey_get_private') || !function_exists('openssl_sign')) {
            throw new Exception("openssl扩展不存在");
        }

        $rsaPriKeyPem = self::convertRSAKeyStr2Pem($rsaPriKeyStr, 1);

        $priKey = openssl_pkey_get_private($rsaPriKeyPem);

        if (isset($assocArr['sign'])) {
            unset($assocArr['sign']);
        }

        ksort($assocArr); // 参数按字典顺序排序

        $parts = array();
        foreach ($assocArr as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        $str = implode('&', $parts);

        openssl_sign($str, $sign, $priKey);
        openssl_free_key($priKey);

        return base64_encode($sign);
    }

    /**
     * [checkSign 使用公钥校验签名]
     * @param  array  $assocArr     [入参数据，签名属性名固定为rsaSign]
     * @param  [type] $rsaPubKeyStr [公钥原始字符串，不含PEM格式前后缀]
     * @return [type]               [验签通过|false 验签不通过]
     */
    public static function checkSign(array $assocArr, $rsaPubKeyStr)
    {
        if (!isset($assocArr['rsaSign']) || empty($assocArr) || empty($rsaPubKeyStr)) {
            return false;
        }

        if (!function_exists('openssl_pkey_get_public') || !function_exists('openssl_verify')) {
            throw new Exception("openssl扩展不存在");
        }

        $sign = $assocArr['rsaSign'];
        unset($assocArr['rsaSign']);

        if (empty($assocArr)) {
            return false;
        }

        ksort($assocArr); // 参数按字典顺序排序

        $parts = array();
        foreach ($assocArr as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        $str = implode('&', $parts);

        $sign = base64_decode($sign);

        $rsaPubKeyPem = self::convertRSAKeyStr2Pem($rsaPubKeyStr);

        $pubKey = openssl_pkey_get_public($rsaPubKeyPem);

        $result = (bool)openssl_verify($str, $sign, $pubKey);
        openssl_free_key($pubKey);

        return $result;
    }

    /**
     * [convertRSAKeyStr2Pem 将密钥由字符串（不换行）转为PEM格式]
     * @param  [type]  $rsaKeyStr [原始密钥字符串]
     * @param  integer $keyType   [0 公钥|1 私钥，默认0]
     * @return [type]             [PEM格式密钥]
     */
    public static function convertRSAKeyStr2Pem($rsaKeyStr, $keyType = 0)
    {
        $pemWidth = 64;
        $rsaKeyPem = '';

        $begin = '-----BEGIN ';
        $end = '-----END ';
        $key = ' KEY-----';
        $type = $keyType ? 'RSA PRIVATE' : 'PUBLIC';

        $keyPrefix = $begin . $type . $key;
        $keySuffix = $end . $type . $key;

        $rsaKeyPem .= $keyPrefix . "\n";
        $rsaKeyPem .= wordwrap($rsaKeyStr, $pemWidth, "\n", true) . "\n";
        $rsaKeyPem .= $keySuffix;

        if (!function_exists('openssl_pkey_get_public') || !function_exists('openssl_pkey_get_private')) {
            return false;
        }

        if ($keyType == 0 && false == openssl_pkey_get_public($rsaKeyPem)) {
            return false;
        }

        if ($keyType == 1 && false == openssl_pkey_get_private($rsaKeyPem)) {
            return false;
        }

        return $rsaKeyPem;
    }

    /**
     * curl post请求
     * @param string $url 地址
     * @param string $postData 数据
     * @param array $header 头部
     * @return bool|string
     * @Date 2020/9/17 17:12
     * @Author wzb
     */
    public static function post_curl($url='',$postData='',$header=[]){
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5000);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5000);

        if($header){
            curl_setopt($ch, CURLOPT_HTTPHEADER,$header);
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        return $result;
    }

}