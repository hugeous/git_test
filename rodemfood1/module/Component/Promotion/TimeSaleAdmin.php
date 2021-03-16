<?php

/**
 * 상품노출형태 관리
 * @author atomyang
 * @version 1.0
 * @since 1.0
 * @copyright ⓒ 2016, NHN godo: Corp.
 */
namespace Component\Promotion;

use Component\Member\Manager;
use Component\Database\DBTableField;
use Component\Validator\Validator;
use Globals;
use LogHandler;
use Request;
use Session;

class TimeSaleAdmin extends \Bundle\Component\Promotion\TimeSaleAdmin
{
    /**
     * getDataThemeCongif
     *
     * @param null $themeCd
     * @return mixed
     */
    public function getDataTimeSale($sno)
    {
        // --- 등록인 경우
        if (!$sno) {
            // 기본 정보
            $data['mode'] = 'register';
            // 기본값 설정
            DBTableField::setDefaultData('tableTimeSale', $data);
            $data['updateFl'] = "y";

            // --- 수정인 경우
        } else {
            // 테마 정보
            $data = $this->getInfoTimeSale($sno);
            $data['mode'] = 'modify';

            if($data['goodsNo']) {
                $goodsAdmin = \App::load('\\Component\\Goods\\GoodsAdmin');
                $data['goodsNo'] = $goodsAdmin->getGoodsDataDisplay($data['goodsNo']);
            }
            if($data['fixGoodsNo']) $data['fixGoodsNo'] = explode(INT_DIVISION, $data['fixGoodsNo']);

            // 기본값 설정
            DBTableField::setDefaultData('tableTimeSale', $data);
        }

        if($data['mobileDisplayFl'] =='y' && $data['pcDisplayFl'] =='y')  $data['displayFl'] = 'all';
        else if($data['mobileDisplayFl'] =='y')  $data['displayFl'] = 'm';
        else if($data['pcDisplayFl'] =='y')  $data['displayFl'] = 'p';

        $checked = array();
        $checked['goodsPriceViewFl'][gd_isset($data['goodsPriceViewFl'])]  = $checked['orderCntDateFl'][gd_isset($data['orderCntDateFl'])]  = $checked['orderCntDisplayFl'][gd_isset($data['orderCntDisplayFl'])] = $checked['stockFl'][gd_isset($data['stockFl'])] = $checked['memberDcFl'][gd_isset($data['memberDcFl'])] = $checked['mileageFl'][gd_isset($data['mileageFl'])] = $checked['couponFl'][gd_isset($data['couponFl'])] = $checked['displayFl'][gd_isset($data['displayFl'])]  = $checked['moreBottomFl'][gd_isset($data['moreBottomFl'])]  = 'checked="checked"';

        $checked['mainDisplayFl'][gd_isset($data['mainDisplayFl'])] = 'checked="checked"';

        $checked['leftTimeDisplayType']['pc'] = strpos($data['leftTimeDisplayType'], 'PC') !== false ? 'checked="checked"' : '';
        $checked['leftTimeDisplayType']['m'] = strpos($data['leftTimeDisplayType'], 'MOBILE') !== false ? 'checked="checked"' : '';

        $selected = array();
        $selected['pcThemeCd'][gd_isset($data['pcThemeCd'])]  = $selected['mobileThemeCd'][gd_isset($data['mobileThemeCd'])]  = 'selected="selected"';

        $getData['data'] = $data;
        $getData['checked'] = $checked;
        $getData['selected'] = $selected;

        return $getData;
    }
}
