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

use Hidehalo\Nanoid\Client;
use iBrand\Component\Pay\Contracts\PayChargeContract;

abstract class BaseCharge implements PayChargeContract
{
    protected function getOutTradeNo($order_sn, $channel)
    {
        switch ($channel) {
            case 'wx_pub':
            case 'wx_pub_qr':
            case 'wx_lite':
            case 'wx_wap':
                $client = new Client();

                return 'wx_'.$client->formatedId('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz-', 24);
            default:
                $client = new Client();

                return 'ali_'.$client->formatedId('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz-', 24);
        }
    }

    protected function validateParams($params = null)
    {
        if ($params && !is_array($params)) {
            $message = 'You must pass an array as the first argument to pay API '
                .'method calls.';
            throw new \InvalidArgumentException($message);
        }
    }

    /**
     * 私钥生成签名字符串
     * @param array $assocArr
     * @param $priKey
     * @param bool $rsaPriKeyStr
     * @return string
     */
    protected function genSignWithRsa(array $assocArr,$priKey, $rsaPriKeyStr = true){
        $sign = '';
        if (empty($rsaPriKeyStr) || empty($assocArr)) {
            return $sign;
        }
        $priKey = chunk_split($priKey, 64, "\n");
        $priKey = "-----BEGIN RSA PRIVATE KEY-----\n$priKey-----END RSA PRIVATE KEY-----\n";
        if (isset($assocArr['sign'])) {
            unset($assocArr['sign']);
        }
        ksort($assocArr); //按字母升序排序
        $parts = array();
        foreach ($assocArr as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        $str = implode('&', $parts);
        openssl_sign($str, $sign, $priKey);

        return base64_encode($sign);
    }
}
