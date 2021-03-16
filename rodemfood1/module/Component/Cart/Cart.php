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
namespace Component\Cart;


use Component\Goods\Goods;
use Component\Naver\NaverPay;
use Component\Payment\Payco\Payco;
use Component\GoodsStatistics\GoodsStatistics;
use Component\Policy\Policy;
use Component\Database\DBTableField;
use Component\Delivery\EmsRate;
use Component\Delivery\OverseasDelivery;
use Component\Mall\Mall;
use Component\Mall\MallDAO;
use Component\Member\Util\MemberUtil;
use Component\Member\Group\Util;
use Component\Validator\Validator;
use Cookie;
use Exception;
use Framework\Debug\Exception\AlertBackException;
use Framework\Debug\Exception\AlertRedirectException;
use Framework\Debug\Exception\AlertRedirectCloseException;
use Framework\Debug\Exception\Except;
use Framework\Utility\ArrayUtils;
use Framework\Utility\NumberUtils;
use Framework\Utility\SkinUtils;
use Framework\Utility\StringUtils;
use Globals;
use Request;
use Session;
use Component\Storage\Storage;

/**
 * 장바구니 class
 *
 * 상품과 추가상품을 분리하는 작업에서 추가상품을 기존과 동일하게 상품에 종속시켜놓은 이유는
 * 상품과 같이 배송비 및 다양한 조건들을 아직은 추가상품에 설정할 수 없어서
 * 해당 상품으로 부터 할인/적립등의 조건을 상속받아서 사용하기 때문이다.
 * 따라서 추후 추가상품쪽에 상품과 동일한 혜택과 기능이 추가되면
 * 장바구니 테이블에서 상품이 별도로 담길 수 있도록 개발되어져야 한다.
 *
 * @author Shin Donggyu <artherot@godo.co.kr>
 */
class Cart extends \Bundle\Component\Cart\Cart
{
	public function reSaveOrder($orderNo, $siteKey){
		if (!is_object($this->db)) {
			$this->db = \App::load('DB');
		}

		$strSQL = "	INSERT INTO es_cart(mallSno, siteKey, memNo, directCart, goodsNo, optionSno, goodsCnt, addGoodsNo, addGoodsCnt, optionText, deliveryCollectFl, deliveryMethodFl, memberCouponNo, useBundleGoods,regDt)
						SELECT a.mallSno, '".$siteKey."' , a.memNo, 'y', b.goodsNo, b.optionSno, b.goodsCnt, 
							CASE WHEN (SELECT COUNT(sno) FROM es_orderGoods where orderNo=a.orderNo and goodsType = 'addGoods' and parentGoodsNo=b.goodsNo) > 0 THEN 
							CONCAT('[\"',(select group_concat(goodsNo) from es_orderGoods where orderNo=a.orderNo and goodsType = 'addGoods' and parentGoodsNo=b.goodsNo), '\"]')  ELSE '' END as 'addGoodsNo'
							,CASE WHEN (SELECT COUNT(sno) FROM es_orderGoods where orderNo=a.orderNo and goodsType = 'addGoods' and parentGoodsNo=b.goodsNo) > 0 THEN 
							CONCAT('[\"',(select group_concat(addGoodsCnt) from es_orderGoods where orderNo=a.orderNo and goodsType = 'addGoods' and parentGoodsNo=b.goodsNo), '\"]') ELSE '' END
							, b.optionTextInfo, b.goodsDeliveryCollectFl, b.deliveryMethodFl, 0, 0, now()
						FROM es_order a
							JOIN es_orderGoods b ON a.orderNo=b.orderNo
						WHERE a.orderNo=".$orderNo."			
				";					
		$this->db->query($strSQL);		
	}

	public function schMemGroupUpdate(){
		if (!is_object($this->db)) {
			$this->db = \App::load('DB');
		}

		//$strSQL = "UPDATE es_member SET ";
		//$this->db->query($strSQL);		

	} 

