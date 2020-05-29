<?php


namespace MyApp\Controllers;


use Omnipay\Omnipay;
use Phalcon\Mvc\Dispatcher;
use MyApp\Models\Trade;
use MyApp\Services\Services;
use Symfony\Component\Yaml\Yaml;
use Xxtime\PayTime\PayTime;
use Phalcon\Logger\Adapter\File as FileLogger;

class NotifyController extends ControllerBase
{

    private $_gateway;


    private $tradeModel;


    public function initialize()
    {
        parent::initialize();
        $this->tradeModel = new Trade();
    }


    public function indexAction()
    {
    }


    /**
     * 异步通知
     * TODO :: 此处代码需要重构
     */
    public function notifyAction()
    {
        // 日志
        $logger = new FileLogger(APP_DIR . '/logs/notify' . date("Ym") . '.log');
        $uri = strpos($_SERVER['REQUEST_URI'], '?') ? substr($_SERVER['REQUEST_URI'], 0,
            strpos($_SERVER['REQUEST_URI'], '?')) : $_SERVER['REQUEST_URI'];
        $logger->info($uri . '?' . urldecode(http_build_query($_REQUEST)));
        unset($_GET['_url']); // 必须去掉_url


        /**
         * ---------------
         * 支付处理部分
         * ---------------
         */

        // 网关
        $this->_gateway = $this->dispatcher->getParam("param", 'alphanum');


        // 苹果谷歌: 平台无订单记录的充值网关单独处理
        if (in_array($this->_gateway, ['apple', 'google'])) {
            $service = Services::pay($this->_gateway);
            $service->notify();
            exit();
        }


        // TODO :: 此处暂无法判断是否测试状态
        $options = $this->getConfigOptions();
        $transactionId = null;


        /**
         * Omnipay For MyCard
         * @see https://github.com/xxtime/omnipay-mycard
         * TODO :: 购买金额验证
         */
        if ($this->_gateway == 'mycard') {
            $gateway = Omnipay::create('MyCard');
            $gateway->initialize($options);
            $notifyResponse = $gateway->acceptNotification()->send();
            $transactionId = $notifyResponse->getTransactionId();           // 平台订单ID
            $tradeData = $this->tradeModel->getTradeMore($transactionId);
            $transactionReference = $tradeData['trade_no'];                 // 网关订单ID

            // 确认
            if (!$tradeData['key_string']) {
                exit('failed');
            }
            $notifyResponse->setToken($tradeData['key_string']);
            $notifyResponse->confirm();
            if ($notifyResponse->isSuccessful()) {
                $this->tradeModel->setTradeReference($transactionId, [
                    'data' => http_build_query($notifyResponse->getData()['confirmData'])
                ]);
            }
            else {
                exit('failed');
            }

            // 兼容PayTime $response 格式
            $response = [
                'raw'                  => $notifyResponse->getData()['confirmData'],
                'transactionReference' => $transactionReference,
                'sandbox'              => false,
            ];
        }
        /**
         * PayTime
         * @see https://github.com/xxtime/paytime
         */
        else {
            $payTime = new PayTime(ucfirst($this->_gateway));
            $payTime->setOption($options);
            $response = $payTime->notify();

            // 网关支付结果处理
            if (!$response['isSuccessful']) {
                if (!isset($response['transactionId'])) {
                    $error_log = $response['message'];
                }
                else {
                    $error_log = $response['transactionId'] . '|' . $response['message'];
                }
                $logger->error($error_log);
                $logger->close();
                exit('failed');
            }
            $transactionId = $response['transactionId'];
        }


        /**
         * ---------------
         * 业务处理部分
         * ---------------
         */

        // 获取订单信息
        $trade = $this->tradeModel->getTrade($transactionId);
        if (!$trade) {
            $logger->error($transactionId . '|' . 'no trade info');
            $logger->close();
            exit('failed');
        }
        $logger->close();


        // 检查订单状态
        if ($trade['status'] == 'complete') {
            exit('success');
        }
        if (!in_array($trade['status'], ['pending', 'paid'])) {
            exit($trade['status']);
        }


        // 卡支付判断【预付卡或者电信支付】
        if ($trade['amount'] == 0 || $trade['product_id'] == '') {
            if (empty($response['amount']) || empty($response['currency'])) {
                exit('failed');
            }
            // 更新订单额度
            $trade_modify_data = $this->tradeModel->updateTradeAmount(
                $trade['app_id'],
                $transactionId,
                ['gateway' => $this->_gateway, 'amount' => $response['amount'], 'currency' => $response['currency']]
            );
            if (!$trade_modify_data) {
                exit('cant find product');
            }
            $trade = array_merge($trade, $trade_modify_data);
        }


        // 通知CP-SERVER
        $raw = isset($response['raw']) ? $response['raw'] : '';
        $result = $this->tradeModel->noticeTo($trade, $response['transactionReference'], $raw);


        // 输出
        if ($result) {
            // 检查沙箱
            if (!empty($response['sandbox']) && $response['sandbox'] === true) {
                $this->tradeModel->updateTradeStatus($transactionId, 'sandbox');
            }
            $payTime->success();

            exit('success');
        }

        exit('notice to cp failed');
    }


    /**
     * 获取配置选项
     * @param int $sandbox
     * @return mixed
     * @throws \Exception
     */
    private function getConfigOptions($sandbox = 0)
    {
        if (!$sandbox) {
            $config = Yaml::parse(file_get_contents(APP_DIR . '/config/trade.yml'));
        }
        else {
            try {
                $config = Yaml::parse(file_get_contents(APP_DIR . '/config/sandbox.trade.yml'));
            } catch (\Exception $e) {
                throw new \Exception('can`t find file sandbox.trade.yml');
            }
        }

        if (!isset($config[$this->_gateway])) {
            throw new \Exception('no config about the gateway');
            exit();
        }

        if (isset($config[$this->_gateway][$this->_app])) {
            $options = $config[$this->_gateway][$this->_app];
        }
        else {
            $options = $config[$this->_gateway];
        }

        return $this->tradeModel->getFullPath($options);
    }

}