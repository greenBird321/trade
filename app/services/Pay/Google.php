<?php
namespace MyApp\Services\Pay;


use MyApp\Models\Trade;
use Phalcon\Mvc\Controller;
use Symfony\Component\Yaml\Yaml;

class Google extends Controller
{

    private $tradeModel;


    private $_receipt;


    private $_isSandbox = false;


    /**
     * 交易通知
     * Google 测试账号不传递订单ID
     */
    public function notify()
    {
        $this->tradeModel = new Trade();


        // 整理参数
        $app_id = $this->request->get('app_id', 'string');
        $user_id = $this->request->get('user_id', 'int');
        $custom = $this->request->get('custom', 'string');
        $ipAddress = $this->request->getClientAddress();
        if (!$user_id) {
            $jwt = $this->request->get('access_token', 'string');
            $account = $this->tradeModel->verifyAccessToken($jwt);
            if (!$account) {
                $this->response->setJsonContent(['code' => 1, 'msg' => _('access_token error')])->send();
                exit();
            }
            $user_id = $account['open_id'];
        }
        if (!$app_id || !$user_id) {
            $this->response->setJsonContent(['code' => 1, 'msg' => _('missing parameter')])->send();
            exit();
        }


        // 验证
        $response = $this->verify();
        if ($response === false) {
            // TODO :: 日志
            $this->response->setJsonContent(['code' => 1, 'msg' => _('receipt verify failed')])->send();
            exit();
        }
        $transactionReference = $response['transactionReference'];
        $product_id = $response['product_id'];


        // 检查苹果订单 TODO :: 事务或者数据库使用唯一索引防止高并发导致的问题
        $trade = $this->tradeModel->getTradeByReference('google', $transactionReference);
        if (!$trade) {
            $trade_data = [
                "app_id"      => $app_id,
                "user_id"     => $user_id,
                "gateway"     => 'google',
                "product_id"  => $product_id,
                "custom"      => $custom,
                "status"      => 'paid',
                "ip"          => $ipAddress,
                "uuid"        => '',
                "adid"        => '',
                "device"      => '',
                "channel"     => '',
                "create_time" => date('Y-m-d H:i:s') // 不使用SQL自动插入时间，避免时区不统一
            ];
            $more = [
                'trade_no'   => $transactionReference,
                'key_string' => $response['key_string'],
                'data'       => $this->_receipt,
            ];
            $trade = $this->tradeModel->createTrade($trade_data, $more);
        }
        else {
            if (in_array($trade['status'], ['complete', 'sandbox'])) {
                $this->response->setJsonContent(['code' => 0, 'msg' => _('success')])->send();
                exit();
            }
            if ($trade['status'] != 'paid') {
                $this->response->setJsonContent(['code' => 1, 'msg' => $trade['status']])->send();
                exit();
            }
        }


        // 通知厂商
        $response = $this->tradeModel->noticeTo($trade, $transactionReference);


        // 输出
        if (!$response) {
            $this->response->setJsonContent(['code' => 1, 'msg' => 'notice to CP failed'])->send();
            exit();
        }


        // 沙箱模式
        if ($this->_isSandbox) {
            $this->tradeModel->updateTradeStatus($trade['transaction'], 'sandbox');
        }


        $this->response->setJsonContent(['code' => 0, 'msg' => _('success')])->send();
        exit();
    }


    /**
     * 交易验证
     * @return array|bool
     */
    public function verify()
    {
        $this->_receipt = $this->request->get('receipt');
        $sign = $this->request->get('sign', 'string');
        $sign = str_replace(' ', '+', $sign);


        if (!$this->_receipt || !$sign) {
            return false;
        }


        $config = Yaml::parse(file_get_contents(APP_DIR . '/config/trade.yml'));
        $config = $config['google'];

        // TODO :: 多包名支持
        if (isset($config['default'])) {
            $public_key = $config['default'];
        }
        else {
            $public_key = $config;
        }


        $public_key = "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split($public_key, 64, "\n") .
            '-----END PUBLIC KEY-----';

        $pub_key_id = openssl_get_publickey($public_key);
        $signature = base64_decode($sign);
        $result = openssl_verify($this->_receipt, $signature, $pub_key_id, OPENSSL_ALGO_SHA1);
        if ($result == 1) {
            $receipt = json_decode($this->_receipt);
            $result = array(
                'transactionReference' => $receipt->orderId,
                'product_id'           => $receipt->productId,
                'key_string'           => $receipt->purchaseToken,
            );
            return $result;
        }
        elseif ($result == 0) {
            return false;
        }
        else {
            return false;
        }
    }

}