	/**
     * 장바구니에 담겨있는 상품리스트 혹은 일부리스트를 가져온다.
     * 데이터를 요청할때 파라미터는 한개라도 반드시 배열의 형태로 넘겨야 한다.
     * [1], [1,2,3...], null과 같은 형태로 작성 가능
     *
     * @param mixed   $cartIdx            장바구니 번호(들)
     * @param array   $address            지역별 배송비 계산을 위한 배송주소
     * @param array   $tmpOrderNo         임시 주문번호 (PG처리 후 해당 주문을 찾기 위함)
     * @param boolean $isAddGoodsDivision 추가상품 주문분리 로직 사용여부
     * @param boolean $isCouponCheck      상품쿠폰 사용가능 체크 여부
     * @param array   $postValue          주문데이터
     * @param array   $setGoodsCnt        복수배송지 사용시 안분된 상품 수량
     * @param array   $setAddGoodsCnt     복수배송지 사용시 안분된 추가 상품 수량
     *
     * @return array 상품데이터
     * @throws Exception
     * @author Jong-tae Ahn <qnibus@godo.co.kr>
     */
    public function getCartGoodsData($cartIdx = null, $address = null, $tmpOrderNo = null, $isAddGoodsDivision = false, $isCouponCheck = false, $postValue = [], $setGoodsCnt = [], $setAddGoodsCnt = [])
    {
		
		// 신규회원 체크
		if (!is_object($this->db)) {
			$this->db = \App::load('DB');
		}		

		$firstOrderStat = "0";
		if(Session::get('member.memNo') > 0){		
			$strSQL = "SELECT groupSno, regDt FROM es_member WHERE memNo='".Session::get('member.memNo')."' LIMIT 1";						        
			$mem = $this->db->query_fetch($strSQL, $arrBind, false);		

			$strSQL = "SELECT orderNo FROM es_order WHERE memNo='".Session::get('member.memNo')."' AND orderStatus IN ('o1', 'p1', 'g1', 'g2', 'g3', 'g4', 'd1', 'd2', 's1', 'e1', 'e2', 'e3', 'e4', 'e5') LIMIT 1";						        
			$firstOrder = $this->db->query_fetch($strSQL, $arrBind, false);		

			$timestamp = strtotime($mem['regDt']."+4 days");
			$timechk = strtotime(date("Y-m-d", $timestamp)." 00:00:00");
			$timenow = strtotime(date("Y-m-d H:i:s"));
			
			if($timenow < $timechk && $firstOrder['orderNo'] == "") {											
				$firstOrderStat = "1";
				$this->totalDeliveryCharge = 0;
			}
		}  

        // 회원 로그인 체크
        // 로그인상태면 mergeCart처리
        if (Request::getFileUri() != 'order_ps.php') {
            if ($this->isLogin === true) {
                $this->setMergeCart($this->members['memNo']);
            } else {
                $this->setMergeCart();
            }
        }

        // 장바구니 상품수량 재정의
        if (Request::getFileUri() == 'order.php') {
            $cartIdx = $this->setCartGoodsCnt($this->members['memNo'], $cartIdx);
        }

        $mallBySession = SESSION::get(SESSION_GLOBAL_MALL);

        // 절사 정책 가져오기
        $truncGoods = Globals::get('gTrunc.goods');

        // 선택한 상품만 주문시
        $arrBind = [];
        if (empty($cartIdx) === false) {
            if (is_array($cartIdx)) {
                $tmpWhere = [];
                foreach ($cartIdx as $cartSno) {
                    if (is_numeric($cartSno)) {
                        $tmpWhere[] = $this->db->escape($cartSno);
                    }
                }
                if (empty($tmpWhere) === false) {
                    $tmpAddWhere = [];
                    foreach ($tmpWhere as $val) {
                        $tmpAddWhere[] = '?';
                        $this->db->bind_param_push($arrBind, 'i', $val);
                    }
                    $arrWhere[] = 'c.sno IN (' . implode(' , ', $tmpAddWhere) . ')';
                }
                unset($tmpWhere);
            } elseif (is_numeric($cartIdx)) {
                $arrWhere[] = 'c.sno = ?';
                $this->db->bind_param_push($arrBind, 'i', $cartIdx);
            }
        }

        // 해외배송비조건에 따라 국가코드가 있으면 배송비조건 일련번호 가져와 저장
        if ($this->isGlobalFront($address)) {
            $overseasDeliverySno = $this->getDeliverySnoForOverseas($address);
        }

        // 회원 로그인 체크
        // App::getInstance('ControllerNameResolver')->getControllerRootDirectory() != 'admin'
        if ($this->isLogin === true) {
            //수기주문시 회원인 경우 memNo, siteKey 로 동시 비교
            if($this->isWrite === true  && $this->useRealCart !== true){
                $arrWhere[] = 'c.memNo = \'' . $this->members['memNo'] . '\' AND  c.siteKey = \'' . $this->siteKey . '\'';
            }
            else {
                $arrWhere[] = 'c.memNo = \'' . $this->members['memNo'] . '\'';
            }
        } else {
            $arrWhere[] = 'c.siteKey = \'' . $this->siteKey . '\'';
        }

        // 바로 구매 설정
        if (Cookie::has('isDirectCart') && $this->cartPolicy['directOrderFl'] == 'y' && Request::getFileUri() != 'cart.php' && (Request::getFileUri() != 'order_ps.php' || (Request::getFileUri() == 'order_ps.php' && in_array(Request::post()->get('mode'), ['set_recalculation']) === true))) {
            $arrWhere[] = 'c.directCart = \'y\'';
        } else {
            if (Cookie::has('isDirectCart')) {
                // 바로 구매 쿠키 삭제
                Cookie::del('isDirectCart');
            }

            // 바로구매 쿠폰은 setDeleteDirectCart 에서 처리되고있음
            //$arrWhere[] = 'c.directCart = \'n\'';
        }

        if ($tmpOrderNo !== null) {
            $arrWhere = [];
            $arrWhere[] = 'c.tmpOrderNo = \'' . $tmpOrderNo . '\'';
        }

        // 정렬 방식
        $strOrder = 'c.sno DESC';

        // 장바구니 디비 및 상품 디비의 설정 (필드값 설정)
        $getData = [];

        $arrExclude['cart'] = [];
        $arrExclude['option'] = [
            'goodsNo',
            'optionNo',
        ];
        $arrExclude['addOptionName'] = [
            'goodsNo',
            'optionCd',
            'mustFl',
        ];
        $arrExclude['addOptionValue'] = [
            'goodsNo',
            'optionCd',
        ];
        $arrInclude['goods'] = [
            'goodsNm',
            'commission',
            'scmNo',
            'purchaseNo',
            'goodsCd',
            'cateCd',
            'goodsOpenDt',
            'goodsState',
            'imageStorage',
            'imagePath',
            'brandCd',
            'makerNm',
            'originNm',
            'goodsModelNo',
            'goodsPermission',
            'goodsPermissionGroup',
            'goodsPermissionPriceStringFl',
            'goodsPermissionPriceString',
            'onlyAdultFl',
            'onlyAdultImageFl',
            'goodsAccess',
            'goodsAccessGroup',
            'taxFreeFl',
            'taxPercent',
            'goodsWeight',
            'totalStock',
            'stockFl',
            'soldOutFl',
            'salesUnit',
            'minOrderCnt',
            'maxOrderCnt',
            'salesStartYmd',
            'salesEndYmd',
            'mileageFl',
            'mileageGoods',
            'mileageGoodsUnit',
            'goodsDiscountFl',
            'goodsDiscount',
            'goodsDiscountUnit',
            'payLimitFl',
            'payLimit',
            'goodsPriceString',
            'goodsPrice',
            'fixedPrice',
            'costPrice',
            'optionFl',
            'optionName',
            'optionTextFl',
            'addGoodsFl',
            'addGoods',
            'deliverySno',
            'delFl',
            'hscode',
            'goodsSellFl',
            'goodsSellMobileFl',
            'goodsDisplayFl',
            'goodsDisplayMobileFl',
            'mileageGroup',
            'mileageGroupInfo',
            'mileageGroupMemberInfo',
            'fixedGoodsDiscount',
            'goodsDiscountGroup',
            'goodsDiscountGroupMemberInfo',
            'exceptBenefit',
            'exceptBenefitGroup',
            'exceptBenefitGroupInfo',
            'fixedSales',
            'fixedOrderCnt',
            'goodsBenefitSetFl',
            'benefitUseType',
            'newGoodsRegFl',
            'newGoodsDate',
            'newGoodsDateFl',
            'periodDiscountStart',
            'periodDiscountEnd',
            'regDt',
            'modDt'
        ];
        $arrInclude['image'] = [
            'imageSize',
            'imageName',
        ];

        $arrFieldCart = DBTableField::setTableField('tableCart', null, $arrExclude['cart'], 'c');
        $arrFieldGoods = DBTableField::setTableField('tableGoods', $arrInclude['goods'], null, 'g');
        $arrFieldOption = DBTableField::setTableField('tableGoodsOption', null, $arrExclude['option'], 'go');
        $arrFieldImage = DBTableField::setTableField('tableGoodsImage', $arrInclude['image'], null, 'gi');
        unset($arrExclude);

        // 장바구니 상품 기본 정보
        $strSQL = "SELECT c.sno,
            " . implode(', ', $arrFieldCart) . ", c.regDt,
            " . implode(', ', $arrFieldGoods) . ",
            " . implode(', ', $arrFieldOption) . ",
            " . implode(', ', $arrFieldImage) . "
			, CASE WHEN (SELECT COUNT(sno) FROM es_goodsLinkCategory WHERE goodsNo=g.goodsNo AND cateCd='008') >0 THEN '1' ELSE '0' END AS 'DealStat'
        FROM " . $this->tableName . " c
        INNER JOIN " . DB_GOODS . " g ON c.goodsNo = g.goodsNo
        LEFT JOIN " . DB_GOODS_OPTION . " go ON c.optionSno = go.sno AND c.goodsNo = go.goodsNo
        LEFT JOIN " . DB_GOODS_IMAGE . " as gi ON g.goodsNo = gi.goodsNo AND gi.imageKind = 'list'
        WHERE " . implode(' AND ', $arrWhere) . "
        ORDER BY " . $strOrder;

        /**해외몰 관련 **/
        if($mallBySession) {
            $arrFieldGoodsGlobal = DBTableField::setTableField('tableGoodsGlobal',null,['mallSno']);
            $strSQLGlobal = "SELECT gg." . implode(', gg.', $arrFieldGoodsGlobal) . " FROM ".$this->tableName." as c INNER JOIN ".DB_GOODS_GLOBAL." as gg ON  c.goodsNo = gg.goodsNo AND gg.mallSno = '".$mallBySession['sno']."'  WHERE " . implode(' AND ', $arrWhere) ;
            $tmpData = $this->db->query_fetch($strSQLGlobal, $arrBind);
            $globalData = array_combine (array_column($tmpData, 'goodsNo'), $tmpData);
        }

        $query = $this->db->getBindingQueryString($strSQL, $arrBind);
        $result = $this->db->query($query);
        unset($arrWhere, $strOrder);

        // 상품리스트가 없는 경우 주문서에서 강제로 빠져나감
        if ($result === false && Request::getFileUri() != 'cart.php') {
            throw new Exception(__('장바구니에 상품이 없습니다.'));
        }

        // 삭제 상품에 대한 cartNo
        $this->cartSno = [];
        $_delCartSno = [];

        // 해외배송시 박스무게 추가
        if (Globals::get('gGlobal.isFront') || $this->isAdminGlobal === true) {
            $this->totalDeliveryWeight['box'] = $this->overseasDeliveryPolicy['data']['boxWeight'];
            $this->totalDeliveryWeight['total'] += $this->totalDeliveryWeight['box'];
        }

        //매입처 관련 정보
        if(gd_is_plus_shop(PLUSSHOP_CODE_PURCHASE) === true) {
            $strPurchaseSQL = 'SELECT purchaseNo,purchaseNm FROM ' . DB_PURCHASE . ' g  WHERE delFl = "n"';
            $tmpPurchaseData = $this->db->query_fetch($strPurchaseSQL);
            $purchaseData = array_combine(array_column($tmpPurchaseData, 'purchaseNo'), array_column($tmpPurchaseData, 'purchaseNm'));
        }

        //상품 가격 노출 관련
        $goodsPriceDisplayFl = gd_policy('goods.display')['priceFl'];

        //품절상품 설정
        if(Request::isMobile()) {
            $soldoutDisplay = gd_policy('soldout.mobile');
        } else {
            $soldoutDisplay = gd_policy('soldout.pc');
        }

        // 제외 혜택 쿠폰 번호
        $exceptCouponNo = [];
        $goodsKey = [];
        $goods = new Goods();
        //상품 혜택 모듈
        $goodsBenefit = \App::load('\\Component\\Goods\\GoodsBenefit');
        while ($data = $this->db->fetch($result)) {

            //상품혜택 사용시 해당 변수 재설정
            $data = $goodsBenefit->goodsDataFrontConvert($data);

            //복수배송지 사용 && 수량 안분처리시 상품수량 재설정
            if ((\Component\Order\OrderMultiShipping::isUseMultiShipping() == true || $postValue['isAdminMultiShippingFl'] === 'y') && empty($setGoodsCnt) === false) {
                $couponConfig = gd_policy('coupon.config');
                if($couponConfig['productCouponChangeLimitType'] == 'n' && $postValue['productCouponChangeLimitType'] == 'n') { // 상품쿠폰 주문서 수정 제한 안함일 때
                    $data['goodsCouponOriginGoodsCnt'] = $data['goodsCnt']; // 복수배송지 안분된 상품 갯수가 아닌 카트 상품갯수 파악
                }
                if (empty($setGoodsCnt[$data['sno']]['goodsCnt']) === false) {
                    $data['goodsCnt'] = $setGoodsCnt[$data['sno']]['goodsCnt'];
                } else {
                    $data['goodsCnt'] = $setGoodsCnt[$data['sno']];
                }
            }
            if ((\Component\Order\OrderMultiShipping::isUseMultiShipping() == true || $postValue['isAdminMultiShippingFl'] === 'y') && empty($setAddGoodsCnt) === false) {
                $addGoodsNo = json_decode(stripslashes($data['addGoodsNo']), true);
                $addGoodsCnt = json_decode(stripslashes($data['addGoodsCnt']), true);
                foreach ($setAddGoodsCnt[$data['sno']] as $aKey => $aVal) {
                    $tmpAddGoodsNoKey = array_search($aKey, $addGoodsNo);
                    $addGoodsCnt[$tmpAddGoodsNoKey] = $aVal;
                }
                $data['addGoodsCnt'] = json_encode($addGoodsCnt);
            }
            // stripcslashes 처리
            // json형태의 경우 json값안에 "이있는경우 stripslashes처리가 되어 json_decode에러가 나므로 json값중 "이 들어갈수있는경우 $aCheckKey에 해당 필드명을 추가해서 처리해주세요
            $aCheckKey = array('optionText');
            foreach ($data as $k => $v) {
                if (!in_array($k, $aCheckKey)) {
                    $data[$k] = gd_htmlspecialchars_stripslashes($v);
                }
            }

            // 전체상품 수량
            $this->cartGoodsCnt += $data['goodsCnt'];
            // 쿠폰사용이면
            if (!empty($data['memberCouponNo']) && $data['memberCouponNo'] != '') {
                // 쿠폰 기본설정값을 가져와서 회원등급만 사용설정이면 쿠폰정보를 제거 처리 & changePrice false처리
                $couponConfig = gd_policy('coupon.config');
                if ($couponConfig['chooseCouponMemberUseType'] == 'member') {
                    $this->setMemberCouponDelete($data['sno']);
                    $data['memberCouponNo'] = '';
                    $this->changePrice = false;
                }

                // 쿠폰 사용정보를 가져와서 쿠폰사용정보가 있으면 쿠폰설정에 따른 결제 방식 제한을 처리해준다
                $aTempMemberCouponNo = explode(INT_DIVISION, $data['memberCouponNo']);
                $coupon = \App::load('\\Component\\Coupon\\Coupon');
                foreach ($aTempMemberCouponNo as $val) {
                    if ($val != null) {
                        $aTempCouponInfo = $coupon->getMemberCouponInfo($val);
                        if ($aTempCouponInfo['couponUseAblePaymentType'] == 'bank') {
                            $data['payLimitFl'] = 'y';
                            if ($data['payLimit'] == '') {
                                $data['payLimit'] = 'gb';
                            } else {
                                $aTempPayLimit = explode(STR_DIVISION, $data['payLimit']);
                                $bankCheck = 'n';
                                foreach($aTempPayLimit as $limitVal) {
                                    if ($limitVal == 'gb') {
                                        $bankCheck = 'y';
                                    }
                                }
                                if ($bankCheck == 'n') {
                                    //$data['payLimit'] = STR_DIVISION . 'gb';
                                    $data['payLimit'] = array(false);
                                }
                            }
                        }
                    }
                }
            }

            // 기준몰 상품명 저장 (무조건 기준몰 상품명이 저장되도록)
            $data['goodsNmStandard'] = $data['goodsNm'];
            if($mallBySession && $globalData[$data['goodsNo']]) {
                $data = array_replace_recursive($data, array_filter(array_map('trim',$globalData[$data['goodsNo']])));
            }

            // 상품 카테고리 정보
            $goods = \App::load(\Component\Goods\Goods::class);
            $data['cateAllCd'] = $goods->getGoodsLinkCategory($data['goodsNo']);

            //매입처 관련 정보
            if(gd_is_plus_shop(PLUSSHOP_CODE_PURCHASE) === false || (gd_is_plus_shop(PLUSSHOP_CODE_PURCHASE) === true && !in_array($data['purchaseNo'],array_keys($purchaseData))))  {
                unset($data['purchaseNo']);
            }

            // 상품 삭제 여부에 따른 처리
            if ($data['delFl'] === 'y') {
                $_delCartSno[] = $data['sno'];
                unset($data);
                continue;
            } else {
                unset($data['delFl']);
            }

            // 해외배송비 선택시 기본무게에 해외배송비 조건의 무게를 더한다
            if (Globals::get('gGlobal.isFront') || $this->isAdminGlobal === true) {
                // 상품의 경우 기본 무게단위 설정에 의해 계산되기때문에 해당 단위를 가져와 별도 계산해야 함
                // 배송은 KG 단위로 적용되어진다.
                $weightConf = gd_policy('basic.weight');
                $rateWeight = ($weightConf['unit'] == 'g' ? 1000 : 1);
                $data['goodsWeight'] = ($data['goodsWeight'] > 0 ? ($data['goodsWeight'] / $rateWeight) : $this->overseasDeliveryPolicy['data']['basicWeight']);
                $this->totalDeliveryWeight['goods'] += ($data['goodsWeight'] * $data['goodsCnt']);
                $this->totalDeliveryWeight['total'] += ($data['goodsWeight'] * $data['goodsCnt']);
            }

            // 텍스트옵션 상품 정보
            $goodsOptionText = $goods->getGoodsOptionText($data['goodsNo']);
            if (empty($data['optionText']) === false && gd_isset($goodsOptionText)) {
                $optionTextKey = array_keys(json_decode($data['optionText'], true));
                foreach ($goodsOptionText as $goodsOptionTextInfo) {
                    if (in_array($goodsOptionTextInfo['sno'], $optionTextKey) === true) {
                        $data['optionTextInfo'][$goodsOptionTextInfo['sno']] = [
                            'optionSno' => $goodsOptionTextInfo['sno'],
                            'optionName' => $goodsOptionTextInfo['optionName'],
                            'baseOptionTextPrice' => $goodsOptionTextInfo['addPrice'],
                        ];
                    }
                }
            }

            // 추가 상품 정보
            $data['addGoodsMustFl'] = $mustFl = json_decode(gd_htmlspecialchars_stripslashes($data['addGoods']),true);
            if ($data['addGoodsFl'] === 'y' && empty($data['addGoodsNo']) === false) {
                $data['addGoodsNo'] = json_decode($data['addGoodsNo']);
                $data['addGoodsCnt'] = json_decode($data['addGoodsCnt']);
                if ($isAddGoodsDivision !== false) {
                    foreach($mustFl as $_key=>$val){
                        $key = $_key;
                        break;
                    }
                    $data['addGoodsMustFl'] = $mustFl[$key]['mustFl'];
                }
            } else {
                $data['addGoodsNo'] = '';
                $data['addGoodsCnt'] = '';
                if ($isAddGoodsDivision !== false) {
                    $data['addGoodsMustFl'] = '';
                }
            }

            // 추가 상품 필수 여부
            if ($data['addGoodsFl'] === 'y' && empty($data['addGoods']) === false) {
                foreach ($mustFl as $k => $v) {
                    if ($v['mustFl'] == 'y') {
                        if (is_array($data['addGoodsNo']) === false) {
                            $data['addGoodsSelectedFl'] = 'n';
                            break;
                        } else {
                            $addGoodsResult = array_intersect($v['addGoods'], $data['addGoodsNo']);
                            if (empty($addGoodsResult) === true) {
                                $data['addGoodsSelectedFl'] = 'n';
                                break;
                            }
                        }
                    }
                }
                unset($mustFl);
            }

            // 텍스트 옵션 정보 (sno, value)
            $data['optionTextSno'] = [];
            $data['optionTextStr'] = [];
            if ($data['optionTextFl'] === 'y' && empty($data['optionText']) === false) {
                $arrText = json_decode($data['optionText']);
                foreach ($arrText as $key => $val) {
                    $data['optionTextSno'][] = $key;
                    $data['optionTextStr'][$key] = $val;
                    unset($tmp);
                }
            }
            //unset($data['optionText']);

            // 텍스트옵션 필수 사용 여부
            if ($data['optionTextFl'] === 'y') {
                if (gd_isset($goodsOptionText)) {
                    foreach ($goodsOptionText as $k => $v) {
                        if ($v['mustFl'] == 'y' && !in_array($v['sno'], $data['optionTextSno'])) {
                            $data['optionTextEnteredFl'] = 'n';
                        }
                    }
                }
            }
            unset($optionText);

            // 상품 구매 가능 여부
            $data = $this->checkOrderPossible($data);

            //구매불가 대체 문구 관련
            if($data['goodsPermissionPriceStringFl'] =='y' && $data['goodsPermission'] !='all' && (($data['goodsPermission'] =='member'  && $this->isLogin === false) || ($data['goodsPermission'] =='group'  && !in_array($this->members['groupSno'],explode(INT_DIVISION,$data['goodsPermissionGroup']))))) {
                $data['goodsPriceString'] = $data['goodsPermissionPriceString'];
            }

            //품절일경우 가격대체 문구 설정
            if (($data['soldOutFl'] === 'y' || ($data['soldOutFl'] === 'n' && $data['stockFl'] === 'y' && ($data['totalStock'] <= 0 || $data['totalStock'] < $data['goodsCnt']))) && $soldoutDisplay['soldout_price'] !='price'){
                if($soldoutDisplay['soldout_price'] =='text' ) {
                    $data['goodsPriceString'] = $soldoutDisplay['soldout_price_text'];
                } else if($soldoutDisplay['soldout_price'] =='custom' ) {
                    $data['goodsPriceString'] = "<img src='".$soldoutDisplay['soldout_price_img']."'>";
                }
            }

            $data['goodsPriceDisplayFl'] = 'y';
            if (empty($data['goodsPriceString']) === false && $goodsPriceDisplayFl =='n') {
                $data['goodsPriceDisplayFl'] = 'n';
            }

            // 정책설정에서 품절상품 보관설정의 보관상품 품절시 자동삭제로 설정한 경우
            if ($this->cartPolicy['soldOutFl'] == 'n' && $data['orderPossibleCode'] == self::POSSIBLE_SOLD_OUT) {
                $_delCartSno[] = $data['sno'];
                unset($data);
                continue;
            }

            // 상품결제 수단에 따른 주문페이지 결제수단 표기용 데이터
            if ($data['payLimitFl'] == 'y' && gd_isset($data['payLimit'])) {
                $payLimit = explode(STR_DIVISION, $data['payLimit']);
                $data['payLimit'] = $payLimit;

                if (is_array($payLimit) && $this->payLimit) {
                    $this->payLimit = array_intersect($this->payLimit, $payLimit);
                    if (empty($this->payLimit) === true) {
                        $this->payLimit = ['false'];
                    }
                } else {
                    $this->payLimit = $payLimit;
                }
            }

            // 비회원시 담은 상품과 회원로그인후 담은 상품이 중복으로 있는경우 재고 체크
            $data['duplicationGoods'] = 'n';
            if (isset($tmpStock[$data['goodsNo']][$data['optionSno']][$data['optionText']]) === false) {
                $tmpStock[$data['goodsNo']][$data['optionSno']][$data['optionText']] = $data['goodsCnt'];
            } else {
                $data['duplicationGoods'] = 'y';
                $chkStock = $tmpStock[$data['goodsNo']][$data['optionSno']][$data['optionText']] + $data['goodsCnt'];
                if ($data['stockFl'] == 'y' && $data['stockCnt'] < $chkStock) {
                    $this->orderPossible = false;
                    $data['stockOver'] = 'y';
                }
            }

            // 상품구분 초기화 (상품인지 추가상품인지?)
            $data['goodsType'] = 'goods';

            // 상품 이미지 처리 @todo 상품 사이즈 설정 값을 가지고 와서 이미지 사이즈 변경을 할것

            // 세로사이즈고정 체크
            $imageSize = SkinUtils::getGoodsImageSize('list');
            $imageConf = gd_policy('goods.image');

            if (Request::isMobile() || $imageConf['imageType'] != 'fixed') {
                $imageSize['size1'] = '40'; // 기존 사이즈
                $imageSize['hsize1'] = '';
            }

            // 상품 이미지 처리
            if ($data['onlyAdultFl'] == 'y' && gd_check_adult() === false && $data['onlyAdultImageFl'] =='n') {
                if (Request::isMobile()) {
                    $data['goodsImageSrc'] = "/data/icon/goods_icon/only_adult_mobile.png";
                } else {
                    $data['goodsImageSrc'] = "/data/icon/goods_icon/only_adult_pc.png";
                }

                $data['goodsImage'] = SkinUtils::makeImageTag($data['goodsImageSrc'], $imageSize['size1']);
            } else {
                $data['goodsImage'] = gd_html_preview_image($data['imageName'], $data['imagePath'], $data['imageStorage'], $imageSize['size1'], 'goods', $data['goodsNm'], 'class="imgsize-s"', false, false, $imageSize['hsize1']);
            }

            unset($data['imageStorage'], $data['imagePath'], $data['imageName'], $data['imagePath']);

            $data['goodsMileageExcept'] = 'n';
            $data['couponBenefitExcept'] = 'n';
            $data['memberBenefitExcept'] = 'n';

            //타임세일 할인 여부
            $data['timeSaleFl'] = false;
            if (gd_is_plus_shop(PLUSSHOP_CODE_TIMESALE) === true && Request::post()->get('mode') !== 'cartEstimate') {

                $timeSale = \App::load('\\Component\\Promotion\\TimeSale');
                $timeSaleInfo = $timeSale->getGoodsTimeSale($data['goodsNo']);
                if ($timeSaleInfo) {
                    $data['timeSaleFl'] = true;
                    if ($timeSaleInfo['mileageFl'] == 'n') {
                        $data['goodsMileageExcept'] = "y";
                    }
                    if ($timeSaleInfo['couponFl'] == 'n') {
                        $data['couponBenefitExcept'] = "y";

                        // 타임세일 상품적용 쿠폰 사용불가 체크
                        if (empty($data['memberCouponNo']) === false) {
                            $exceptCouponNo[$data['sno']] = $data['memberCouponNo'];
                        }
                    }
                    if ($timeSaleInfo['memberDcFl'] == 'n') {
                        $data['memberBenefitExcept'] = "y";
                    }
                    if ($data['goodsPrice'] > 0) {
                        // 타임세일 할인금액
                        $data['timeSalePrice'] = gd_number_figure((($timeSaleInfo['benefit'] / 100) * $data['goodsPrice']), $truncGoods['unitPrecision'], $truncGoods['unitRound']);

                        $data['goodsPrice'] = gd_number_figure($data['goodsPrice'] - (($timeSaleInfo['benefit'] / 100) * $data['goodsPrice']), $truncGoods['unitPrecision'], $truncGoods['unitRound']);
                    }
                    //상품 옵션가(일체형,분리형) 타임세일 할인율 적용 ( 텍스트 옵션가 / 추가상품가격 제외 )
                    if($data['optionFl'] === 'y'){
                        // 타임세일 할인금액
                        $data['timeSalePrice'] = gd_number_figure($data['timeSalePrice'] + (($timeSaleInfo['benefit'] / 100) * $data['optionPrice']), $truncGoods['unitPrecision'], $truncGoods['unitRound']);

                        $data['optionPrice'] = gd_number_figure($data['optionPrice'] - (($timeSaleInfo['benefit'] / 100) * $data['optionPrice']), $truncGoods['unitPrecision'], $truncGoods['unitRound']);
                    }
                }
            }

            // 혜택제외 체크 (쿠폰)
            $exceptBenefit = explode(STR_DIVISION, $data['exceptBenefit']);
            $exceptBenefitGroupInfo = explode(INT_DIVISION, $data['exceptBenefitGroupInfo']);
            if (in_array('coupon', $exceptBenefit) === true && ($data['exceptBenefitGroup'] == 'all' || ($data['exceptBenefitGroup'] == 'group' && in_array($this->_memInfo['groupSno'], $exceptBenefitGroupInfo) === true))) {
                if (empty($data['memberCouponNo']) === false) {
                    $exceptCouponNo[$data['sno']] = $data['memberCouponNo'];
                }
                $data['couponBenefitExcept'] = "y";
            }

            //배송방식에 관한 데이터
            $data['goodsDeliveryMethodFl'] = $data['deliveryMethodFl'];
            $data['goodsDeliveryMethodFlText'] = gd_get_delivery_method_display($data['deliveryMethodFl']);

            // 회원 추가 할인 여부 설정 (적용제외 대상이 있는 경우 적용 제외)
            $data = $this->getMemberDcFlInfo($data);

            // 해외배송의 배송비조건 일련번호 추출 후 기존 상품데이터에 배송지조건 일괄 변경
            if ($this->isGlobalFront($address)) {
                $data['deliverySno'] = $overseasDeliverySno;
            }
			// kaimen
			if ($firstOrderStat == "1"){
				$data['deliverySno'] = $this->getFreeDeliverySno();
			}

            $tmpOptionName = [];
            for ($optionKey = 1; $optionKey <= 5; $optionKey++) {
                if (empty($data['optionValue' . $optionKey]) === false) {
                    $tmpOptionName[] = $data['optionValue' . $optionKey];
                }
            }
            $data['optionNm'] = @implode('/', $tmpOptionName);
            unset($tmpOptionName);

            if (in_array($data['goodsNo'], $goodsKey) === false) {
                $goodsKey[] = $data['goodsNo'];
            }
            $data['goodsKey'] = array_search($data['goodsNo'], $goodsKey);

            // 현재 주문 중인 장바구니 SNO
            $this->cartSno[] = $data['sno'];

            // 쇼핑 계속하기 주소 처리
            if ($data['cateCd'] && empty($this->shoppingUrl) === true) {
                $this->shoppingUrl = $data['cateCd'];
            }
            $getData[] = $data;
            unset($data);
        }

        if ($isCouponCheck === true && empty($exceptCouponNo) === false && (MemberUtil::isLogin() || ($this->isWrite && empty($this->_memInfo) === false))) {
            if ($this->setCartCouponReset($exceptCouponNo) === true) {
                throw new AlertRedirectException(__('쿠폰 할인/적립 혜택이 변경된 상품이 포함되어 있으니 장바구니에서 확인 후 다시 주문해주세요.'), null, null, '../order/cart.php');
            }
        }

        // 삭제 상품이 있는 경우 해당 장바구니 삭제
        if (empty($_delCartSno) === false) {
            $this->setCartDelete($_delCartSno);
        }

        // 쇼핑계속하기 버튼
        if (empty($this->shoppingUrl) === true) {
            $this->shoppingUrl = URI_OVERSEAS_HOME;
        } else {
            $this->shoppingUrl = URI_OVERSEAS_HOME . 'goods/goods_list.php?cateCd=' . $this->shoppingUrl;
        }

        // 해외배송시 EMS조건에 30KG 이상인 경우 체크
        if ($this->overseasDeliveryPolicy['data']['standardFl'] === 'ems' && $this->totalDeliveryWeight['total'] > 30) {
            $this->emsDeliveryPossible = false;
        }

        //회원구매제한 체크 order.php 의 useSettleKind 함수에서도 한번 체크함
        if (!in_array('false', $this->payLimit)) { // 상품별 결제수단 체크에서 결제가능한 결제수단이 없는것으로 나왔을때는 처리필요없음
            if($this->isWrite === true){
                if (empty($this->_memInfo) === false && !in_array('gb', $this->_memInfo['settleGb'])) {
                    if (empty($this->payLimit)) {
                        $this->payLimit = $this->_memInfo['settleGb'];
                    } else {
                        if($this->_memInfo['settleGb'] != 'all') {
                            $payLimit = array_intersect($this->_memInfo['settleGb'], $this->payLimit);
                            if (count($this->payLimit) > 0 && !in_array('false', $this->payLimit) && count($payLimit) == 0) {
                                $this->payLimit = ['false'];
                            } else {
                                $this->payLimit = $payLimit;
                            }
                        }
                    }
                }
            }
            else {
                if (empty($this->_memInfo) === false) {
                    if (empty($this->payLimit)) {
                        $this->payLimit = $this->_memInfo['settleGb'];
                    } else {
                        $payLimit = array_intersect($this->_memInfo['settleGb'], $this->payLimit);
                        if (count($this->payLimit) > 0 && !in_array('false', $this->payLimit) && count($payLimit) == 0) {
                            $this->payLimit = ['false'];
                        } else {
                            $this->payLimit = $payLimit;
                        }
                    }
                }
            }
        }

        // 장바구니 상품에 대한 계산된 정보
        $getCart = $this->getCartDataInfo($getData, $postValue);

        // 글로벌 해외배송 조건에 따라서 처리
        if (Globals::get('gGlobal.isFront') || $this->isAdminGlobal === true) {
            if ($address !== null) {
                $getCart = $this->getOverseasDeliveryDataInfo($getCart, array_column($getData, 'deliverySno'), $address);
            }
        } else {
            // 복수배송지 사용시 배송정보 재설정
            if ((\Component\Order\OrderMultiShipping::isUseMultiShipping() === true && $postValue['multiShippingFl'] == 'y') || $postValue['isAdminMultiShippingFl'] === 'y') {
                foreach ($postValue['orderInfoCdData'] as $key => $val) {
                    $tmpGetCart = [];
                    $tmpAllGetKey = [];
                    $tmpDeliverySnos = [];
                    foreach ($val as $tVal) {
                        $tmpScmNo = $this->multiShippingOrderInfo[$tVal]['scmNo'];
                        $tmpDeliverySno = $this->multiShippingOrderInfo[$tVal]['deliverySno'];
                        $tmpGetKey = $this->multiShippingOrderInfo[$tVal]['getKey'];
                        $tmpAllGetKey[] = $tmpGetKey;
                        $tmpDeliverySnos[] = $tmpDeliverySno;

                        $tmpGetCart[$tmpScmNo][$tmpDeliverySno][$tmpGetKey] = $getCart[$tmpScmNo][$tmpDeliverySno][$tmpGetKey];
                    }
                    if ($key > 0) {
                        $multiAddress = str_replace(' ', '', $postValue['receiverAddressAdd'][$key] . $postValue['receiverAddressSubAdd'][$key]);
                    } else {
                        $multiAddress = $address;
                    }

                    $tmpGetCart = $this->getDeliveryDataInfo($tmpGetCart, $tmpDeliverySnos, $multiAddress, $postValue['multiShippingFl'], $key);
                    foreach ($tmpGetCart as $sKey => $sVal) {
                        foreach ($sVal as $dKey => $dVal) {
                            foreach ($dVal as $getKey => $getVal) {
                                if (empty($tmpGetCart[$sKey][$dKey][$getKey]) === false) {
                                    $getCart[$sKey][$dKey][$getKey] = $tmpGetCart[$sKey][$dKey][$getKey];
                                }
                            }

                        }
                    }
                    unset($tmpGetCart);
                }
            } else {
                $getCart = $this->getDeliveryDataInfo($getCart, array_column($getData, 'deliverySno'), $address);
            }
        }

        // 장바구니 SCM 정보
        if (is_array($getCart)) {
            $scmClass = \App::load(\Component\Scm\Scm::class);
            $this->cartScmCnt = count($getCart);
            $this->cartScmInfo = $scmClass->getCartScmInfo(array_keys($getCart));
        }

        // 회원 할인 총 금액
        if ($this->getChannel() != 'naverpay') {
            $this->totalSumMemberDcPrice = $this->totalMemberDcPrice + $this->totalMemberOverlapDcPrice;
        }
        // 총 부가세율
        $this->totalVatRate = gd_tax_rate($this->totalGoodsPrice, $this->totalPriceSupply);

        // 비과세 설정에 따른 세금계산서 출력 여부
        if ($this->taxInvoice === true && $this->taxGoodsChk === false) {
            $this->taxInvoice = false;
        }

        // 총 결제 금액 (상품별 금액 + 배송비 - 상품할인 - 회원할인 - 사용마일리지(X) - 상품쿠폰할인 - 주문쿠폰할인(X) - 마이앱할인 : 여기서는 장바구니에 있는 상품에 대해서만 계산하므로 결제 예정금액임)
        // 주문관련 할인금액 및 마일리지/예치금 사용은 setOrderSettleCalculation에서 별도로 계산됨

        $this->totalSettlePrice = $this->totalGoodsPrice + $this->totalDeliveryCharge - $this->totalGoodsDcPrice - $this->totalSumMemberDcPrice - $this->totalCouponGoodsDcPrice;

        // 마이앱 사용에 따른 분기 처리
        if ($this->useMyapp) {
            $this->totalSettlePrice -= $this->totalMyappDcPrice;
        }

		//$this->totalDeliveryCharge = 0;

        if($this->totalSettlePrice < 0 ) $this->totalSettlePrice = 0;

        // 총 적립 마일리지 (상품별 총 상품 마일리지 + 회원 그룹 총 마일리지 + 총 상품 쿠폰 마일리지 + 총 주문 쿠폰 적립 금액 : 여기서는 장바구니에 있는 상품에 대해서만 계산하므로 적립 예정금액임)
        $this->totalMileage = $this->totalGoodsMileage + $this->totalMemberMileage + $this->totalCouponGoodsMileage + $this->totalCouponOrderMileage;

        // 주문에 추가상품 분리데이터를 저장하기 위해 별도 생성 (추가상품 안분까지 적용된 데이터를 가지고와 처리)
        if ($isAddGoodsDivision !== false) {
            // 최종 반환할 $getCart 변수 재설정
            $tmpGetCart = [];

            foreach ($getCart as $sKey => $sVal) {
                foreach ($sVal as $dKey => $dVal) {
                    foreach ($dVal as $gKey => $gVal) {
                        // 기본 상품에 속해있던 추가상품 관련 데이터 정리
                        if ($gVal['price']['goodsPriceSubtotal'] > 0) {
                            $gVal['price']['goodsPriceSubtotal'] -= $gVal['price']['addGoodsPriceSum'];
                            if ($gVal['price']['goodsPriceSubtotal'] < 0) $gVal['price']['goodsPriceSubtotal'] = 0;
                            $gVal['price']['addGoodsPriceSum'] = 0;
                        }

                        $gVal['price']['goodsPriceTotal'] = ($gVal['price']['goodsPriceSum'] + $gVal['price']['optionPriceSum'] + $gVal['price']['optionTextPriceSum']) - ($gVal['price']['goodsDcPrice'] + $gVal['price']['goodsMemberDcPrice'] + $gVal['price']['goodsMemberOverlapDcPrice'] + $gVal['price']['goodsCouponGoodsDcPrice']);

                        // 마이앱 사용에 따른 분기 처리
                        if ($this->useMyapp) {
                            $gVal['price']['goodsPriceTotal'] += $gVal['price']['myappDcPrice'];
                        }

                        // 총 상품 무게 계산
                        $gVal['goodsWeight'] = $gVal['goodsWeight'] * $gVal['goodsCnt'];

                        // 추가상품 변수에 담고 언셋
                        $addGoods = $gVal['addGoods'];
                        unset($gVal['addGoods']);

                        // 기존 상품정보에 추가 내용
                        $gVal['goodsType'] = self::CART_GOODS_TYPE_GOODS;

                        // 원래 상품정보 그대로 추가
                        $tmpGetCart[$sKey][$dKey][] = $gVal;

                        // 추가상품 배열화
                        if (empty($addGoods) === false) {
                            // 추가상품을 상품화시켜 담을 배열
                            foreach ($addGoods as $aKey => $aVal) {
                                // 초기화
                                $tmpAddGoods = [];

                                // 부모 상품의 기본 정보 초기화
                                $tmpAddGoods['goodsType'] = self::CART_GOODS_TYPE_ADDGOODS;
                                $tmpAddGoods['optionTextFl'] = 'n';
                                $tmpAddGoods['goodsDiscountFl'] = 'n';
                                $tmpAddGoods['goodsDiscount'] = 0;
                                $tmpAddGoods['goodsDiscountUnit'] = '';
                                $tmpAddGoods['couponBenefitExcept'] = 'y';

                                // 부모 상품의 설정을 상속받아 설정
                                $tmpAddGoods['sno'] = $gVal['sno'];
                                $tmpAddGoods['siteKey'] = $gVal['siteKey'];
                                $tmpAddGoods['directCart'] = $gVal['directCart'];
                                $tmpAddGoods['memNo'] = $gVal['memNo'];
                                $tmpAddGoods['deliveryCollectFl'] = $gVal['deliveryCollectFl'];
                                $tmpAddGoods['tmpOrderNo'] = $gVal['tmpOrderNo'];
                                $tmpAddGoods['goodsDisplayMobileFl'] = $gVal['goodsDisplayMobileFl'];
                                $tmpAddGoods['goodsSellFl'] = $gVal['goodsSellFl'];
                                $tmpAddGoods['goodsSellMobileFl'] = $gVal['goodsSellMobileFl'];
                                $tmpAddGoods['cateCd'] = $gVal['cateCd'];
                                $tmpAddGoods['deliverySno'] = $gVal['deliverySno'];
                                $tmpAddGoods['memberBenefitExcept'] = $gVal['memberBenefitExcept'];
                                $tmpAddGoods['goodsMileageExcept'] = $gVal['goodsMileageExcept'];
                                $tmpAddGoods['mileageFl'] = $gVal['mileageFl'];
                                $tmpAddGoods['mileageGoods'] = $gVal['mileageGoods'];
                                $tmpAddGoods['mileageGoodsUnit'] = $gVal['mileageGoodsUnit'];
                                $tmpAddGoods['addDcFl'] = $gVal['addDcFl'];
                                $tmpAddGoods['overlapDcFl'] = $gVal['overlapDcFl'];
                                $tmpAddGoods['orderPossible'] = $gVal['orderPossible'];
                                $tmpAddGoods['payLimitFl'] = $gVal['payLimitFl'];
                                $tmpAddGoods['payLimit'] = $gVal['payLimit'];
                                $tmpAddGoods['goodsPermission'] = $gVal['goodsPermission'];
                                $tmpAddGoods['goodsPermissionGroup'] = $gVal['goodsPermissionGroup'];
                                $tmpAddGoods['onlyAdultFl'] = $gVal['onlyAdultFl'];
                                $tmpAddGoods['goodsDeliveryFl'] = $gVal['goodsDeliveryFl'];
                                $tmpAddGoods['goodsDeliveryFixFl'] = $gVal['goodsDeliveryFixFl'];
                                $tmpAddGoods['goodsDeliveryMethod'] = $gVal['goodsDeliveryMethod'];
                                $tmpAddGoods['goodsDeliveryCollectFl'] = $gVal['goodsDeliveryCollectFl'];
                                $tmpAddGoods['goodsDeliveryWholeFreeFl'] = $gVal['goodsDeliveryWholeFreeFl'];
                                $tmpAddGoods['goodsDeliveryTaxFreeFl'] = $gVal['goodsDeliveryTaxFreeFl'];
                                $tmpAddGoods['goodsDeliveryTaxPercent'] = $gVal['goodsDeliveryTaxPercent'];

                                // 추가상품에서 가져온 정보 저장
                                $tmpAddGoods['goodsNo'] = $tmpAddGoods['addGoodsNo'] = $aVal['addGoodsNo'];
                                $tmpAddGoods['goodsCnt'] = $aVal['addGoodsCnt'];
                                $tmpAddGoods['goodsNm'] = $aVal['addGoodsNm'];
                                if($mallBySession && $aVal['addGoodsNmStandard']) {
                                    $tmpAddGoods['goodsNmStandard'] = $aVal['addGoodsNmStandard'];
                                }
                                $tmpAddGoods['scmNo'] = $aVal['scmNo'];
                                $tmpAddGoods['purchaseNo'] = $aVal['purchaseNo'];
                                $tmpAddGoods['commission'] = $aVal['commission'];
                                $tmpAddGoods['goodsCd'] = $aVal['goodsCd'];
                                $tmpAddGoods['goodsModelNo'] = $aVal['goodsModelNo'];
                                $tmpAddGoods['brandCd'] = $aVal['brandCd'];
                                $tmpAddGoods['makerNm'] = $aVal['makerNm'];
                                $tmpAddGoods['goodsDisplayFl'] = $aVal['viewFl'];
                                $tmpAddGoods['stockUseFl'] = $aVal['stockUseFl'];
                                $tmpAddGoods['stockCnt'] = $aVal['stockCnt'];
                                $tmpAddGoods['soldOutFl'] = $aVal['soldOutFl'];
                                $tmpAddGoods['taxFreeFl'] = $aVal['taxFreeFl'];
                                $tmpAddGoods['taxPercent'] = $aVal['taxPercent'];
                                $tmpAddGoods['goodsImage'] = $aVal['addGoodsImage'];
                                $tmpAddGoods['parentMustFl'] = $gVal['addGoodsMustFl'];
                                $tmpAddGoods['parentGoodsNo'] = $gVal['goodsNo'];
                                $tmpAddGoods['price']['goodsPrice'] = $aVal['addGoodsPrice'];
                                $tmpAddGoods['price']['costPrice'] = $aVal['addCostGoodsPrice'];
                                $tmpAddGoods['price']['goodsMemberDcPrice'] = $aVal['addGoodsMemberDcPrice'];
                                $tmpAddGoods['price']['goodsMemberOverlapDcPrice'] = $aVal['addGoodsMemberOverlapDcPrice'];
                                $tmpAddGoods['price']['goodsCouponGoodsDcPrice'] = $aVal['addGoodsCouponGoodsDcPrice'];
                                $tmpAddGoods['price']['goodsPriceSum'] = ($aVal['addGoodsPrice'] * $aVal['addGoodsCnt']);
                                $tmpAddGoods['price']['goodsPriceSubtotal'] = $tmpAddGoods['price']['goodsPriceSum'];
//                                $tmpAddGoods['price']['goodsPriceSubtotal'] = ($tmpAddGoods['price']['goodsPriceSum'] - $aVal['addGoodsMemberDcPrice'] - $aVal['addGoodsMemberOverlapDcPrice']);
                                $tmpAddGoods['price']['goodsPriceTotal'] = ($tmpAddGoods['price']['goodsPriceSum'] - $aVal['addGoodsMemberDcPrice'] - $aVal['addGoodsMemberOverlapDcPrice'] - $aVal['addGoodsCouponGoodsDcPrice']);
                                $tmpAddGoods['mileage']['goodsGoodsMileage'] = $aVal['addGoodsGoodsMileage'];
                                $tmpAddGoods['mileage']['goodsMemberMileage'] = $aVal['addGoodsMemberMileage'];
                                $tmpAddGoods['mileage']['goodsCouponGoodsMileage'] = $aVal['addGoodsCouponGoodsMileage'];

                                // 상품 옵션 처리
                                $tmpAddGoods['option'] = [];
                                if (empty($aVal['optionNm']) === false) {
                                    $tmp = explode(STR_DIVISION, $aVal['optionNm']);
                                    for ($i = 0; $i < 1; $i++) {
                                        $tmpAddGoods['option'][$i]['optionName'] = '';
                                        $tmpAddGoods['option'][$i]['optionValue'] = $aVal['optionNm'];
                                    }
                                    unset($tmp);
                                }
                                unset($tmpAddGoods['optionName']);

                                // 재정의 배열에 추가
                                $tmpGetCart[$sKey][$dKey][] = $tmpAddGoods;
                                unset($tmpAddGoods);
                            }
                        }
                    }
                }
            }
            unset($getCart);

            // 장바구니
            $getCart = $tmpGetCart;
        }
        unset($getData, $arrTmp);

        return $getCart;
    }


