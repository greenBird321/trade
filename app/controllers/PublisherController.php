<?php

/**
 * 发行渠道
 */
namespace MyApp\Controllers;


use MyApp\Models\Trade;
use Phalcon\Config;
use Phalcon\Mvc\Controller;
use Xt\Publisher\Publisher;
use Symfony\Component\Yaml\Yaml;
use Exception;

class PublisherController extends Controller
{

    private $publisher;     // object
    private $tradeModel;


    public function initialize()
    {
        $this->tradeModel = new Trade();
    }


    /**
     * 发行商通知回调
     * $this->dispatcher->getParam("app");
     */
    public function notifyAction()
    {
        $logger = $this->logger;
        $logger->info(urldecode(http_build_query($_REQUEST)));
        $publisher = $this->dispatcher->getParam('0', ['trim', 'lower']);
        $publisher_sub = $this->dispatcher->getParam('1', ['trim', 'lower']);


        // 配置文件
        $cfg = new Config(Yaml::parse(file_get_contents(APP_DIR . '/config/publisher.yml')));
        if (!isset($cfg->$publisher)) {
            $this->response->setJsonContent(['code' => 1, 'msg' => _('no config for the publisher')],
                JSON_UNESCAPED_UNICODE)->send();
            exit();
        }
        if ($publisher_sub) {
            if (!isset($cfg->$publisher->$publisher_sub)) {
                $this->response->setJsonContent(['code' => 1, 'msg' => _('no config for the publisher')],
                    JSON_UNESCAPED_UNICODE)->send();
                exit();
            }
            $cfgInfo = (array)$cfg->$publisher->$publisher_sub;
        }
        else {
            $cfgInfo = (array)$cfg->$publisher;
        }


        // 调用\Xt\Publisher
        try {
            $this->publisher = new Publisher($publisher, $cfgInfo);
        } catch (Exception $e) {
            $this->response->setJsonContent(['code' => 1, 'msg' => _('error publisher')],
                JSON_UNESCAPED_UNICODE)->send();
            exit();
        }
        $response = $this->publisher->notify();
        dump($response);
        exit;
        try {
            $response = $this->publisher->notify();
//            $response =  [
//                'transaction' => 'neibu',
//                'reference'   => 'waibu',
//                'amount'      => 3,      // 金额 元
//                'currency'    => 'CNY',
//                'userId'      => 'quickuid',
//                'channel_id'     => 1      // 充值渠道 ID
//            ];
            if (empty($response['transaction'])) {
                throw new Exception('error transactionId');
            }
        } catch (Exception $e) {
            $logger->error($e->getMessage());
            exit('failed');
        }


        // 获取订单信息
        $trade = $this->tradeModel->getTrade($response['transaction']);
        if (!$trade) {
            $logger->error($response['transaction'] . '|' . 'no trade info');
            exit('failed');
        }

        // 检查订单状态
        if ($trade['status'] == 'complete') {
            $this->publisher->success();
        }
        if (!in_array($trade['status'], ['pending', 'paid'])) {
            exit($trade['status']);
        }

        // 检查金额匹配 严格限制,暂不修改订单
        if ($response['amount'] != $trade['amount']) {
            $logger->error($response['transaction'] . '|' . 'amount not matching');
            exit('failed');
        }

        // 通知CP-SERVER
        $result = $this->tradeModel->noticeTo($trade, $publisher . '|' . $response['reference']);
        if (!$result) {
            exit('failed');
        }
        $this->publisher->success();
    }

    /**
     * 订单查询
     */
    public function queryAction()
    {
        $publisher = $this->dispatcher->getParam('0', ['trim', 'lower']);
        $publishers = new Publisher($publisher);
        $i = 1;

        $request = $_REQUEST;
        unset($request['_url']);
        foreach ($request as $key => $orderId){
            $order[] = $this->tradeModel->queryOrderStatus($orderId);
        }

        foreach ($order as $k => $v){
            $data = [
                'transaction' => $order[$k]['transaction'],
                'TransactionReference' => $order[$k]['gateway'],
                'status' => $order[$k]['status'],
                'userId' => $order[$k]['user_id'],
                'createTime' => $order[$k]['create_time'],
                'completeTime' => $order[$k]['complete_time']
            ];
            $response[$i] = $publishers->query($data);
            $i++;
        }
        $this->response->setJsonContent(['code' => 0, 'data' => $response],
            JSON_UNESCAPED_UNICODE)->send();
        exit();
    }
}