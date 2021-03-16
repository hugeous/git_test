<?php

/**
 * This is commercial software, only users who have purchased a valid license
 * and accept to the terms of the License Agreement can install and use this
 * program.
 *
 * Do not edit or add to this file if you wish to upgrade Godomall5 to newer
 * versions in the future.
 *
 * @copyright ⓒ 2016, NHN godo: Corp.
 * @link http://www.godo.co.kr
 */
namespace Component\Delivery;

use Component\Database\DBTableField;
use Framework\Debug\Exception\Except;
use Component\Validator\Validator;
use Exception;
use Request;
use Globals;
use Session;


/**
 * 장바구니내 배송비 계산
 * @author Shin Donggyu <artherot@godo.co.kr>
 */
class DeliveryCart extends \Bundle\Component\Delivery\DeliveryCart
{
	 protected $db;

    /**
     * 생성자
     */
    public function __construct()
    {
        if (!is_object($this->db)) {
            $this->db = \App::load('DB');
        }
    }

	public function getFreeDeliverySno(){
		if (empty($arrSno) === true || ! is_array($arrSno)) {
            return false;
        }

        $arrBind = [];        

        $arrInclude = [
            'scmNo',
            'method',
            'collectFl',
            'fixFl',
            'freeFl',
            'pricePlusStandard',
            'priceMinusStandard',
            'goodsDeliveryFl',
            'areaFl',
            'areaGroupNo',
            'scmCommissionDelivery',
            'taxFreeFl',
            'taxPercent',
            'rangeLimitFl',
            'rangeLimitWeight',
            'rangeRepeat',
            'addGoodsCountInclude',
            'deliveryMethodFl',
            'deliveryVisitPayFl',
            'deliveryConfigType',
            'dmVisitAddressUseFl',
            'sameGoodsDeliveryFl',
        ];
        $arrField = DBTableField::setTableField('tableScmDeliveryBasic', $arrInclude, null, 'sdb');
        $strSQL = 'SELECT sdb.sno, ' . implode(', ', $arrField) . ', sm.scmCommissionDelivery FROM ' . DB_SCM_DELIVERY_BASIC . ' AS sdb LEFT JOIN ' . DB_SCM_MANAGE . ' AS sm ON sdb.scmNo = sm.scmNo WHERE sdb.fixFl="free" ORDER BY sdb.sno ASC';
        $getData = $this->db->query_fetch($strSQL, $arrBind);
        $setData = [];
        $tmpFreeScmNo = [];
        foreach ($getData as $key => $val) {
            foreach ($arrInclude as $fVal) {
                if (in_array($fVal, ['pricePlusStandard', 'priceMinusStandard'])) {
                    $setData[$val['sno']][$fVal] = explode(STR_DIVISION, $val[$fVal]);
                } else {
                    // 무료 배송인 경우에만 해당 공급사 무료 체크 활성화
                    if ($fVal === 'freeFl') {
                        if ($val['fixFl'] === 'free') {
                            $setData[$val['sno']][$fVal] = $val[$fVal];
                            // 해당 공급사 무료 인경우 체크를 해서
                            if ($val[$fVal] === 'y') {
                                $tmpFreeScmNo[] = $setData[$val['sno']]['scmNo'];
                            }
                        } else {
                            $setData[$val['sno']][$fVal] = '';
                        }
                    } else {
                        $setData[$val['sno']][$fVal] = $val[$fVal];
                    }
                }
            }
            $setData[$val['sno']]['charge'] = $this->_getSnoDeliveryCharge($val['sno'], $val['deliveryConfigType']);
            $setData[$val['sno']]['areaGroupList'] = $this->_getAreaDeliveryData($setData[$val['sno']]['areaGroupNo']);
        }

        if (empty($tmpFreeScmNo) === false) {
            foreach ($setData as $key => $val){
                if (in_array($val['scmNo'], $tmpFreeScmNo)) {
                    $setData[$key]['wholeFreeFl'] = 'y';
                }
            }
        }

        return $setData;
	}
}