	 /**
     * 실결제 금액(settlePrice)를 계산해 반환한다.
     * 장바구니 상품의 최종 계산으로 주문서 작성단계에서 발생되는 사용 쿠폰/마일리지/예치금등의 데이터를 설정하고 반환한다.
     * 이곳은 주문서에 입력된 금액을 토대로 최종 결제금액을 완성한다.
     * 총결제금액, SCM별 금액, 배송비, 각종 할인정보 및 로그를 처리
     *
     * @dependency getCartGoodsData() 반드시 먼저 실행된 후 계산된 값을 이용해 작동한다
     *
     * @param array $requestData 사용한 마일리지/예치금 정보가 담긴 reqeust 정보
     *
     * @return array 결제시 저장되는 최종 상품들의 정보
     * @throws Exception
     */
    public function setOrderSettleCalculation($requestData)
    {
		// 신규회원 체크
		if (!is_object($this->db)) {
			$this->db = \App::load('DB');
		}		

		$firstOrderStat = "0";
		if(Session::get('member.memNo') > 0){		
			$strSQL = "SELECT groupSno, regDt FROM es_member WHERE memNo='".Session::get('member.memNo')."' LIMIT 1";						        
			$mem = $this->db->query_fetch($strSQL, $arrBind, false);		

			$strSQL = "SELECT orderNo FROM es_order WHERE memNo='".Session::get('member.memNo')."' AND orderStatus IN ('o1', 'p1', 'g1', 'g2', 'g3', 'g4', 'd1', 'd2', 's1', 'e1', 'e2', 'e3', 'e4', 'e5') LIMIT 1";						        
			$firstOrder = $this->db->query_fetch($strSQL, $arrBind, false);		

			$timestamp = strtotime($mem['regDt']."+4 days");
			$timechk = strtotime(date("Y-m-d", $timestamp)." 00:00:00");
			$timenow = strtotime(date("Y-m-d H:i:s"));
			
			if($timenow < $timechk && $firstOrder['orderNo'] == "") {								
				$this->totalDeliveryCharge = 0;				
				$firstOrderStat = "1";
			}
		}   

        // 전체 할인금액 초기화 = 총 상품금액 - 총 상품할인 적용된 결제금액
        $this->totalDcPrice = $this->totalGoodsPrice + $this->totalDeliveryCharge - $this->totalSettlePrice;
        $orderPrice['totalOrderDcPrice'] = $this->totalDcPrice;

        // 회원 쿠폰 번호 없이 쿠폰 할인 / 적립 금액이 넘어 온 경우 경고
        if (!$requestData['couponApplyOrderNo']) {
            if ($requestData['totalCouponOrderDcPrice'] > 0) {
                throw new Exception(__("결제 할 금액이 일치하지 않습니다.\n할인/적립 금액이 변경되었을 수 있습니다.\n새로고침 후 다시 시도해 주세요.(4)"));
            }
            if ($requestData['totalCouponDeliveryDcPrice'] > 0) {
                throw new Exception(__("결제 할 금액이 일치하지 않습니다.\n할인/적립 금액이 변경되었을 수 있습니다.\n새로고침 후 다시 시도해 주세요.(5)"));
            }
            if ($requestData['totalCouponOrderMileage'] > 0) {
                throw new Exception(__("결제 할 금액이 일치하지 않습니다.\n할인/적립 금액이 변경되었을 수 있습니다.\n새로고침 후 다시 시도해 주세요.(6)"));
            }
        }

        // 주문 쿠폰 계산 - 쿠폰사용에 따른 회원등급부분이 취소가 되는 경우가있어서 예치금이나 마일리지보다 먼저 계산하도록 순서를 바꿈
        $couponNos = explode(INT_DIVISION, $requestData['couponApplyOrderNo']);
        if (count($couponNos) > 0) {
            if ($requestData['couponApplyOrderNo'] != '') {
                $goodsPriceArr = [
                    'goodsPriceSum' => $this->totalPrice['goodsPrice'],
                    'optionPriceSum' => $this->totalPrice['optionPrice'],
                    'optionTextPriceSum' => $this->totalPrice['optionTextPrice'],
                    'addGoodsPriceSum' => $this->totalPrice['addGoodsPrice'],
                ];
                $coupon = \App::load(\Component\Coupon\Coupon::class);
                $orderCouponPrice = $coupon->getMemberCouponPrice($goodsPriceArr, $requestData['couponApplyOrderNo']);
                foreach ($orderCouponPrice['memberCouponAlertMsg'] as $orderCouponNo => $limitMsg) {
                    if ($limitMsg) {
                        unset($orderCouponPrice['memberCouponAddMileage'][$orderCouponNo]);
                        unset($orderCouponPrice['memberCouponSalePrice'][$orderCouponNo]);
                        unset($orderCouponPrice['memberCouponDeliveryPrice'][$orderCouponNo]);
                    }
                }
                $totalCouponOrderDcPrice = array_sum($orderCouponPrice['memberCouponSalePrice']);
                $totalCouponDeliveryDcPrice = array_sum($orderCouponPrice['memberCouponDeliveryPrice']);
                $totalCouponOrderMileage = array_sum($orderCouponPrice['memberCouponAddMileage']);

                gd_isset($totalCouponOrderDcPrice, 0);
                gd_isset($totalCouponDeliveryDcPrice, 0);
                gd_isset($totalCouponOrderMileage, 0);

                gd_isset($requestData['totalCouponOrderDcPrice'], 0);
                gd_isset($requestData['totalCouponDeliveryDcPrice'], 0);
                gd_isset($requestData['totalCouponOrderMileage'], 0);

                if ($requestData['totalCouponOrderDcPrice'] > $totalCouponOrderDcPrice) {
                    throw new Exception(__("결제 할 금액이 일치하지 않습니다.\n할인/적립 금액이 변경되었을 수 있습니다.\n새로고침 후 다시 시도해 주세요.(1)"));
                }
                if ($requestData['totalCouponDeliveryDcPrice'] > $totalCouponDeliveryDcPrice) {
                    throw new Exception(__("결제 할 금액이 일치하지 않습니다.\n할인/적립 금액이 변경되었을 수 있습니다.\n새로고침 후 다시 시도해 주세요.(2)"));
                }
                if ($requestData['totalCouponOrderMileage'] > $totalCouponOrderMileage) {
                    throw new Exception(__("결제 할 금액이 일치하지 않습니다.\n할인/적립 금액이 변경되었을 수 있습니다.\n새로고침 후 다시 시도해 주세요.(3)"));
                }

                if ($requestData['totalCouponOrderDcPrice'] > 0) {
                    $this->totalCouponOrderDcPrice = $requestData['totalCouponOrderDcPrice'];
                }
                if ($requestData['totalCouponDeliveryDcPrice'] > 0) {
                    $this->totalCouponDeliveryDcPrice = $requestData['totalCouponDeliveryDcPrice'];
                }
                if ($requestData['totalCouponOrderMileage'] > 0) {
                    $this->totalCouponOrderMileage = $requestData['totalCouponOrderMileage'];
                }
            }

            // 쿠폰을 사용했고 사용설정에 쿠폰만 사용설정일때 처리
            if ($requestData['totalCouponOrderDcPrice'] > 0 || $requestData['totalCouponDeliveryDcPrice'] > 0 || $requestData['totalCouponOrderMileage'] > 0) {
                $couponConfig = gd_policy('coupon.config');
                if ($couponConfig['couponUseType'] == 'y' && $couponConfig['chooseCouponMemberUseType'] == 'coupon') {
                    $this->totalSettlePrice += $this->totalSumMemberDcPrice;
                    $this->totalMileage -= $this->totalMemberMileage;
                    $this->totalSumMemberDcPrice = 0;
                    $this->totalMemberDcPrice = 0;
                    $this->totalMemberOverlapDcPrice = 0;
                    $this->totalMemberMileage = 0;
                }

                if ($couponConfig['chooseCouponMemberUseType'] == 'member') {
                    $this->changePrice = false;
                }
            }

            $this->totalSettlePrice -= ($this->totalCouponOrderDcPrice + $this->totalCouponDeliveryDcPrice);
            $this->totalDcPrice += ($this->totalCouponOrderDcPrice + $this->totalCouponDeliveryDcPrice);
            $this->totalMileage += $this->totalCouponOrderMileage;
        }

        // 배송비 혜택이 무료이고 스킨패치가 적용되있을 경우 해당 주문에 적용된 배송비 0원처리
        if ((empty($this->deliveryFree) === false && $this->_memInfo['deliveryFree'] == 'y') || $firstOrderStat == "1") {
            $this->totalDeliveryFreeCharge = $this->totalDeliveryCharge - array_sum($this->totalGoodsDeliveryAreaPrice);
            $this->totalSettlePrice -= $this->totalDeliveryFreeCharge;
            $this->totalDcPrice += $this->totalDeliveryFreeCharge;
            $orderPrice['totalMemberDeliveryDcPrice'] = $this->totalDeliveryFreeCharge;
        }

        // 예치금 사용 여부에 따른 금액 설정
        $useDeposit = $this->getUserOrderDeposit(gd_isset($requestData['useDeposit'], 0));
        $orderPrice['useDeposit'] = $useDeposit['useDeposit'];

        // 예치금 설정 및 총 결제금액 반영
        if ($this->totalSettlePrice < 0) {
            // 사용 예치금 체크 (총결제금액이 -인경우 사용 예치금에서 제외)
            $this->totalUseDeposit = $this->totalUseDeposit + $this->totalSettlePrice;
            $this->totalSettlePrice = 0;
        } else {
            $this->totalUseDeposit = $orderPrice['useDeposit'];
            $this->totalSettlePrice -= $orderPrice['useDeposit'];
        }
        $this->totalDcPrice += $this->totalUseDeposit;

        // 마일리지 사용 여부에 따른 금액 설정
        $useMileage = $this->getUseOrderMileage(gd_isset($requestData['useMileage'], 0));
        $orderPrice['useMileage'] = $useMileage['useMileage'];

        // 마일리지 설정 및 총 결제금액 반영
        if ($this->totalSettlePrice < 0) {
            // 사용 마일리지 체크 (총결제금액이 -인경우 사용 마일리지에서 제외)
            $this->totalUseMileage = $this->totalUseMileage + $this->totalSettlePrice;
            $this->totalSettlePrice = 0;
        } else {
            $this->totalUseMileage = $orderPrice['useMileage'];
            $this->totalSettlePrice -= $orderPrice['useMileage'];
        }
        $this->totalDcPrice += $this->totalUseMileage;

        // 실 상품금액 = 상품금액 + 쿠폰사용금액 (순수 상품 합계금액)
        $orderPrice['totalGoodsPrice'] = $this->totalGoodsPrice;

        // 쿠폰 계산을 위한 실제 할인이 되기전에 적용된 상품판매가
        $orderPrice['totalSumGoodsPrice'] = $this->totalPrice;

        // 배송비 (전체 = 정책배송비 + 지역별배송비)
        $orderPrice['totalDeliveryCharge'] = $this->totalDeliveryCharge;
        $orderPrice['totalGoodsDeliveryPolicyCharge'] = $this->totalGoodsDeliveryPolicyCharge;
        $orderPrice['totalScmGoodsDeliveryCharge'] = $this->totalScmGoodsDeliveryCharge;
        $orderPrice['totalGoodsDeliveryAreaCharge'] = $this->totalGoodsDeliveryAreaPrice;
        if ((\Component\Order\OrderMultiShipping::isUseMultiShipping() === true && \Globals::get('gGlobal.isFront') === false && $requestData['multiShippingFl'] == 'y') || $requestData['isAdminMultiShippingFl'] === 'y') {
            $orderPrice['totalGoodsMultiDeliveryAreaPrice'] = $this->totalGoodsMultiDeliveryAreaPrice;
            $orderPrice['totalGoodsMultiDeliveryPolicyCharge'] = $this->totalGoodsMultiDeliveryPolicyCharge;
            $orderPrice['totalScmGoodsMultiDeliveryCharge'] = $this->totalScmGoodsMultiDeliveryCharge;
        }

        // 해외배송 보험료
        $orderPrice['totalDeliveryInsuranceFee'] = 0;
        if ($this->overseasDeliveryPolicy['data']['standardFl'] === 'ems' && $this->overseasDeliveryPolicy['data']['insuranceFl'] === 'y') {
            $orderPrice['totalDeliveryInsuranceFee'] = $this->setDeliveryInsuranceFee($this->totalGoodsPrice);
            $this->totalSettlePrice += $orderPrice['totalDeliveryInsuranceFee'];
        }

        // 해외배송 총 무게
        $orderPrice['totalDeliveryWeight'] = $this->totalDeliveryWeight;

        // 배송비 착불 금액 넘겨 받기 (collectPrice|wholefreeprice)
        foreach ($this->setDeliveryInfo as $dKey => $dVal) {
            $orderPrice['totalDeliveryCollectPrice'][$dKey] = $dVal['goodsDeliveryCollectPrice'];
            $orderPrice['totalDeliveryWholeFreePrice'][$dKey] = $dVal['goodsDeliveryWholeFreePrice'];
        }

        // 총 상품 할인 금액
        $orderPrice['totalGoodsDcPrice'] = $this->totalGoodsDcPrice;

        // 총 회원 할인 금액
        $orderPrice['totalSumMemberDcPrice'] = $this->totalSumMemberDcPrice;
        $orderPrice['totalMemberDcPrice'] = $this->totalMemberDcPrice;//총 회원할인 금액
        $orderPrice['totalMemberOverlapDcPrice'] = $this->totalMemberOverlapDcPrice;//총 그룹별 회원 중복할인 금액

        // 쿠폰할인액 = 상품쿠폰 + 주문쿠폰 + 배송비쿠폰
        $orderPrice['totalCouponDcPrice'] = ($this->totalCouponGoodsDcPrice + $this->totalCouponOrderDcPrice + $this->totalCouponDeliveryDcPrice);
        $orderPrice['totalCouponGoodsDcPrice'] = $this->totalCouponGoodsDcPrice;
        $orderPrice['totalCouponOrderDcPrice'] = $this->totalCouponOrderDcPrice;
        $orderPrice['totalCouponDeliveryDcPrice'] = $this->totalCouponDeliveryDcPrice;

        // 마이앱 사용에 따른 분기 처리
        if ($this->useMyapp) {
            // 총 마이앱 할인 금액
            $orderPrice['totalMyappDcPrice'] = $this->totalMyappDcPrice;
        }

        // 주문할인금액 안분을 위한 순수상품금액 = 상품금액(옵션/텍스트옵션가 포함) + 추가상품금액 - 상품할인 - 회원할인 - 상품쿠폰할인 - 마이앱할인
        $orderPrice['settleTotalGoodsPrice'] = $this->totalGoodsPrice - $this->totalGoodsDcPrice - $this->totalSumMemberDcPrice - $this->totalCouponGoodsDcPrice;

        // 마이앱 사용에 따른 분기 처리
        if ($this->useMyapp) {
            $orderPrice['settleTotalGoodsPrice'] -= $this->totalMyappDcPrice;
        }

        // 배송비할인금액 안분을 위한 순수배송비금액 = 정책배송비 + 지역배송비 - 배송비 할인쿠폰 - 회원 배송비 무료
        $orderPrice['settleTotalDeliveryCharge'] = $this->totalDeliveryCharge - $this->totalCouponDeliveryDcPrice - $this->totalDeliveryFreeCharge;

        // 주문할인금액 안분을 위한 순수상품금액 + 실 배송비 - 배송비 할인쿠폰
        $orderPrice['settleTotalGoodsPriceWithDelivery'] = $orderPrice['settleTotalGoodsPrice'] + $orderPrice['settleTotalDeliveryCharge'];// 배송비 포함

        // 마일리지를 사용한 경우 지급 예외 처리
        if ($this->mileageGiveExclude == 'n' && $this->totalUseMileage > 0) {
            $orderPrice['totalGoodsMileage'] = 0;// 총 상품 적립 마일리지
            $orderPrice['totalMemberMileage'] = 0;// 총 회원 적립 마일리지
            $orderPrice['totalCouponGoodsMileage'] = 0;// 총 상품쿠폰 적립 마일리지
            $orderPrice['totalCouponOrderMileage'] = 0;// 총 주문쿠폰 적립 마일리지
            $orderPrice['totalMileage'] = 0;
        } else {
            $orderPrice['totalGoodsMileage'] = $this->totalGoodsMileage;// 총 상품 적립 마일리지
            $orderPrice['totalMemberMileage'] = $this->totalMemberMileage;// 총 회원 적립 마일리지
            $orderPrice['totalCouponGoodsMileage'] = $this->totalCouponGoodsMileage;// 총 상품쿠폰 적립 마일리지
            $orderPrice['totalCouponOrderMileage'] = $this->totalCouponOrderMileage;// 총 주문쿠폰 적립 마일리지
            $orderPrice['totalMileage'] = $this->totalMileage;// 총 적립 마일리지 = 총 상품 적립 마일리지 + 총 회원 적립 마일리지 + 총 쿠폰 적립 마일리지
        }

        // 총 주문할인 + 상품 할인 금액
        $orderPrice['totalDcPrice'] = $this->totalDcPrice;

        // 총 주문할인 금액 (복합과세용 금액 산출을 위해 배송비는 제외시킴)
        $orderPrice['totalOrderDcPrice'] = $this->totalCouponOrderDcPrice + $this->totalUseMileage + $this->totalUseDeposit;

        // 마일리지 지급예외 정책 저장
        $orderPrice['mileageGiveExclude'] = $this->mileageGiveExclude;

        // 마일리지/예치금/쿠폰 사용에 따른 실결제 금액 반영
        $orderPrice['settlePrice'] = $this->totalSettlePrice;

        // 해외PG를 위한 승인금액 저장
        $orderPrice['overseasSettlePrice'] = NumberUtils::globalMoneyConvert($orderPrice['settlePrice'], $requestData['overseasSettleCurrency']);
        $orderPrice['overseasSettleCurrency'] = $requestData['overseasSettleCurrency'];

        // 주문하기에서 요청된 실 결제금액
        $requestSettlePrice = str_replace(',', '', $requestData['settlePrice']);

        // 실결제금액 마이너스인 경우
        if ($requestSettlePrice < 0 || $this->totalSettlePrice < 0) {
            throw new Exception(__('결제하실 금액을 다시 확인해주세요. 결제금액은 (-)음수가 될 수 없습니다.'));
        }

        // 배송비 산출을 위한 로직을 타는 경우 제외 처리
        if ($requestData['mode'] != 'check_area_delivery' && $requestData['mode'] != 'check_country_delivery') {
            // 넘어온 결제금액과 다를 경우 예외 처리
            if (gd_money_format($orderPrice['settlePrice'], false) != gd_money_format($requestSettlePrice, false) || $orderPrice['settlePrice'] < 0) {
                throw new Exception(__("결제 할 금액이 일치하지 않습니다.\n할인/적립 금액이 변경되었을 수 있습니다.\n새로고침 후 다시 시도해 주세요."));
            }

            // 해외PG 결제인 경우 금액 비교 체크
            if ($requestData['overseasSettlePrice'] > 0 && empty($requestData['overseasSettleCurrency']) === false) {
                if ($orderPrice['overseasSettlePrice'] != NumberUtils::commaRemover($requestData['overseasSettlePrice'])) {
                    throw new Exception(__('해외PG 승인금액이 일치하지 않습니다.'));
                }
            }
        }

        return $orderPrice;
    }
	
	function getFreeDeliverySno(){
		$strSQL = 'SELECT sno 
						FROM es_scmDeliveryBasic WHERE fixFl = "free" ORDER BY sno DESC LIMIT 1';        		
		$getData = $this->db->query_fetch($strSQL, $arrBind, false);		

		return $getData['sno'];
	}
}