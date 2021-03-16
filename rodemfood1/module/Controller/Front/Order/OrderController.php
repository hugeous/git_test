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
 * @link      http://www.godo.co.kr
 */
namespace Controller\Front\Order;

use Component\Agreement\BuyerInformCode;
use Component\Cart\Cart;
use Component\Database\DBTableField;
use Component\Member\Member;
use Component\Member\Util\MemberUtil;
use Component\Order\Order;
use Component\Mall\Mall;
use Framework\Debug\Exception\AlertRedirectException;
use Framework\Utility\ComponentUtils;
use Framework\Utility\StringUtils;
use Request;
use Session;


/**
 * 주문서 작성
 * @author Shin Donggyu <artherot@godo.co.kr>
 */
class OrderController extends \Bundle\Controller\Front\Order\OrderController
{
	 /**
     * index
     *
     */
    public function index()
    {
        try {
            if (\Component\Order\OrderMultiShipping::isUseMultiShipping() === true) {
                $this->addScript(['gd_multi_shipping.js']);
                $this->setData('isUseMultiShipping', true);
            }

            // 모듈 설정
            $cart = new Cart();
            $order = new Order();
            // 선택된 상품만 주문서 상품으로
            if (Request::get()->has('cartIdx')) {
                $cartIdx = $cart->getOrderSelect(Request::get()->get('cartIdx'));
                $this->setData('cartIdx', $cartIdx);
            }			

            // 장바구니 정보 (최상위 로드)
            $cartInfo = $cart->getCartGoodsData($cartIdx, null, null, false, true);

            $this->setData('cartInfo', $cartInfo);

            // 주문서에 상품이 없는 경우 처리
            if (empty($cartInfo) === true) {
                throw new AlertRedirectException(__('장바구니에 상품이 없습니다. 장바구니에 상품을 담으신 후 주문해주세요.'), null, null, '../main/index.php');
            }

            // 주문불가한 경우 진행 중지
            if (!$cart->orderPossible) {
                if(trim($cart->orderPossibleMessage) !== ''){
                    throw new AlertRedirectException(__($cart->orderPossibleMessage), null, null, '../order/cart.php');
                }
                else {
                    throw new AlertRedirectException(__('구매 불가 상품이 포함되어 있으니 장바구니에서 확인 후 다시 주문해주세요.'), null, null, '../order/cart.php');
                }
            }

            // EMS 배송불가
            if (!$cart->emsDeliveryPossible) {
                throw new AlertRedirectException(__('무게가 %sg 이상의 상품은 구매할 수 없습니다. (배송범위 제한)', '30k'), null, null, '../order/cart.php', 'top');
            }

            // 결제수단이 없는 경우 진행 중지
            if (empty($cart->payLimit) === false && in_array('false', $cart->payLimit)) {
                throw new AlertRedirectException(__('결제 가능한 결제수단이 없으니 장바구니에서 확인 후 다시 주문해주세요.'), null, null, '../order/cart.php');
            }

            // 설정 변경등으로 쿠폰 할인가등이 변경된경우
            if (!$cart->changePrice) {
                throw new AlertRedirectException(__('할인/적립 금액이 변경되었습니다. 상품 결제 금액을 확인해 주세요!'), null, null, '../order/cart.php');
            }

            // 추가 정보
            $addFieldInfo = $order->getOrderAddFieldUseList($cartInfo);
            $this->setData('addFieldInfo', $addFieldInfo);

            // 휴대폰 본인인증 설정
            $dreamInfo = gd_policy('member.auth_cellphone');
            $kcpInfo = gd_policy('member.auth_cellphone_kcp');
            $dreamInfo['useFlKcp']  = $kcpInfo['useFlKcp'];
            $dreamInfo['useDataModifyFlKcp']  = $kcpInfo['useDataModifyFlKcp'];
            $authData = $dreamInfo;			

			// 신규회원 체크
			if (!is_object($this->db)) {
				$this->db = \App::load('DB');
			}	

			// 리뷰 팝업 노출 여부
			$reviewStat = "0";
			if(Session::get('member.memNo') > 0){						
				$strSQL = "SELECT orderGoodsNo as 'orderNo', a.orderNo as 'orderNo1'
							FROM es_order a
								JOIN es_orderGoods b ON a.orderNo=b.orderNo			
								LEFT JOIN es_plusReviewArticle c ON b.sno=c.orderGoodsNo AND c.memNo ='".Session::get('member.memNo')."'
							WHERE a.memNo='".Session::get('member.memNo')."' AND c.orderGoodsNo IS NULL AND a.orderNo != '' AND a.orderStatus IN ('o1', 'p1', 'g1', 'g2', 'g3', 'g4', 'd1', 'd2', 's1', 'e1', 'e2', 'e3', 'e4', 'e5') LIMIT 1";								
				$reviewStat = $this->db->query_fetch($strSQL, $arrBind, false);

				if($reviewStat['orderNo'] == "" && $reviewStat['orderNo1'] != ""){
					$reviewStat = "1";
				}			
			}
			$this->setData('reviewStat', $reviewStat);

			// 100딜 상품 존재여부
			$dealGoodsStat = "0";
			 foreach ($cartInfo as $cInfo) {					
				foreach ($cInfo as $cI) {						
					foreach ($cI as $tempData) {
						$strSQL = "SELECT sno FROM  es_goodsLinkCategory WHERE goodsNo=".$tempData['goodsNo']." AND cateCd = '008' LIMIT 1";						        
						$deal = $this->db->query_fetch($strSQL, $arrBind, false);
						if($deal['sno'] != ""){
							$dealGoodsStat = "1";
						}							
					}
				}
			}

			// 100원딜 상품 구매여부, 가입후 3일이내
			$buyMsg = "";
			if(Session::get('member.memNo') > 0){		
				$strSQL = "SELECT groupSno, regDt FROM es_member WHERE memNo='".Session::get('member.memNo')."' LIMIT 1";						        
				$mem = $this->db->query_fetch($strSQL, $arrBind, false);

				// 장바구니에 담긴 100원딜 상품 갯수
				$strSQL = "SELECT SUM(a.goodsCnt) as cnt FROM es_cart a JOIN es_goodsLinkCategory b ON a.goodsNo=b.goodsNo WHERE cateLinkFl='y' AND a.memNo='".Session::get('member.memNo')."' AND cateCd ='008' LIMIT 1";
				$cartCnt = $this->db->query_fetch($strSQL, $arrBind, false);

				if($cartCnt['cnt'] > 1){
					throw new AlertRedirectException(__('신규회원 이벤트 상품은 가입 후 3일이내 1개만 구매가능합니다. (복수 구매 불가)'), null, null, '../order/cart.php');
				}			
		
				// 100딜 상품 구매여부
				$strSQL = "SELECT a.orderNo 
								FROM es_order a
									JOIN es_orderGoods b ON a.orderNo=b.orderNo
									JOIN es_goodsLinkCategory c ON b.goodsNo=c.goodsNo
								WHERE memNo='".Session::get('member.memNo')."' AND c.cateCd = '008' AND a.orderStatus IN ('o1', 'p1', 'g1', 'g2', 'g3', 'g4', 'd1', 'd2', 's1', 'e1', 'e2', 'e3', 'e4', 'e5') LIMIT 1";						        
				$deal = $this->db->query_fetch($strSQL, $arrBind, false);

				$timestamp = strtotime($mem['regDt']."+4 days");
				$timechk = strtotime(date("Y-m-d", $timestamp)." 00:00:00");
				$timenow = strtotime(date("Y-m-d H:i:s"));
				
				if($timenow < $timechk && empty($deal['orderNo'])) {
					$dateMsg = strtotime($mem['regDt']."+3 days");			
					$buyMsg = "구매조건 : ".date("m",$dateMsg)."월 ".date("d",$dateMsg)."일 23시 59분까지 구매 가능";		

					if($dealGoodsStat == "1" && $cart->totalSettlePrice < 9900 ){
						throw new AlertRedirectException(__('신규회원 이벤트 상품 포함 9,900원 이상 담아주시면 구매 가능합니다.'), null, null, '../order/cart.php');
					}
				} else {
					if($dealGoodsStat == "1"){
						throw new AlertRedirectException(__('신규회원 이벤트 상품은 가입 후 3일이내 1개만 구매가능합니다. (복수 구매 불가)'), null, null, '../order/cart.php');
					}
				}
			} else {				
				if($dealGoodsStat == "1"){
					throw new AlertRedirectException(__('신규회원 이벤트 상품은 가입 후 3일이내 1개만 구매가능합니다. (복수 구매 불가)'), null, null, '../order/cart.php');
				}
			}
			$this->setData('buyMsg', $buyMsg);

			// 마지막 세금계산서 신청 정보

            // 장바구니 생성시 만들어진 금액 정보
            $this->setData('cartCnt', $cart->cartCnt); // 장바구니 수량
            $this->setData('totalGoodsPriceSum', $cart->totalPrice); // 상품 총 (판매가격, 옵션가격, 텍스트 옵션 가격, 추가 상품 가격)
            $this->setData('totalGoodsPrice', $cart->totalGoodsPrice); // 상품 총 가격
            $this->setData('totalGoodsDcPrice', $cart->totalGoodsDcPrice); // 상품 할인 총 가격
            $this->setData('totalGoodsMileage', $cart->totalGoodsMileage); // 상품별 총 상품 마일리지
            $this->setData('totalScmGoodsPrice', $cart->totalScmGoodsPrice); // SCM 별 상품 총 가격
            $this->setData('totalScmGoodsDcPrice', $cart->totalScmGoodsDcPrice); // SCM 별 상품 할인 총 가격
            $this->setData('totalScmGoodsMileage', $cart->totalScmGoodsMileage); // SCM 별 총 상품 마일리지
            $this->setData('totalMemberDcPrice', $cart->totalMemberDcPrice); // 회원 그룹 추가 할인 총 가격
            $this->setData('totalMemberBankDcPrice', 0); // 회원 등급할인 브랜드 무통장 할인 총 가격
            $this->setData('totalMemberOverlapDcPrice', $cart->totalMemberOverlapDcPrice); // 회원 그룹 중복 할인 총 가격
            $this->setData('totalScmMemberDcPrice', $cart->totalScmMemberDcPrice); // scm 별 회원 그룹 추가 할인 총 가격
            $this->setData('totalScmMemberOverlapDcPrice', $cart->totalScmMemberOverlapDcPrice); // scm 별 회원 그룹 중복 할인 총 가격
            $this->setData('totalSumMemberDcPrice', $cart->totalSumMemberDcPrice); // 회원 할인 총 금액
            $this->setData('totalMyappDcPrice', $cart->totalMyappDcPrice); // 마이앱 할인 총 금액
            $this->setData('totalScmMyappDcPrice', $cart->totalScmMyappDcPrice); // SCM 별 마이앱 할인 총 금액
            $this->setData('totalMemberMileage', $cart->totalMemberMileage); // 회원 그룹 총 마일리지
            $this->setData('totalScmMemberMileage', $cart->totalScmMemberMileage); // scm 별 회원 그룹 총 마일리지
            $this->setData('totalCouponGoodsDcPrice', $cart->totalCouponGoodsDcPrice); // 상품 총 쿠폰 금액
            $this->setData('totalScmCouponGoodsDcPrice', $cart->totalScmCouponGoodsDcPrice); // scm 별 상품 총 쿠폰 금액
            $this->setData('totalCouponGoodsMileage', $cart->totalCouponGoodsMileage); // 상품 총 쿠폰 마일리지
            $this->setData('totalScmCouponGoodsMileage', $cart->totalScmCouponGoodsMileage); // scm 별 상품 총 쿠폰 마일리지
            $this->setData('totalDeliveryCharge', $cart->totalDeliveryCharge); // 상품 배송정책별 총 배송 금액
            $this->setData('totalScmGoodsDeliveryCharge', $cart->totalScmGoodsDeliveryCharge); // SCM 별 총 배송 금액
            $this->setData('totalSettlePrice', $cart->totalSettlePrice); // 총 결제 금액 (예정)
            $this->setData('totalMileage', $cart->totalMileage); // 총 적립 마일리지 (예정)
            $this->setData('orderPossible', $cart->orderPossible); // 주문 가능 여부
            $this->setData('setDeliveryInfo', $cart->setDeliveryInfo); // 배송비조건별 배송 정보
            $this->setData('useSettleKindPg', $cart->useSettleKindPg); // 결제가능PG
            $this->setData('isPayLimit', count($cart->payLimit)); // 결제가능PG
            $this->setData('totalDeliveryWeight', $cart->totalDeliveryWeight); // 배송 상품의 무게
            $this->setData('deliveryFree', gd_isset($cart->_memInfo['deliveryFree'], 'n')); // 회원 배송비 무료 여부
            $this->setData('authData', $authData); // 휴대폰 본인인증 설정
            $this->setData('cartGoodsCnt', $cart->cartGoodsCnt);
            $this->setData('cartAddGoodsCnt', $cart->cartAddGoodsCnt);

            // 주문시 마일리지 사용하는 경우 적립마일리지 지급 여부
            $this->setData('mileageGiveExclude', $cart->mileageGiveExclude);

            // 사은품 증정 정책
            $giftConf = gd_policy('goods.gift');
            $this->setData('giftConf', $giftConf);
            if (gd_is_plus_shop(PLUSSHOP_CODE_GIFT) === true && $giftConf['giftFl'] == 'y') {
                // 사은품리스트
                $gift = \App::load('\\Component\\Gift\\Gift');
                $this->setData('giftInfo', $gift->getGiftPresentOrder($cart->giftForData));
            }

            // 마일리지 지급 정보
            $mileage = gd_mileage_give_info();
            $this->setData('mileage', $mileage['info']);

            // 회원 정보
            $memInfo = $this->getData('gMemberInfo');

            // 쿠폰 설정값 정보
            $couponConfig = gd_policy('coupon.config');
            $this->setData('couponUse', gd_isset($couponConfig['couponUseType'], 'n')); // 쿠폰 사용여부
            $this->setData('couponConfig', gd_isset($couponConfig)); // 쿠폰설정
            $this->setData('productCouponChangeLimitType', $couponConfig['productCouponChangeLimitType']); // 상품쿠폰 주문서 제한여부

            // 결제가능한 수단이 무통장인경우 payco미노출처리
            if (!empty($cart->payLimit) && ((in_array('gb', $cart->payLimit) && !in_array('pg', $cart->payLimit)) || (!in_array('gb', $cart->payLimit) && !in_array('pg', $cart->payLimit)))) {
                $onlyBankFl = 'y';
            } else {
                $onlyBankFl = 'n';
            }

            // 마일리지 정책
            // '마일리지 정책에 따른 주문시 사용가능한 범위 제한' 에 사용되는 기준금액 셋팅
            $mileagePrice = $cart->setMileageUseLimitPrice();
            // 마일리지 정책에 따른 주문시 사용가능한 범위 제한
            $mileageUse = $cart->getMileageUseLimit(gd_isset($memInfo['mileage'], 0), $mileagePrice);
            if (gd_isset($cart->payLimit) && in_array('gm', $cart->payLimit) == false) {
                $mileageUse['payUsableFl'] = 'n';
            }
            $this->setData('mileageUse', $mileageUse);

            // 마일리지-쿠폰 동시사용 설정
            if ($couponConfig['couponUseType'] == 'y' && $mileageUse['payUsableFl'] == 'y') { // 마일리지와 쿠폰이 모두 사용상태일때만 동시사용설정 체크
                $memberAccess = gd_policy('member.access');
                $this->setData('chooseMileageCoupon', gd_isset($memberAccess['chooseMileageCoupon'], 'n'));
            } else {
                $this->setData('chooseMileageCoupon', 'n');
            }

            // 예치금 정책
            $depositUse = gd_policy('member.depositConfig');
            if (gd_isset($cart->payLimit) && in_array('gd', $cart->payLimit) == false) {
                $depositUse['payUsableFl'] = 'n';
            }
            $this->setData('depositUse', $depositUse);

            // 무통장 입금 은행
            $bank = $order->getBankInfo(null, 'y');
            $this->setData('bank', $bank);

            // 메일도메인
            $emailDomain = gd_array_change_key_value(gd_code('01004'));
            $emailDomain = array_merge(['self' => __('직접입력')], $emailDomain);
            $this->setData('emailDomain', $emailDomain); // 메일주소 리스팅

            // 개인 정보 수집 동의 - 이용자 동의 사항
            $tmp = gd_buyer_inform('001003');
            $private = $tmp['content'];
            if (gd_is_html($private) === false) {
                $private = nl2br($private);
            }
            $this->setData('private', gd_isset($private));

            // 주문정책
            $this->setData('orderPolicy', $order->orderPolicy);

            // 결제 방법 설정
            if (gd_isset($memInfo['settleGb'], 'all') == 'all') {
                $settleSelect = $cart->couponSettleMethod; // 회원 등급별 결제 방법이 전체 인경우 쿠폰에 따른 결제 방법을 따름
            } else {
                $settleSelect = $memInfo['settleGb']; // 회원 등급별 결제 방법이 제한 인경우 등급에 따른 결제 방법을 따름
            }

            // 주문의 세금정책
            $taxInfo = gd_policy('order.taxInvoice');
            if ($cart->taxInvoice && gd_isset($taxInfo['taxInvoiceUseFl']) == 'y' && gd_isset($taxInfo['taxInvoiceOrderUseFl'], 'y') == 'y' && (gd_isset($taxInfo['gTaxInvoiceFl']) == 'y' || gd_isset($taxInfo['eTaxInvoiceFl']) == 'y')) {
                if (gd_isset($taxInfo['TaxMileageFl']) == 'y' || gd_isset($taxInfo['taxDepositFl']) == 'y') {
                    $receipt['taxZeroFl'] = 'y';
                } else {
                    $receipt['taxZeroFl'] = 'n';
                }

                $receipt['taxFl'] = 'y';

                // 세금계산서 이용안내
                $taxInvoiceInfo = gd_policy('order.taxInvoiceInfo');
                if ($taxInfo['taxinvoiceInfoUseFl'] == 'y') {
                    $this->setData('taxinvoiceInfo', nl2br($taxInvoiceInfo['taxinvoiceInfo']));
                }

                // 세금계산서 입력 정보 가져오기
                if (gd_is_login() === true) {
                    $tax = \App::load('\\Component\\Order\\Tax');
                    $memNo = Session::get('member.memNo');
                    $memberTaxInfo = $order->getLastOrderTaxInfo($memNo);
                    $memberInvoiceInfo['tax'] = $memberTaxInfo;
                }
            } else {
                $receipt['taxFl'] = 'n';
                $receipt['taxZeroFl'] = 'n';
            }
            // 배송비 무료일 경우 세금계산서 노출시 총 결제금액에서 배송비 차감 제외
            if ($cart->_memInfo['deliveryFree'] == 'y') {
                $taxInfo['taxDeliveryFl'] = 'y';
            }
            $this->setData('taxInfo', $taxInfo);

            // 현금 영수증 사용 여부 및 필수 신청 여부
            $pgConf = gd_pgs();
            if ((empty($pgConf['pgId']) === false && $pgConf['cashReceiptFl'] == 'y') && (empty($cart->payLimit) === true || (empty($cart->payLimit) === false && in_array('gb', $cart->payLimit)))) {
                $receipt['cashFl'] = 'y';

                // 현금영수증 입력 정보 가져오기
                if (gd_is_login() === true) {
                    $cashReceipt = \App::load('\\Component\\Payment\\CashReceipt');
                    $memNo = Session::get('member.memNo');
                    $memberCashInfo = $cashReceipt->getMemberCashReceiptInfo($memNo);
                    $memberInvoiceInfo['cash'] = $memberCashInfo;
                }
            } else {
                $receipt['cashFl'] = 'n';
            }

            // 세금계산서/현금영수증 입력 정보
            if (empty($memberInvoiceInfo) == false) {
                $this->setData('memberInvoiceInfo', $memberInvoiceInfo);
            }

            // 영수증 우선 설정 (현금영수증과 안함만 있음)
            $receipt['aboveFl'] = $pgConf['aboveFl'];

            // 영수증 신청 가능한 주문 코드
            // 프론트 주문에서는 계좌이체 가상계좌방식은 영수증 신청 불가능하게 변경
            $settleKindReceiptPossible = array_diff($order->settleKindReceiptPossible, array('pb', 'pv', 'eb', 'ev'));
            $receipt['useReceiptCode'] = json_encode($settleKindReceiptPossible);
            $this->setData('receipt', gd_isset($receipt));

            if((int)Session::get('member.memNo') > 0){
                // 기본 배송지 정보 가져오기
                $defaultShippingAddress = gd_isset(json_encode($order->getDefaultShippingAddress()));
                // 최근배송지 정보 가져오기
                $recentShippingAddress = gd_isset(json_encode($order->getRecentShippingAddress()));

            }
            else {
                // 기본 배송지 정보 가져오기
                $defaultShippingAddress = '[]';
                // 최근배송지 정보 가져오기
                $recentShippingAddress = '[]';
            }
            $this->setData('defaultShippingAddress', $defaultShippingAddress);
            $this->setData('recentShippingAddress', $recentShippingAddress);

            // 페이코 버튼
            foreach ($cartInfo as $cInfo) {
                foreach ($cInfo as $cI) {
                    foreach ($cI as $tempData) {
                        $arrGoodsNo[] = $tempData['goodsNo'];
                    }
                }
            }

            $payco = \App::load('\\Component\\Payment\\Payco\\Payco');
            $paycoEasypaybuttonImage = $payco->getButtonHtmlCode('EASYPAY', false, '', $arrGoodsNo);
            if ($paycoEasypaybuttonImage !== false && $onlyBankFl == 'n') {
                $this->setData('payco', $paycoEasypaybuttonImage);
            }

            // 결제가 가능한 결제 수단 표기
            $settle = $order->useSettleKind($settleSelect, $pgConf, $cart->payLimit);
            if ($onlyBankFl == 'y') {
                foreach ($settle as $k => $v) {
                    if ($k == 'general') {
                        $tempSettle[$k] = $v;
                    }
                }
                $settle = $tempSettle;
            }
            $this->setData('settle', gd_isset($settle));

            //개별결제수단이 설정되어 있는데 모두 다른경우 결제 불가
            $settleOrderPossible = true;
            if (empty($cart->payLimit) === false && in_array('false', $cart->payLimit)) {
                $settleOrderPossible = false;
            }
            $this->setData('settleOrderPossible', gd_isset($settleOrderPossible));

            // --- Template_ 호출
            $this->setData('orderName', Session::get('member.memNm')); // 주문자명 (회원 이름)

            $this->setData('orderItemMode', 'order');

            /** @var  \Bundle\Component\Agreement\BuyerInform $inform */
            $inform = \App::load('\\Component\\Agreement\\BuyerInform');
            $privateGuestOffer = $inform->getAgreementWithReplaceCode(BuyerInformCode::PRIVATE_GUEST_ORDER);
            $this->setData('privateGuestOffer', $privateGuestOffer);

            // 이용약관
            $agreementInfo = $inform->getAgreementWithReplaceCode(BuyerInformCode::AGREEMENT);
            $this->setData('agreementInfo', $agreementInfo);

            // 쇼핑몰 정보
            $mall = new Mall();
            $serviceInfo = $mall->getServiceInfo();
            $this->setData('serviceInfo', $serviceInfo);

            // 공급사 상품이 포함되었는지 여부
            $scmNoList = array_keys($cartInfo);
            $hasScmGoods = false;
            $tmpScmList = [];
            $scmAdmin = \App::load(\Component\Scm\ScmAdmin::class);
            foreach ($scmNoList as $scmNo) {
                if ($scmNo > 1) {
                    $hasScmGoods = true;
                    $tmpScmList[$scmNo] = $scmNo;
                }
            }
            $this->setData('hasScmGoods', $hasScmGoods);
            if ($hasScmGoods) {
                $scmNmList = [];
                $ScmData = $scmAdmin->getScmSelectList(implode(INT_DIVISION,$tmpScmList));
                foreach($ScmData as $val) {
                    $scmNmList[$val['scmNo']] = $val['companyNm'];
                }
                ksort($scmNmList);
                $scmNm['scmNm'] = implode(', ',$scmNmList);
                $privateProvider = $inform->getAgreementWithReplaceCode(BuyerInformCode::PRIVATE_PROVIDER);
                $replaceCode = \App::load(\Component\Design\ReplaceCode::class);
                $replaceCode->setReplaceCodeByScmAgreement($scmNm);
                $privateProvider = $replaceCode->replace($privateProvider);
                $this->setData('privateProvider', $privateProvider);
            }

            // 주문데이터 가져오기
            $orderCountriesCode = $order->getCountriesCode();

            // 주문자 전화용 국가코드 셀렉트 박스 데이터
            foreach ($orderCountriesCode as $key => $val) {
                if ($val['callPrefix'] > 0) {
                    $orderCountryPhone[$val['code']] = __($val['countryNameKor']) . '(+' . $val['callPrefix'] . ')';
                }
            }
            $this->setData('orderCountryPhone', $orderCountryPhone);

            // 국가데이터 가져오기
            $countriesCode = $order->getUsableCountriesList();

            // 전화용 국가코드 셀렉트 박스 데이터
            foreach ($countriesCode as $key => $val) {
                if ($val['callPrefix'] > 0) {
                    $countryPhone[$val['code']] = __($val['countryNameKor']) . '(+' . $val['callPrefix'] . ')';
                }
            }
            $this->setData('countryPhone', $countryPhone);

            // 주소용 국가코드 셀렉트 박스 데이터
            $countryAddress = [];
            foreach ($countriesCode as $key => $val) {
                $countryAddress[$val['code']] = __($val['countryNameKor']) . '(' . $val['countryName'] . ')';
            }
            $this->setData('countryAddress', $countryAddress);

            // 주소셀렉트시 자동 전화번호 선택 처리를 위한 맵핑 데이터
            $countryMapping = [];
            foreach ($countriesCode as $key => $val) {
                $countryMapping[$val['code']] = $val['callPrefix'];
            }
            $this->setData('countryMapping', json_encode($countryMapping));
            $this->setData('recommendCountryCode', SESSION::get(SESSION_GLOBAL_MALL)['recommendCountryCode']);

            $pgPaycoPolicy = ComponentUtils::getPolicy('pg.payco');
            StringUtils::strIsSet($pgPaycoPolicy['useEventPopupYn'], 'n');
            StringUtils::strIsSet($pgPaycoPolicy['paycoSellerKey'], '');
            if ($pgPaycoPolicy['useEventPopupYn'] == 'y' && $pgPaycoPolicy['paycoSellerKey'] != '') {
                $userFilePathResolver = \App::getInstance('user.path');
                $this->addScript([$userFilePathResolver->data('common', 'payco', 'event', 'pc', 'gd_payco_event_popup.js')->www()], true);
            }

            // 안심번호 사용여부
            $orderBasic = gd_policy('order.basic');
            if ($orderBasic['safeNumberServiceFl'] != 'off' && isset($orderBasic['useSafeNumberFl'])) {
                $this->setData('useSafeNumberFl', $orderBasic['useSafeNumberFl']);
            }

            // 상품 옵션가 표시설정 config 불러오기
            $optionPriceConf = gd_policy('goods.display');
            $this->setData('optionPriceFl', gd_isset($optionPriceConf['optionPriceFl'], 'y')); // 상품 옵션가 표시설정
        } // --- 오류 발생시
        catch (Exception $e) {
            // echo ($e->ectMessage);
            // 설정 오류 : 화면 출력용
            if ($e->ectName == 'ERROR_VIEW') {
                $errorMessage = $e->ectMessage;
                $item = ($e->ectMessage ? ' - ' . str_replace('\n', ' - ', gd_isset($errorMessage, $e->ectMessage)) : '');
                throw new AlertRedirectException(__('안내') . $item, null, null, '../order/cart.php', 'parent');

            } else {
                $e->actLog();
                // echo ($e->ectMessage);
                throw new AlertRedirectException(__('오류') . ' - ' . __('오류가 발생 하였습니다.'), null, null, '../order/cart.php', 'parent');
            }
        }
    }
}