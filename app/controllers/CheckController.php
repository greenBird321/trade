<?php


namespace MyApp\Controllers;


use Omnipay\Omnipay;
use Phalcon\Mvc\Dispatcher;
use MyApp\Models\Trade;
use Symfony\Component\Yaml\Yaml;

class CheckController extends ControllerBase
{


    protected $_gateway;


    protected $tradeModel;


    public function initialize()
    {
        parent::initialize();
        $this->tradeModel = new Trade();
    }

    public function indexAction()
    {
        $gatewayName = $this->_gateway = $this->dispatcher->getParam("gateway");
        if ($gatewayName != 'mycard') {
            exit();
        }

        $config = $this->getConfigOptions();
        $gateway = Omnipay::create('MyCard');
        $gateway->initialize($config);
        $compare = $gateway->compareTransaction();


        // Get Params, Exp: ["card"=>"MC123456"] or ["startTime"=>1500000000,"endTime"=>1560000000];
        $data = $this->tradeModel->getMyCardCheckData($compare->getParams());
        $compare->setData($data)->send();
    }


    private function getConfigOptions($sandbox = 0)
    {
        if (!$sandbox) {
            $config = Yaml::parse(file_get_contents(APP_DIR . '/config/trade.yml'));
        }
        else {
            try {
                $config = Yaml::parse(file_get_contents(APP_DIR . '/config/sandbox.trade.yml'));
            } catch (Exception $e) {
                throw new Exception(_('no config'));
            }
        }

        if (!isset($config[$this->_gateway])) {
            throw new Exception(_('no config'));
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