<?php

/**
 */

namespace Widget\Front\Proc;

use Framework\Utility\SkinUtils;

class TimesaleTitleWidget extends \Widget\Front\Widget
{
    public function index()
    {
        $goods = \App::load('\\Component\\Goods\\Goods');
        $timeSale = \App::load('\\Component\\Promotion\\TimeSale');
        $timeSaleInfo = $timeSale->getInfoTimeSale();
        $timeSaleList = $timeSale->getListTimeSale();
        // var_dump($timeSaleList);
        var_dump($timeSaleInfo);
        // var_dump($timeSaleInfo['pcDescription']);

        usort($timeSaleInfo, function($a, $b){
            return $a['endDt'] > $b['endDt'];

        });
        var_dump($timeSaleInfo[0]['pcDescription']);
        $this->setData('timeSaleInfo', $timeSaleInfo[0]);

        $timeSaleDuration = strtotime($getData['endDt'])- time();
        $this->setData('timeSaleDuration', gd_isset($timeSaleDuration));

    }
}
