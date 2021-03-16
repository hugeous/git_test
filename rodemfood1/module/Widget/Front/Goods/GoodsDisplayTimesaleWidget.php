<?php

/**
 */

namespace Widget\Front\Goods;

use Framework\Utility\SkinUtils;

class GoodsDisplayTimesaleWidget extends \Widget\Front\Widget
{
    public function index()
    {
        $goods = \App::load('\\Component\\Goods\\Goods');
        $timeSale = \App::load('\\Component\\Promotion\\TimeSale');
        $timeSaleList = $timeSale->getListTimeSale();

        foreach($timeSaleList as $k => $v){
            $getData = $timeSale->getInfoTimeSale($v['sno']);
            $arrGoodsNo = explode(INT_DIVISION, $getData['goodsNo']);
            $tmpGoodsList = $goods->goodsDataDisplay('goods',$getData['goodsNo']);
            // var_dump($tmpGoodsList);
            // foreach($arrGoodsNo as $goodsNo) {
            //     $timeSaleInfo = $timeSale->getGoodsTimeSale($goodsNo);
            //     $goodsViewList[] = $timeSaleInfo;
            //     continue;
            //     // echo json_encode($timeSaleInfo);
            //
            //     $goodsInfo = $goods->getGoodsInfo($goodsNo);
            //     // if($goodsInfo['goodsDisplayMainTimeSaleFl'] != 'y')
            //     //   continue;
            //     try {
            //       $goodsData = $goods->getGoodsView($goodsNo);
            //     } catch(\Exception $e) {
            //       // getGoodsView()로 삭제된 상품 정보 가져올 시 exception 발생
            //       continue;
            //     }
            //
            //     $goodsImage = $goods->getGoodsImage($goodsNo, 'add4');
            //
            //     if ($goodsImage) {
            //         $goodsImageName = $goodsImage[0]['imageName'];
            //         $goodsImageSize = $goodsImage[0]['imageSize'];
            //         $_imageInfo = pathinfo($goodsImageName);
            //         if (!$goodsImageSize) {
            //             $goodsImageSize = SkinUtils::getGoodsImageSize($_imageInfo['extension']);
            //             $goodsImageSize = $goodsImageSize['size1'];
            //         }
            //     }
            //     $goodsImageSrc = SkinUtils::imageViewStorageConfig($goodsImageName, $goodsData['imagePath'], $goodsData['imageStorage'], $goodsImageSize, 'goods', false)[0];
            //     if($goodsData['imageStorage'] == 'local'){
            //         $goodsImageSrc = str_replace('https', 'http', $goodsImageSrc);
            //         $goodsImageSrc = str_replace(':443', '', $goodsImageSrc);
            //     }
            //     $goodsData['goodsImageSrc'] = $goodsImageSrc;
            //     $goodsViewList[] = $goodsData;
            // }
        }
        $this->setData('goodsViewList', $tmpGoodsList);
        // print_r('<!--');
        // var_dump($goodsViewList);
        // print_r('-->');
    }
}
