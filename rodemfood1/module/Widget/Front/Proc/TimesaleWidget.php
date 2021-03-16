<?php

/**
 */

namespace Widget\Front\Proc;

use Framework\Utility\SkinUtils;

class TimesaleWidget extends \Widget\Front\Widget
{
    public function index()
    {
        // type
        // list
        // title
        // timer

        $goods = \App::load('\\Component\\Goods\\Goods');
        $timeSale = \App::load('\\Component\\Promotion\\TimeSale');
        $timeSaleInfo = $timeSale->getInfoTimeSale();
        if (count($timeSaleInfo) > 0) {

            // var_dump($timeSaleInfo);
            usort($timeSaleInfo, function($a, $b){
                // return $a['endDt'] < $b['endDt'];
                return $a['sno'] < $b['sno'];
            });
            // var_dump($timeSaleInfo);
            // $timeSaleList = $timeSale->getListTimeSale();
            // var_dump($timeSaleList);
            // var_dump($timeSaleInfo[0]);
            // var_dump($timeSaleInfo['pcDescription']);

            foreach ($timeSaleInfo as $k => $v) {
                if ($v['mainDisplayFl'] != 'y') {
                    unset($timeSaleInfo[$k]);
                }
            }
            $timeSaleInfo = \array_values($timeSaleInfo); // rebase key
            // var_dump(99999999);
            // var_dump(count($timeSaleInfo));
            // var_dump(($timeSaleInfo));

            if (count($timeSaleInfo) > 0) {
                $this->setData('timeSaleInfo', $timeSaleInfo[0]);
                $getData = $timeSale->getInfoTimeSale($timeSaleInfo[0]['sno']);
                // $arrGoodsNo = explode(INT_DIVISION, $getData['goodsNo']);
                $goodsViewList = $goods->goodsDataDisplay('goods',$getData['goodsNo']);
                $this->setData('goodsViewList', $goodsViewList);

                $timeSaleDuration = strtotime($getData['endDt'])- time();
                $this->setData('timeSaleDuration', gd_isset(max($timeSaleDuration, 0)));
            } else {
                $this->setData('timeSaleDuration', 0);
            }

        }


        // $getData = $timeSale->getInfoTimeSale($timeSaleInfo[0]['sno']);
        // // $arrGoodsNo = explode(INT_DIVISION, $getData['goodsNo']);
        // $goodsViewList = $goods->goodsDataDisplay('goods',$getData['goodsNo']);
        // $this->setData('goodsViewList', $goodsViewList);
        //
        // foreach($timeSaleList as $k => $v){
        //     $getData = $timeSale->getInfoTimeSale($v['sno']);
        //     $arrGoodsNo = explode(INT_DIVISION, $getData['goodsNo']);
        //     $tmpGoodsList = $goods->goodsDataDisplay('goods',$getData['goodsNo']);
        // }
        //
        // var_dump($timeSaleInfo[0]['pcDescription']);
        $type = $this->getData('type');
        // var_dump($type);

        if ($type == 'title') {
            // $this->getView()->setPageName('proc/_timesale_title');
            print_r($timeSaleInfo[0]['pcDescription']);exit();
        } else if ($type == 'list') {
            $this->getView()->setPageName('goods/_goods_display_timesale');
        } else if ($type == 'timer') {
            $this->getView()->setPageName('proc/_timesale_timer');
        } else {
            exit();
        }

    }
}
