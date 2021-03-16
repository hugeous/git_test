<?php
namespace Controller\Front\Test;

use Globals;
use Session;
use Response;
use Request;

/**
 * 테스트용
 */
class TestController extends \Controller\Front\Controller
{
    /**
     * {@inheritdoc}
     */
    public function index()
    {
        $setData = 'Hello World !!!';
        $this->setData('setData', $setData);
    }
}
