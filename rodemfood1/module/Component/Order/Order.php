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
namespace Component\Order;


use App;
use Component\Mail\MailAutoObserver;
use Component\Godo\NaverPayAPI;
use Component\Member\Member;
use Component\Naver\NaverPay;
use Component\Database\DBTableField;
use Component\Delivery\OverseasDelivery;
use Component\Deposit\Deposit;
use Component\ExchangeRate\ExchangeRate;
use Component\Mail\MailMimeAuto;
use Component\Mall\Mall;
use Component\Mall\MallDAO;
use Component\Member\Manager;
use Component\Member\Util\MemberUtil;
use Component\Mileage\Mileage;
use Component\Policy\Policy;
use Component\Sms\Code;
use Component\Sms\SmsAuto;
use Component\Sms\SmsAutoCode;
use Component\Sms\SmsAutoObserver;
use Component\Validator\Validator;
use Encryptor;
use Exception;
use Framework\Application\Bootstrap\Log;
use Framework\Debug\Exception\AlertOnlyException;
use Framework\Debug\Exception\AlertRedirectException;
use Framework\Helper\MallHelper;
use Framework\Utility\ArrayUtils;
use Framework\Utility\ComponentUtils;
use Framework\Utility\NumberUtils;
use Framework\Utility\StringUtils;
use Framework\Utility\UrlUtils;
use Globals;
use Logger;
use LogHandler;
use Request;
use Session;
use Framework\Utility\DateTimeUtils;

/**
 * 주문 class
 * @author Shin Donggyu <artherot@godo.co.kr>
 */
class Order extends \Bundle\Component\Order\Order
{
	/**
     * 주문 프로세스
     *
     * @param array   $cartInfo
     * @param array   $orderInfo
     * @param array   $order
     * @param boolean $isWrite
     *
     * @throws Exception
     * @author Jong-tae Ahn <qnibus@godo.co.kr>
     * @author su
     */
    public function saveOrder($cartInfo, $orderInfo, $order, $isWrite = false)
    {
        // 주문상품이 없는 경우 처리
        if (empty($cartInfo) === true) {
            throw new Exception(__('주문하실 상품이 없습니다.'));
        }

        // 주문번호 생성
        $this->orderNo = $this->generateOrderNo();

        // 주문로그 저장
        \Logger::channel('order')->info('OREDR NO : ' . $this->orderNo, $orderInfo);

        // 주문정보 설정에서 재설정됨
        $this->orderGoodsName = __('주문상품');

        // 결제 금액이 0원인 경우 결제수단을 전액할인(gz)으로 강제 적용 및 주문 채널을 shop 으로 고정
        if ($order['settlePrice'] == 0) {
            $orderInfo['settleKind'] = self::SETTLE_KIND_ZERO;
            $orderInfo['orderChannelFl'] = 'shop';
        }

        // 결제 방법에 따른 주문 단계 설정
        if ($orderInfo['settleKind'] == 'gb') {
            $orderStatusPre = 'o1'; // 무통장입금인 경우 입금대기 상태로
        } elseif ($orderInfo['settleKind'] == self::SETTLE_KIND_ZERO) {
            $orderStatusPre = 'p1'; // 전액할인인 경우 결제완료 상태로
        } else {
            $orderStatusPre = 'f1'; // PG 결제의 경우 결제시도 상태로
        }

        // 회원이 아닌 경우 적립마일리지 0원 처리
        if (empty($order['memNo']) === true || $order['memNo'] == 0) {
            $order['totalGoodsMileage'] = 0;// 총 상품 적립 마일리지
            $order['totalMemberMileage'] = 0;// 총 회원 적립 마일리지
            $order['totalCouponGoodsMileage'] = 0;// 총 상품쿠폰 적립 마일리지
            $order['totalCouponOrderMileage'] = 0;// 총 주문쿠폰 적립 마일리지
            $order['totalMileage'] = 0;
        }

        // 주문 추가 필드 정보
        if ($orderInfo['addFieldConf'] == 'y') {
            $addFieldData = $this->getOrderAddFieldSaveData($orderInfo['addField']);
        }

        // 주문 추가 필드 정보 json 으로 기본 json타입 빈값 처리를 위한 if 밖 처리
        $order['addField'] = json_encode($addFieldData, JSON_UNESCAPED_UNICODE);

        // 장바구니 상품 정보 저장 설정을 위한 초기화
        $orderGoodsCnt = 0;
        $this->arrGoodsName = [];
        $this->arrGoodsNo = [];
        $this->arrGoodsAmt = [];
        $this->arrGoodsCnt = [];
        $goodsCouponInfo = [];
        $arrOrderGoodsSno = [];

        // 주문할인 금액 안분을 위한 데이터 초기화
        $order['divisionUseMileage'] = 0;
        $order['divisionUseDeposit'] = 0;
        $order['divisionCouponDcPrice'] = 0;
        $order['divisionCouponMileage'] = 0;

        // 절사 내용
        $truncPolicy = Globals::get('gTrunc.goods');

        // 쿠폰 정책
        $couponPolicy = gd_policy('coupon.config');

        // 쿠폰 정책에서 쿠폰만사용일때 회원등급 할인 적립금 제거 처리
        $setMemberDcMileageZero = 'F';
        if ($order['totalCouponOrderDcPrice'] > 0 || $order['totalCouponDeliveryDcPrice'] > 0 || $order['totalCouponOrderMileage'] > 0) {
            if ($couponPolicy['couponUseType'] == 'y' && $couponPolicy['chooseCouponMemberUseType'] == 'coupon') {
                $setMemberDcMileageZero = 'T';
            }
        }

        // 배송비에 안분되어야 할 부가결제금액 (0원의 -를 막기위해 차감처리를 위한 변수)
        $tmpMinusDivisionDeliveryUseDeposit = 0;
        $tmpMinusDivisionDeliveryUseMileage = 0;

        // 안분해야 할 데이터 초기화
        $tmpDivisionGoodsUseDepositSum = 0;
        $tmpDivisionGoodsUseMileageSum = 0;
        $tmpDivisionGoodsCouponOrderDcPriceSum = 0;
        $tmpDivisionCouponDeliveryDcPriceSum = 0;
        $tmpDivisionGoodsCouponMileageSum = 0;
        $divisionUseDeposit = $order['useDeposit'];
        $divisionUseMileage = $order['useMileage'];
        $divisionCouponOrderDcPrice = $order['totalCouponOrderDcPrice'];
        $divisionCouponDeliveryDcPrice = $order['totalCouponDeliveryDcPrice'];
        $divisionCouponOrderMileage = $order['totalCouponOrderMileage'];

        // 주문할인 금액 안분 작업
        foreach ($cartInfo as $sKey => $sVal) {
            foreach ($sVal as $dKey => $dVal) {
                $totalOriginGoodsPrice[$dKey] = 0;
                foreach ($dVal as $gKey => $gVal) {
                    // 순수 할인된 상품의 결제금액 (추가상품 제외)
                    $originGoodsPrice = $gVal['price']['goodsPriceSubtotal'];
                    if (is_numeric($gVal['price']['goodsPriceTotal']) === true) {
                        $originGoodsPrice = $gVal['price']['goodsPriceTotal'];
                    }

                    // 최종 배송비의 안분된 예치금/마일리지를 다시 상품으로 안분하기 위한 기준 금액
                    $totalOriginGoodsPrice[$dKey] += $originGoodsPrice;

                    // 전체 순수할인된 상품금액 대비 비율 산정 (소수점까지 표현)
                    $goodsDcRate = ($originGoodsPrice / $order['settleTotalGoodsPrice']);
                    $goodsDcRateWithDelivery = ($originGoodsPrice / $order['settleTotalGoodsPriceWithDelivery']);

                    // 상품번호에 따른 기획전 sno 가져오기
                    // TODO 추후 기획전 검색 들어갈때 작업 예정
                    //                    $arrBindTheme = [];
                    //                    $strWhere = 'goodsNo LIKE concat(\'%\',?,\'%\') AND kind = ?';
                    //                    $this->db->bind_param_push($arrBindTheme, 'i', $gVal['goodsNo']);
                    //                    $this->db->bind_param_push($arrBindTheme, 's', 'event');
                    //                    $strSQL = 'SELECT sno FROM ' . DB_DISPLAY_THEME . ' WHERE ' . $strWhere . ' ORDER BY sno DESC LIMIT 0, 1';
                    //                    $getData = $this->db->query_fetch($strSQL, $arrBindTheme, false);
                    //                    $gVal['evnetSno'] = $getData['sno'];
                    //                    unset($arrBindTheme);


                    // 사용예치금 주문할인 안분 금액
                    $tmpDivisionGoodsUseDeposit = NumberUtils::getNumberFigure($divisionUseDeposit * $goodsDcRateWithDelivery, $truncPolicy['unitPrecision'], 'round');
                    $cartInfo[$sKey][$dKey][$gKey]['price']['divisionUseDeposit'] = $tmpDivisionGoodsUseDeposit;
                    $tmpDivisionGoodsUseDepositSum += $tmpDivisionGoodsUseDeposit;

                    // 사용마일리지 주문할인 안분 금액
                    $tmpDivisionGoodsUseMileage = NumberUtils::getNumberFigure($divisionUseMileage * $goodsDcRateWithDelivery, $truncPolicy['unitPrecision'], 'round');
                    $cartInfo[$sKey][$dKey][$gKey]['price']['divisionUseMileage'] = $tmpDivisionGoodsUseMileage;
                    $tmpDivisionGoodsUseMileageSum += $tmpDivisionGoodsUseMileage;

                    // 사용주문쿠폰 주문할인 안분 금액
                    $tmpDivisionGoodsCouponOrderDcPrice = NumberUtils::getNumberFigure($divisionCouponOrderDcPrice * $goodsDcRate, $truncPolicy['unitPrecision'], 'round');
                    $cartInfo[$sKey][$dKey][$gKey]['price']['divisionCouponOrderDcPrice'] = $tmpDivisionGoodsCouponOrderDcPrice;
                    $tmpDivisionGoodsCouponOrderDcPriceSum += $tmpDivisionGoodsCouponOrderDcPrice;

                    // 적립주문쿠폰 주문적립 안분 금액
                    $tmpDivisionGoodsCouponOrderMileage = NumberUtils::getNumberFigure($divisionCouponOrderMileage * $goodsDcRate, $truncPolicy['unitPrecision'], 'round');
                    $cartInfo[$sKey][$dKey][$gKey]['price']['divisionCouponOrderMileage'] = $tmpDivisionGoodsCouponOrderMileage;
                    $tmpDivisionGoodsCouponMileageSum += $tmpDivisionGoodsCouponOrderMileage;

                    // 복합과세 계산을 위해 주문할인 금액까지 모두 할인 적용된 상품의 실 결제금액
                    $cartInfo[$sKey][$dKey][$gKey]['price']['taxableGoodsPrice'] = $originGoodsPrice - ($tmpDivisionGoodsUseDeposit + $tmpDivisionGoodsUseMileage + $tmpDivisionGoodsCouponOrderDcPrice);

                    if ($setMemberDcMileageZero == 'T') {
                        $cartInfo[$sKey][$dKey][$gKey]['price']['taxableGoodsPrice'] += ($cartInfo[$sKey][$dKey][$gKey]['price']['memberDcPrice'] + $cartInfo[$sKey][$dKey][$gKey]['price']['memberOverlapDcPrice']);
                    }

                    // 주문상품의 갯수 카운트
                    $orderGoodsCnt++;
                }

                // 배송비 할인쿠폰 안분 작업
                $tmpDivisionCouponDeliveryDcPrice = 0;
                $tmpDivisionMemberDeliveryDcPrice = 0;
                if ($order['totalDeliveryCharge'] > 0) {
                    $totalDeliveryCharge = $order['totalDeliveryCharge'];
                    // 전체배송비(배송비 할인쿠폰이 적용된 금액) 대비 비율 산정하며 소수점까지 표현한다.
                    $deliveryCharge = $order['totalGoodsDeliveryAreaCharge'][$dKey];
                    if ($order['totalMemberDeliveryDcPrice'] <= 0) {
                        $deliveryCharge += $order['totalGoodsDeliveryPolicyCharge'][$dKey];
                    } else {
                        $totalDeliveryCharge -= $order['totalMemberDeliveryDcPrice'];
                    }
                    $deliveryDcRate = ($deliveryCharge / ($totalDeliveryCharge));

                    // 배송비쿠폰 주문할인 안분 금액
                    $tmpDivisionCouponDeliveryDcPrice = NumberUtils::getNumberFigure($divisionCouponDeliveryDcPrice * $deliveryDcRate, $truncPolicy['unitPrecision'], 'round');

                    // 회원 배송비 무료 할인 금액
                    if($order['totalMemberDeliveryDcPrice'] > 0){
                        // 회원 배송비 무료 할인은 정책 배송비 금액에만 적용됨.
                        $tmpDivisionMemberDeliveryDcPrice = $order['totalGoodsDeliveryPolicyCharge'][$dKey];
                    }
                }

                // 회원 배송비 무료 할인 금액
                $order['divisionMemberDeliveryDcPrice'][$dKey] = $tmpDivisionMemberDeliveryDcPrice;

                // 나머지 금액 계산을 위한 총합 구하기
                $order['divisionDeliveryCharge'][$dKey] = $tmpDivisionCouponDeliveryDcPrice;
                $tmpDivisionCouponDeliveryDcPriceSum += $tmpDivisionCouponDeliveryDcPrice;

                // 배송비 예치금/마일리지 안분 작업
                $tmpDivisionDeliveryUseDeposit = 0;
                $tmpDivisionDeliveryUseMileage = 0;
                if ($order['settleTotalDeliveryCharge'] > 0) {
                    // 배송비 - 배송비 할인쿠폰 - 회원배송비무료가 적용된 금액을 기준으로 남은 실결제금액을 안분처리 한다.
                    $deliveryDcRateWithDelivery = (($order['totalGoodsDeliveryPolicyCharge'][$dKey] + $order['totalGoodsDeliveryAreaCharge'][$dKey] - $tmpDivisionCouponDeliveryDcPrice - $tmpDivisionMemberDeliveryDcPrice) / $order['settleTotalGoodsPriceWithDelivery']);

                    // 배송비 사용예치금 주문할인 안분 금액
                    $tmpDivisionDeliveryUseDeposit = NumberUtils::getNumberFigure($divisionUseDeposit * $deliveryDcRateWithDelivery, $truncPolicy['unitPrecision'], 'round');
                    $order['divisionDeliveryUseDeposit'][$dKey] = $tmpDivisionDeliveryUseDeposit;
                    $tmpDivisionGoodsUseDepositSum += $tmpDivisionDeliveryUseDeposit;

                    // 배송비 사용마일리지 주문할인 안분 금액
                    $tmpDivisionDeliveryUseMileage = NumberUtils::getNumberFigure($divisionUseMileage * $deliveryDcRateWithDelivery, $truncPolicy['unitPrecision'], 'round');
                    $order['divisionDeliveryUseMileage'][$dKey] = $tmpDivisionDeliveryUseMileage;
                    $tmpDivisionGoodsUseMileageSum += $tmpDivisionDeliveryUseMileage;
                }

                // 복합과세 적용 가능한 실제 배송비 금액 (이미 실 배송비는 구해짐)
                $order['taxableDeliveryCharge'][$dKey] = $order['totalGoodsDeliveryPolicyCharge'][$dKey] + $order['totalGoodsDeliveryAreaCharge'][$dKey] - $tmpDivisionCouponDeliveryDcPrice - $tmpDivisionMemberDeliveryDcPrice - $tmpDivisionDeliveryUseDeposit - $tmpDivisionDeliveryUseMileage;

                // !중요! 배송비로 안분된 마일리지/예치금을 다시 상품쪽 예치금/마일리지로 환원하기 위한 루프 돌리기
                $tmpDivisionDeliveryUseDepositForGoodsSum = 0;
                $tmpDivisionDeliveryUseMileageForGoodsSum = 0;
                $tmpMinusDivisionDeliveryUseDeposit = $tmpDivisionDeliveryUseDeposit;
                $tmpMinusDivisionDeliveryUseMileage = $tmpDivisionDeliveryUseMileage;
                foreach ($dVal as $gKey => $gVal) {
                    $originGoodsPrice = $gVal['price']['goodsPriceSubtotal'];
                    $deliveryForGoodsDcRate = $originGoodsPrice / $totalOriginGoodsPrice[$dKey];

                    // 배송비 사용예치금 주문할인 안분 금액
                    $tmpDivisionDeliveryUseDepositForGoods = NumberUtils::getNumberFigure($tmpDivisionDeliveryUseDeposit * $deliveryForGoodsDcRate, $truncPolicy['unitPrecision'], 'round');
                    // 0 원의 주문상품이 마이너스 부가결제 금액을 할당받는 것에 대한 방지
                    if($tmpMinusDivisionDeliveryUseDeposit >= $tmpDivisionDeliveryUseDepositForGoods){
                        $tmpMinusDivisionDeliveryUseDeposit -= $tmpDivisionDeliveryUseDepositForGoods;
                    }
                    else {
                        $tmpDivisionDeliveryUseDepositForGoods = $tmpMinusDivisionDeliveryUseDeposit;
                        $tmpMinusDivisionDeliveryUseDeposit = 0;
                    }
                    $cartInfo[$sKey][$dKey][$gKey]['price']['divisionGoodsDeliveryUseDeposit'] = $tmpDivisionDeliveryUseDepositForGoods;
                    $tmpDivisionDeliveryUseDepositForGoodsSum += $tmpDivisionDeliveryUseDepositForGoods;

                    // 배송비 사용마일리지 주문할인 안분 금액
                    $tmpDivisionDeliveryUseMileageForGoods = NumberUtils::getNumberFigure($tmpDivisionDeliveryUseMileage * $deliveryForGoodsDcRate, $truncPolicy['unitPrecision'], 'round');
                    // 0 원의 주문상품이 마이너스 부가결제 금액을 할당받는 것에 대한 방지
                    if($tmpMinusDivisionDeliveryUseMileage >= $tmpDivisionDeliveryUseMileageForGoods){
                        $tmpMinusDivisionDeliveryUseMileage -= $tmpDivisionDeliveryUseMileageForGoods;
                    }
                    else {
                        $tmpDivisionDeliveryUseMileageForGoods = $tmpMinusDivisionDeliveryUseMileage;
                        $tmpMinusDivisionDeliveryUseMileage = 0;
                    }
                    $cartInfo[$sKey][$dKey][$gKey]['price']['divisionGoodsDeliveryUseMileage'] = $tmpDivisionDeliveryUseMileageForGoods;
                    $tmpDivisionDeliveryUseMileageForGoodsSum += $tmpDivisionDeliveryUseMileageForGoods;
                }
                $cartInfo[$sKey][$dKey][$gKey]['price']['divisionGoodsDeliveryUseDeposit'] += ($tmpDivisionDeliveryUseDeposit - $tmpDivisionDeliveryUseDepositForGoodsSum);
                $cartInfo[$sKey][$dKey][$gKey]['price']['divisionGoodsDeliveryUseMileage'] += ($tmpDivisionDeliveryUseMileage - $tmpDivisionDeliveryUseMileageForGoodsSum);
            }
        }

        // 상품금액 비율로 처리 후 금액이 안맞는 부분 마지막 상품에 +/- 처리
        if ($tmpDivisionGoodsUseDepositSum != $divisionUseDeposit) {
            $cartInfo[$sKey][$dKey][$gKey]['price']['divisionUseDeposit'] += ($divisionUseDeposit - $tmpDivisionGoodsUseDepositSum);
            $cartInfo[$sKey][$dKey][$gKey]['price']['taxableGoodsPrice'] -= ($divisionUseDeposit - $tmpDivisionGoodsUseDepositSum);
        }
        if ($tmpDivisionGoodsUseMileageSum != $divisionUseMileage) {
            $cartInfo[$sKey][$dKey][$gKey]['price']['divisionUseMileage'] += ($divisionUseMileage - $tmpDivisionGoodsUseMileageSum);
            $cartInfo[$sKey][$dKey][$gKey]['price']['taxableGoodsPrice'] -= ($divisionUseMileage - $tmpDivisionGoodsUseMileageSum);
        }
        if ($tmpDivisionGoodsCouponOrderDcPriceSum != $divisionCouponOrderDcPrice) {
            $cartInfo[$sKey][$dKey][$gKey]['price']['divisionCouponOrderDcPrice'] += ($divisionCouponOrderDcPrice - $tmpDivisionGoodsCouponOrderDcPriceSum);
            $cartInfo[$sKey][$dKey][$gKey]['price']['taxableGoodsPrice'] -= ($divisionCouponOrderDcPrice - $tmpDivisionGoodsCouponOrderDcPriceSum);
        }
        if ($tmpDivisionGoodsCouponMileageSum != $divisionCouponOrderMileage) {
            $cartInfo[$sKey][$dKey][$gKey]['price']['divisionCouponOrderMileage'] += ($divisionCouponOrderMileage - $tmpDivisionGoodsCouponMileageSum);
            //            $cartInfo[$sKey][$dKey][$gKey]['price']['taxableGoodsPrice'] -= ($divisionCouponOrderMileage - $tmpDivisionGoodsCouponMileageSum);
        }

        // 배송비조건 비율로 처리 후 금액이 안맞는 부분 마지막 배송비에 +/- 처리
        if ($tmpDivisionCouponDeliveryDcPriceSum != $divisionCouponDeliveryDcPrice) {
            $order['divisionDeliveryCharge'][$dKey] += ($divisionCouponDeliveryDcPrice - $tmpDivisionCouponDeliveryDcPriceSum);
            $order['taxableDeliveryCharge'][$dKey] += ($divisionCouponDeliveryDcPrice - $tmpDivisionCouponDeliveryDcPriceSum);
        }

        // 배송 콤포넌트 호출
        $delivery = App::load(\Component\Delivery\DeliveryCart::class);
        $delivery->setDeliveryMethodCompanySno();

        //공급사 수수료 컴포넌트 호출
        if(gd_use_provider() === true) {
            if(!is_object($scmCommission)) {
                $scmCommission = App::load(\Component\Scm\ScmCommission::class);
            }
        }

        // 해외배송 기본 정책
        $overseasDeliveryPolicy = null;
        $onlyOneOverseasDelivery = false;
        if (Globals::get('gGlobal.isFront')) {
            $overseasDelivery = new OverseasDelivery();
            $overseasDeliveryPolicy = $overseasDelivery->getBasicData(\Component\Mall\Mall::getSession('sno'), 'mallSno');
        }

        //상품 호출
        $goods = \App::load('\\Component\\Goods\\Goods');

        // 과세/면세 총 합을 위한 변수 초기화
        $taxSupplyPrice = 0;
        $taxVatPrice = 0;
        $taxFreePrice = 0;

        $orderCd = 1;

        // 복수배송지를 사용할 경우 해당 프로세스 실행 (주문배송 정보)
        $orderMultiDeliverySno = [];
        $tmpTotalCouponDeliveryDcPrice = $order['totalCouponDeliveryDcPrice'];
        $tmpDivisionUseDeposit = $order['useDeposit'];
        $tmpDivisionUseMileage = $order['useMileage'];
        if (\Component\Order\OrderMultiShipping::isUseMultiShipping() === true && \Globals::get('gGlobal.isFront') === false && $orderInfo['multiShippingFl'] == 'y') {
            foreach ($order['totalGoodsMultiDeliveryPolicyCharge'] as $key => $val) {
                foreach ($val as $tKey => $tVal) {
                    // 배송비 할인쿠폰 안분 작업
                    $tmpDivisionCouponDeliveryDcPrice = 0;
                    $tmpDivisionMemberDeliveryDcPrice = 0;
                    if ($order['totalDeliveryCharge'] > 0) {
                        $totalDeliveryCharge = $order['totalDeliveryCharge'];
                        // 전체배송비(배송비 할인쿠폰이 적용된 금액) 대비 비율 산정하며 소수점까지 표현한다.
                        $deliveryCharge = $order['totalGoodsMultiDeliveryAreaPrice'][$key][$tKey];
                        if ($order['totalMemberDeliveryDcPrice'] <= 0) {
                            $deliveryCharge += $order['totalGoodsMultiDeliveryPolicyCharge'][$key][$tKey];
                        } else {
                            $totalDeliveryCharge -= $order['totalMemberDeliveryDcPrice'];
                        }
                        $deliveryDcRate = ($deliveryCharge / ($totalDeliveryCharge));

                        // 배송비쿠폰 주문할인 안분 금액
                        $tmpDivisionCouponDeliveryDcPrice = NumberUtils::getNumberFigure($divisionCouponDeliveryDcPrice * $deliveryDcRate, $truncPolicy['unitPrecision'], 'round');
                        // 회원 배송비 무료 할인 금액
                        if($order['totalMemberDeliveryDcPrice'] > 0){
                            // 회원 배송비 무료 할인은 정책 배송비 금액에만 적용됨.
                            $tmpDivisionMemberDeliveryDcPrice = $order['totalGoodsMultiDeliveryPolicyCharge'][$key][$tKey];
                        }
                    }
                    if ($tmpTotalCouponDeliveryDcPrice - $tmpDivisionCouponDeliveryDcPrice > 0) {
                        $tmpTotalCouponDeliveryDcPrice -= $tmpDivisionCouponDeliveryDcPrice;
                    } else if ($tmpTotalCouponDeliveryDcPrice - $tmpDivisionCouponDeliveryDcPrice < 0) {
                        $tmpDivisionCouponDeliveryDcPrice = $tmpTotalCouponDeliveryDcPrice;
                        $tmpTotalCouponDeliveryDcPrice = 0;
                    }

                    // 배송비 예치금/마일리지 안분 작업
                    $tmpDivisionDeliveryUseDeposit = 0;
                    $tmpDivisionDeliveryUseMileage = 0;
                    if ($order['settleTotalDeliveryCharge'] > 0) {
                        // 배송비 - 배송비 할인쿠폰이 적용된 금액을 기준으로 남은 실결제금액을 안분처리 한다.
                        $deliveryDcRateWithDelivery = (($order['totalGoodsMultiDeliveryPolicyCharge'][$key][$tKey] + $order['totalGoodsMultiDeliveryAreaPrice'][$key][$tKey] - $tmpDivisionCouponDeliveryDcPrice - $tmpDivisionMemberDeliveryDcPrice) / $order['settleTotalGoodsPriceWithDelivery']);

                        // 배송비 사용예치금 주문할인 안분 금액
                        $tmpDivisionDeliveryUseDeposit = NumberUtils::getNumberFigure($divisionUseDeposit * $deliveryDcRateWithDelivery, $truncPolicy['unitPrecision'], 'round');

                        // 배송비 사용마일리지 주문할인 안분 금액
                        $tmpDivisionDeliveryUseMileage = NumberUtils::getNumberFigure($divisionUseMileage * $deliveryDcRateWithDelivery, $truncPolicy['unitPrecision'], 'round');
                    }
                    if ($tmpDivisionUseDeposit - $tmpDivisionDeliveryUseDeposit > 0) {
                        $tmpDivisionUseDeposit -= $tmpDivisionDeliveryUseDeposit;
                    } else if ($tmpDivisionUseDeposit - $tmpDivisionDeliveryUseDeposit < 0) {
                        $tmpDivisionDeliveryUseDeposit = $tmpDivisionUseDeposit;
                        $tmpDivisionUseDeposit = 0;
                    }
                    if ($tmpDivisionUseMileage - $tmpDivisionDeliveryUseMileage > 0) {
                        $tmpDivisionUseMileage -= $tmpDivisionDeliveryUseMileage;
                    } else if ($tmpDivisionUseMileage - $tmpDivisionDeliveryUseMileage < 0) {
                        $tmpDivisionDeliveryUseMileage = $tmpDivisionUseMileage;
                        $tmpDivisionUseMileage = 0;
                    }

                    $deliveryPolicy = $delivery->getDataDeliveryWithGoodsNo([$tKey]);
                    $scmNo = $deliveryPolicy[$tKey]['scmNo'];
                    $goodsData = $cartInfo[$scmNo][$tKey][0];

                    // 공급사 수수료 일정 Convert 실행
                    if(gd_use_provider() === true) {
                        if($scmNo > DEFAULT_CODE_SCMNO) {
                            $scmCommissionConvertData = $scmCommission->frontConvertScmCommission($scmNo, $goodsData);
                        }
                    }

                    // 배송정책내 부가세율 관련 정보 설정
                    $deliveryTaxFreeFl = $goodsData['goodsDeliveryTaxFreeFl'];
                    $deliveryTaxPercent = $goodsData['goodsDeliveryTaxPercent'];
                    $taxableDeliveryCharge = $order['totalGoodsMultiDeliveryPolicyCharge'][$key][$tKey] + $order['totalGoodsMultiDeliveryAreaPrice'][$key][$tKey];
                    $taxableDeliveryCharge -= $tmpDivisionCouponDeliveryDcPrice + $tmpDivisionMemberDeliveryDcPrice + $tmpDivisionDeliveryUseDeposit + $tmpDivisionDeliveryUseMileage;

                    // 상단에서 계산된 금액으로 배송비 복합과세 처리
                    $tmpDeliveryTaxPrice = NumberUtils::taxAll($taxableDeliveryCharge, $deliveryTaxPercent, $deliveryTaxFreeFl);

                    // 초기화
                    $taxDeliveryCharge['supply'] = 0;
                    $taxDeliveryCharge['tax'] = 0;
                    $taxDeliveryCharge['free'] = 0;
                    if ($deliveryTaxFreeFl == 't') {
                        // 배송비 과세처리
                        $taxDeliveryCharge['supply'] = $tmpDeliveryTaxPrice['supply'];
                        $taxDeliveryCharge['tax'] = $tmpDeliveryTaxPrice['tax'];
                    } else {
                        // 배송비 면세처리
                        $taxDeliveryCharge['free'] = $tmpDeliveryTaxPrice['supply'];
                    }

                    $deliveryInfo = [
                        'orderNo'                     => $this->orderNo,
                        'scmNo'                       => $scmNo,
                        'commission'                  => ($scmCommissionConvertData['scmCommissionDelivery']) ? $scmCommissionConvertData['scmCommissionDelivery'] : $deliveryPolicy[$tKey]['scmCommissionDelivery'],
                        'deliverySno'                 => $tKey,
                        'deliveryCharge'              => $order['totalGoodsMultiDeliveryPolicyCharge'][$key][$tKey] + $order['totalGoodsMultiDeliveryAreaPrice'][$key][$tKey],
                        'taxSupplyDeliveryCharge'     => $taxDeliveryCharge['supply'],
                        'taxVatDeliveryCharge'        => $taxDeliveryCharge['tax'],
                        'taxFreeDeliveryCharge'       => $taxDeliveryCharge['free'],
                        'realTaxSupplyDeliveryCharge' => $taxDeliveryCharge['supply'],
                        'realTaxVatDeliveryCharge'    => $taxDeliveryCharge['tax'],
                        'realTaxFreeDeliveryCharge'   => $taxDeliveryCharge['free'],
                        'deliveryPolicyCharge'        => $order['totalGoodsMultiDeliveryPolicyCharge'][$key][$tKey],
                        'deliveryAreaCharge'          => $order['totalGoodsMultiDeliveryAreaPrice'][$key][$tKey],
                        'deliveryFixFl'               => $goodsData['goodsDeliveryFixFl'],
                        'divisionDeliveryUseDeposit'  => $tmpDivisionDeliveryUseDeposit,
                        'divisionDeliveryUseMileage'  => $tmpDivisionDeliveryUseMileage,
                        'divisionDeliveryCharge'      => $tmpDivisionCouponDeliveryDcPrice,
                        'divisionMemberDeliveryDcPrice' => $tmpDivisionMemberDeliveryDcPrice,
                        'deliveryInsuranceFee'        => $order['totalDeliveryInsuranceFee'],
                        'goodsDeliveryFl'             => $goodsData['goodsDeliveryFl'],
                        'deliveryTaxInfo'             => $deliveryTaxFreeFl . STR_DIVISION . $deliveryTaxPercent,
                        'deliveryWeightInfo'          => $order['totalDeliveryWeight'],
                        'deliveryPolicy'              => json_encode($deliveryPolicy[$tKey], JSON_UNESCAPED_UNICODE),
                        'overseasDeliveryPolicy'      => json_encode($overseasDeliveryPolicy, JSON_UNESCAPED_UNICODE),
                        'deliveryCollectFl'           => $goodsData['goodsDeliveryCollectFl'],
                        'deliveryCollectPrice'        => $order['totalDeliveryCollectPrice'][$tKey],
                        // 배송비조건별인 경우만 금액을 넣는다.
                        'deliveryMethod'              => $goodsData['goodsDeliveryMethod'],
                        'deliveryWholeFreeFl'         => $goodsData['goodsDeliveryWholeFreeFl'],
                        'deliveryWholeFreePrice'      => ($goodsData['price']['goodsDeliveryWholeFreePrice'] > 0 ?: $order['totalDeliveryWholeFreePrice'][$tKey]),
                        // 배송비 조건별/상품별에 따라서 금액을 받아온다.
                        'deliveryLog'                 => '',
                    ];

					if($order['totalDeliveryCharge'] == 0) {
                        $deliveryInfo['deliveryCharge'] = 0;
                    }

                    // 정책별 배송 정보 저장
                    $arrBind = $this->db->get_binding(DBTableField::tableOrderDelivery(), $deliveryInfo, 'insert');
                    $this->db->set_insert_db(DB_ORDER_DELIVERY, $arrBind['param'], $arrBind['bind'], 'y', false);
                    $orderMultiDeliverySno[$key][$tKey] = $this->db->insert_id();
                    unset($arrBind);
                }
            }
        }

        if (\Cookie::has('inflow_goods') === true) {
            $inflowGoods = json_decode(\Cookie::get('inflow_goods'), true);
            $inflowDiffGoods = [];
        }
        foreach ($cartInfo as $sKey => $sVal) {
            foreach ($sVal as $dKey => $dVal) {
                $onlyOneDelivery = true;
                $deliveryPolicy = $delivery->getDataDeliveryWithGoodsNo([$dKey]);
                $deliveryMethodFl = '';
                foreach ($dVal as $gKey => $gVal) {
                    if (empty($gVal['goodsNo']) === true) continue;
                    // 공급사 수수료 일정 Convert 실행
                    if(gd_use_provider() === true) {
                        if($sKey > DEFAULT_CODE_SCMNO) {
                            $scmCommissionConvertData = $scmCommission->frontConvertScmCommission($sKey, $gVal);
                            if($scmCommissionConvertData['scmCommission']) {
                                $gVal['commission'] = $scmCommissionConvertData['scmCommission'];
                            }
                        }
                    }
                    $gVal['orderNo'] = $this->orderNo;
                    $gVal['mallSno'] = gd_isset(Mall::getSession('sno'), 1);
                    $gVal['orderCd'] = $orderCd;
                    $gVal['goodsNm'] = $gVal['goodsNm'];
                    $gVal['goodsNmStandard'] = $gVal['goodsNmStandard'];
                    $gVal['orderStatus'] = $orderStatusPre;
                    $gVal['deliveryMethodFl'] = empty($gVal['deliveryMethodFl']) === true ? 'delivery' : $gVal['deliveryMethodFl']; //배송방식
                    $gVal['goodsDeliveryCollectFl'] = $gVal['goodsDeliveryCollectFl'];
                    $gVal['cateAllCd'] = json_encode($gVal['cateAllCd'], JSON_UNESCAPED_UNICODE);
                    // 상품별 배송비조건인 경우 선불/착불 금액 기록 (배송비조건별인 경우 orderDelivery에 저장)
                    // orderDelivery에 각 상품별 선/착불 데이터를 저장하기 애매해서 이와 같이 처리 함
                    if ($gVal['goodsDeliveryFl'] === 'n') {
                        $gVal['goodsDeliveryCollectPrice'] = $gVal['goodsDeliveryCollectFl'] == 'pre' ? $gVal['price']['goodsDeliveryPrice'] : $gVal['price']['goodsDeliveryCollectPrice'];
                    }

                    //조건별 배송비 일때
                    if($deliveryPolicy[$dKey]['goodsDeliveryFl'] === 'y'){
                        //조건별 배송비 사용 일 경우 배송방식을 모두 변환한다.
                        if(trim($deliveryMethodFl) === ''){
                            $deliveryMethodFl = empty($gVal['deliveryMethodFl']) === true ? 'delivery' : $gVal['deliveryMethodFl']; //배송방식
                        }
                        $gVal['deliveryMethodFl'] = $deliveryMethodFl;
                    }
                    else {
                        $deliveryMethodFl = '';
                    }

                    if($gVal['deliveryMethodFl'] && $gVal['deliveryMethodFl'] !== 'delivery'){
                        $gVal['invoiceCompanySno'] = $delivery->deliveryMethodList['sno'][$gVal['deliveryMethodFl']];
                    }

                    $gVal['goodsPrice'] = $gVal['price']['goodsPrice'];
                    $gVal['addGoodsCnt'] = count(gd_isset($gVal['addGoods']));
                    // 기존 추가상품의 계산로직 레거시 보장을 위해 0으로 변경 처리
                    $gVal['addGoodsPrice'] = 0;
                    $gVal['optionPrice'] = $gVal['price']['optionPrice'];
                    $gVal['optionCostPrice'] = $gVal['price']['optionCostPrice'];
                    $gVal['optionTextPrice'] = $gVal['price']['optionTextPrice'];
                    $gVal['fixedPrice'] = $gVal['price']['fixedPrice'];
                    $gVal['costPrice'] = $gVal['price']['costPrice'];
                    $gVal['goodsDcPrice'] = $gVal['price']['goodsDcPrice'];
                    // 쿠폰 정책에 따른 쿠폰만사용설정시 회원혜택 제거
                    if ($setMemberDcMileageZero == 'T') {
                        $gVal['memberDcPrice'] = 0;
                        $gVal['memberMileage'] = 0;
                    } else {
                        $gVal['memberDcPrice'] = $gVal['price']['goodsMemberDcPrice'];
                        $gVal['memberMileage'] = $gVal['mileage']['memberMileage'];
                    }
                    $gVal['memberOverlapDcPrice'] = $gVal['price']['goodsMemberOverlapDcPrice'];
                    $gVal['couponGoodsDcPrice'] = $gVal['price']['goodsCouponGoodsDcPrice'];

                    // 마이앱 사용에 따른 분기 처리
                    if ($this->useMyapp) {
                        $gVal['myappDcPrice'] = $gVal['price']['myappDcPrice'];
                    }

                    $gVal['goodsMileage'] = $gVal['mileage']['goodsMileage'];
                    $gVal['couponGoodsMileage'] = $gVal['mileage']['couponGoodsMileage'];
                    $gVal['goodsTaxInfo'] = $gVal['taxFreeFl'] . STR_DIVISION . $gVal['taxPercent'];// 상품 세금 정보
                    $gVal['divisionUseDeposit'] = $gVal['price']['divisionUseDeposit'];
                    $gVal['divisionUseMileage'] = $gVal['price']['divisionUseMileage'];
                    $gVal['divisionGoodsDeliveryUseDeposit'] = $gVal['price']['divisionGoodsDeliveryUseDeposit'];
                    $gVal['divisionGoodsDeliveryUseMileage'] = $gVal['price']['divisionGoodsDeliveryUseMileage'];
                    $gVal['divisionCouponOrderDcPrice'] = $gVal['price']['divisionCouponOrderDcPrice'];
                    $gVal['divisionCouponOrderMileage'] = $gVal['price']['divisionCouponOrderMileage'];
                    if($gVal['hscode']) $gVal['hscode'] = $gVal['hscode'];
                    if($gVal['timeSaleFl']) $gVal['timeSaleFl'] = 'y';
                    else $gVal['timeSaleFl'] = 'n';

                    // 배송비 테이블 데이터 설정으로 foreach구문에서 최초 한번만 실행된다.
                    if ($onlyOneDelivery === true) {
                        // 배송정책내 부가세율 관련 정보 설정
                        $deliveryTaxFreeFl = $gVal['goodsDeliveryTaxFreeFl'];
                        $deliveryTaxPercent = $gVal['goodsDeliveryTaxPercent'];

                        // 상단에서 계산된 금액으로 배송비 복합과세 처리
                        $tmpDeliveryTaxPrice = NumberUtils::taxAll($order['taxableDeliveryCharge'][$dKey], $deliveryTaxPercent, $deliveryTaxFreeFl);

                        // 초기화
                        $taxDeliveryCharge['supply'] = 0;
                        $taxDeliveryCharge['tax'] = 0;
                        $taxDeliveryCharge['free'] = 0;
                        if ($deliveryTaxFreeFl == 't') {
                            // 배송비 과세처리
                            $taxDeliveryCharge['supply'] = $tmpDeliveryTaxPrice['supply'];
                            $taxDeliveryCharge['tax'] = $tmpDeliveryTaxPrice['tax'];

                            // 주문의 총 과세에 합산
                            $taxSupplyPrice += $tmpDeliveryTaxPrice['supply'];
                            $taxVatPrice += $tmpDeliveryTaxPrice['tax'];
                        } else {
                            // 배송비 면세처리
                            $taxDeliveryCharge['free'] = $tmpDeliveryTaxPrice['supply'];

                            // 주문의 총 면세에 합산
                            $taxFreePrice += $tmpDeliveryTaxPrice['supply'];
                        }

                        // 복수배송지를 사용할 경우 해당 프로세스 실행하지 않음
                        if (\Component\Order\OrderMultiShipping::isUseMultiShipping() === true && \Globals::get('gGlobal.isFront') === false && $orderInfo['multiShippingFl'] == 'y') {}
                        else {
                            // 공급사 수수료 일정 Convert 실행
                            if(gd_use_provider() === true) {
                                if($sKey > DEFAULT_CODE_SCMNO) {
                                    $scmCommissionConvertData = $scmCommission->frontConvertScmCommission($sKey, $gVal);
                                }
                            }
                            $deliveryInfo = [
                                'orderNo'                     => $this->orderNo,
                                'scmNo'                       => $sKey,
                                'commission'                  => ($scmCommissionConvertData['scmCommissionDelivery']) ? $scmCommissionConvertData['scmCommissionDelivery'] : $deliveryPolicy[$dKey]['scmCommissionDelivery'],
                                'deliverySno'                 => $dKey,
                                'deliveryCharge'              => $order['totalGoodsDeliveryPolicyCharge'][$dKey] + $order['totalGoodsDeliveryAreaCharge'][$dKey],
                                'taxSupplyDeliveryCharge'     => $taxDeliveryCharge['supply'],
                                'taxVatDeliveryCharge'        => $taxDeliveryCharge['tax'],
                                'taxFreeDeliveryCharge'       => $taxDeliveryCharge['free'],
                                'realTaxSupplyDeliveryCharge' => $taxDeliveryCharge['supply'],
                                'realTaxVatDeliveryCharge'    => $taxDeliveryCharge['tax'],
                                'realTaxFreeDeliveryCharge'   => $taxDeliveryCharge['free'],
                                'deliveryPolicyCharge'        => $order['totalGoodsDeliveryPolicyCharge'][$dKey],
                                'deliveryAreaCharge'          => $order['totalGoodsDeliveryAreaCharge'][$dKey],
                                'deliveryFixFl'               => $gVal['goodsDeliveryFixFl'],
                                'divisionDeliveryUseDeposit'  => $order['divisionDeliveryUseDeposit'][$dKey],
                                'divisionDeliveryUseMileage'  => $order['divisionDeliveryUseMileage'][$dKey],
                                'divisionDeliveryCharge'      => $order['divisionDeliveryCharge'][$dKey],
                                'divisionMemberDeliveryDcPrice' => $order['divisionMemberDeliveryDcPrice'][$dKey],
                                'deliveryInsuranceFee'        => $order['totalDeliveryInsuranceFee'],
                                'goodsDeliveryFl'             => $gVal['goodsDeliveryFl'],
                                'deliveryTaxInfo'             => $deliveryTaxFreeFl . STR_DIVISION . $deliveryTaxPercent,
                                'deliveryWeightInfo'          => $order['totalDeliveryWeight'],
                                'deliveryPolicy'              => json_encode($deliveryPolicy[$dKey], JSON_UNESCAPED_UNICODE),
                                'overseasDeliveryPolicy'      => json_encode($overseasDeliveryPolicy, JSON_UNESCAPED_UNICODE),
                                'deliveryCollectFl'           => $gVal['goodsDeliveryCollectFl'],
                                'deliveryCollectPrice'        => $order['totalDeliveryCollectPrice'][$dKey],
                                // 배송비조건별인 경우만 금액을 넣는다.
                                'deliveryMethod'              => $gVal['goodsDeliveryMethod'],
                                'deliveryWholeFreeFl'         => $gVal['goodsDeliveryWholeFreeFl'],
                                'deliveryWholeFreePrice'      => ($gVal['price']['goodsDeliveryWholeFreePrice'] > 0 ?: $order['totalDeliveryWholeFreePrice'][$dKey]),
                                // 배송비 조건별/상품별에 따라서 금액을 받아온다.
                                'deliveryLog'                 => '',
                            ];

                            // !중요!
                            // 해외배송은 설정에 따라서 무조건 하나의 배송비조건만 가지고 계산된다.
                            // 따라서 공급사의 경우 기본적으로 공급사마다 별도의 배송비조건을 가지게 되기때문에 아래와 같이
                            // 본사/공급사 구분없이 최초 배송비조건만 할당하고 나머지 배송비는 0원으로 처리해 이를 처리한다.
                            if (Globals::get('gGlobal.isFront') && $onlyOneOverseasDelivery === true) {
                                $deliveryInfo['deliveryCharge'] = 0;
                                $deliveryInfo['taxSupplyDeliveryCharge'] = 0;
                                $deliveryInfo['taxVatDeliveryCharge'] = 0;
                                $deliveryInfo['taxFreeDeliveryCharge'] = 0;
                                $deliveryInfo['realTaxSupplyDeliveryCharge'] = 0;
                                $deliveryInfo['realTaxVatDeliveryCharge'] = 0;
                                $deliveryInfo['realTaxFreeDeliveryCharge'] = 0;
                                $deliveryInfo['deliveryPolicyCharge'] = 0;
                                $deliveryInfo['deliveryAreaCharge'] = 0;
                                $deliveryInfo['divisionDeliveryUseDeposit'] = 0;
                                $deliveryInfo['divisionDeliveryUseMileage'] = 0;
                                $deliveryInfo['divisionDeliveryCharge'] = 0;
                                $deliveryInfo['deliveryInsuranceFee'] = 0;
                                $deliveryInfo['deliveryCollectPrice'] = 0;
                                $deliveryInfo['deliveryWholeFreePrice'] = 0;
                            }

							if($order['totalDeliveryCharge'] == 0) {
                                $deliveryInfo['deliveryCharge'] = 0;
                            }

                            // 정책별 배송 정보 저장
                            $arrBind = $this->db->get_binding(DBTableField::tableOrderDelivery(), $deliveryInfo, 'insert');
                            $this->db->set_insert_db(DB_ORDER_DELIVERY, $arrBind['param'], $arrBind['bind'], 'y', false);
                            $orderDeliverySno = $this->db->insert_id();
                            unset($arrBind);
                        }

                        // 한번만 실행
                        $onlyOneDelivery = false;
                        $onlyOneOverseasDelivery = true;
                    }

                    if (empty($orderDeliverySno) === false) {
                        $gVal['orderDeliverySno'] = $orderDeliverySno;
                    } else {
                        $gVal['orderDeliverySno'] = $orderMultiDeliverySno[$orderInfo['orderInfoCdBySno'][$gVal['sno']]][$dKey];
                    }

                    // 옵션 설정
                    if (empty($gVal['option']) === true) {
                        $gVal['optionInfo'] = '';
                    } else {
                        foreach ($gVal['option'] as $oKey => $oVal) {
                            $tmp[] = [
                                $oVal['optionName'],
                                $oVal['optionValue'],
                                $oVal['optionCode'],
                                floatval($oVal['optionPrice']),
                                $oVal['optionDeliveryStr'],
                            ];
                        }
                        $gVal['optionInfo'] = json_encode($tmp, JSON_UNESCAPED_UNICODE);
                        unset($tmp);
                    }

                    // 텍스트 옵션
                    if (empty($gVal['optionText']) === true) {
                        $gVal['optionTextInfo'] = '';
                    } else {
                        foreach ($gVal['optionText'] as $oKey => $oVal) {
                            $tmp[$oVal['optionSno']] = [
                                $oVal['optionName'],
                                $oVal['optionValue'],
                                floatval($oVal['optionTextPrice']),
                            ];
                        }
                        $gVal['optionTextInfo'] = json_encode($tmp, JSON_UNESCAPED_UNICODE);
                        unset($tmp);
                    }

                    // 상품할인정보
                    if (empty($gVal['goodsDiscountInfo']) === true) {
                        $gVal['goodsDiscountInfo'] = '';
                    } else {
                        $gVal['goodsDiscountInfo'] = json_encode($gVal['goodsDiscountInfo'], JSON_UNESCAPED_UNICODE);
                    }
                    // 상품적립정보
                    if (empty($gVal['goodsMileageAddInfo']) === true) {
                        $gVal['goodsMileageAddInfo'] = '';
                    } else {
                        $gVal['goodsMileageAddInfo'] = json_encode($gVal['goodsMileageAddInfo'], JSON_UNESCAPED_UNICODE);
                    }

                    // 상품의 복합과세 금액 산출 및 주문상품에 저장할 필드 설정
                    $tmpGoodsTaxPrice = NumberUtils::taxAll($gVal['price']['taxableGoodsPrice'], $gVal['taxPercent'], $gVal['taxFreeFl']);
                    if ($gVal['taxFreeFl'] == 't') {
                        $gVal['taxSupplyGoodsPrice'] = $gVal['realTaxSupplyGoodsPrice'] = gd_isset($tmpGoodsTaxPrice['supply'], 0);
                        $gVal['taxVatGoodsPrice'] = $gVal['realTaxVatGoodsPrice'] = gd_isset($tmpGoodsTaxPrice['tax'], 0);
                        $taxSupplyPrice += $gVal['taxSupplyGoodsPrice'];
                        $taxVatPrice += $gVal['taxVatGoodsPrice'];
                    } else {
                        $gVal['taxFreeGoodsPrice'] = $gVal['realTaxFreeGoodsPrice'] = gd_isset($tmpGoodsTaxPrice['supply'], 0);
                        $taxFreePrice += $gVal['taxFreeGoodsPrice'];
                    }

                    // 상품 쿠폰 정보 (하단 별도의 테이블에 담는 정보)
                    if ($gVal['couponGoodsDcPrice'] > 0 || $gVal['couponGoodsMileage'] > 0) {
                        foreach ($gVal['coupon'] as $memberCouponNo => $couponVal) {
                            $goodsCouponInfo[] = [
                                'orderNo'        => $this->orderNo,
                                'orderCd'        => $orderCd,
                                'goodsNo'        => $gVal['goodsNo'],
                                'memberCouponNo' => $memberCouponNo,
                                'expireSdt'      => $couponVal['memberCouponStartDate'],
                                'expireEdt'      => $couponVal['memberCouponEndDate'],
                                'couponNm'       => $couponVal['couponNm'],
                                'couponPrice'    => $couponVal['couponGoodsDcPrice'],
                                'couponMileage'  => $couponVal['couponGoodsMileage'],
                            ];
                        }
                    }

                    if (empty($inflowGoods) === false && in_array($gVal['goodsNo'], $inflowGoods) === true) {
                        $gVal['inflow'] = \Cookie::get('inflow');
                        $inflowDiffGoods[] = $gVal['goodsNo']; // 상품 기준(옵션 달라도 하나로 묶음)
                    }

                    // 주문 상품명
                    if ($orderCd == 1) {
                        $orderGoodsNm = $gVal['goodsNm'];
                        $orderGoodsNmStandard =  $gVal['goodsNmStandard'];
                    }

                    // PG 처리를 위한 상품 정보
                    $this->arrGoodsName[] = gd_htmlspecialchars($gVal['goodsNm']);
                    $this->arrGoodsNo[] = $gVal['goodsNo'];
                    $this->arrGoodsAmt[] = $gVal['goodsPrice'] + $gVal['optionPrice'] + $gVal['optionTextPrice'];
                    $this->arrGoodsCnt[] = $gVal['goodsCnt'];

                    // 장바구니 상품 정보 저장
                    $arrBind = $this->db->get_binding(DBTableField::tableOrderGoods(), $gVal, 'insert');
                    $this->db->set_insert_db(DB_ORDER_GOODS, $arrBind['param'], $arrBind['bind'], 'y', false);

                    // 저장된 주문상품(order_goods) SNO 값
                    $arrOrderGoodsSno['sno'][] = $this->db->insert_id();

                    $orderCd++;
                    unset($arrBind);

                    //주문시 결제완료 상태인경우에 주문카운트 수정
                    if ($gVal['orderStatus']  =='p1') {
                        $goods->setOrderCount($this->db->insert_id());
                        // es_goods.orderGoodsCnt 갱신
                        $goods->setOrderGoodsCount($this->db->insert_id());
                    }

                    // 주문 로그 저장
                    $this->orderLog($gVal['orderNo'], $this->db->insert_id(), null, $this->getOrderStatusAdmin($gVal['orderStatus']) . '(' . $gVal['orderStatus'] . ')', '초기주문', true);
                }
            }
        }

        if (\Cookie::has('inflow_goods') === true) {
            $inflowGoods = array_diff($inflowGoods, $inflowDiffGoods); // 상품 기준(옵션 달라도 하나로 묶음)
            $inflowGoods = json_encode($inflowGoods);
            \Cookie::set('inflow_goods', $inflowGoods);
        }

        // 주문서 저장용 데이터 설정
        $order['orderChannelFl'] = gd_isset($orderInfo['orderChannelFl'], 'shop');
        $order['totalMinusMileage'] = $orderInfo['useMileage'];
        $order['orderNo'] = $this->orderNo;
        $order['orderStatus'] = $orderStatusPre;
        $order['orderIp'] = Request::getRemoteAddress();
        $order['orderEmail'] = is_array($orderInfo['orderEmail']) ? implode('@', $orderInfo['orderEmail']) : $orderInfo['orderEmail'];
        $order['settleKind'] = $orderInfo['settleKind'];
        $order['receiptFl'] = gd_isset($orderInfo['receiptFl'], 'n');
        $order['orderGoodsNm'] = $this->orderGoodsName = $orderGoodsNm . ($orderGoodsCnt > 1 ? __(' 외 ') . ($orderGoodsCnt - 1) . __(' 건') : '');

        // 한글 저장하는 것으로 번역 처리 하면 안됩니다.
        $order['orderGoodsNmStandard'] = $orderGoodsNmStandard . ($orderGoodsCnt > 1 ? ' 외 ' . ($orderGoodsCnt - 1) . ' 건' : '');

        $order['orderGoodsCnt'] = $orderGoodsCnt;
        $order['bankSender'] = gd_isset($orderInfo['bankSender']);

        // 멀티상점 정보 추가 (없으면 1)
        $order['mallSno'] = gd_isset(Mall::getSession('sno'), 1);

        // 해외배송을 위한 배송 총 무게
        $order['totalDeliveryWeight'] = $order['totalDeliveryWeight']['total'];

        // 최종 상품 + 배송비 결제금액에 대한 복합과세 금액
        $order['taxSupplyPrice'] = $order['realTaxSupplyPrice'] = $taxSupplyPrice;
        $order['taxVatPrice'] = $order['realTaxVatPrice'] = $taxVatPrice;
        $order['taxFreePrice'] = $order['realTaxFreePrice'] = $taxFreePrice;

        // 회원 콤포넌트 호출
        $member = \App::load('\\Component\\Member\\Member');

        // 주문완료 시점의 정책 저장
        $order['depositPolicy'] = json_encode(gd_policy('member.depositConfig'), JSON_UNESCAPED_UNICODE);//예치금정책
        $order['mileagePolicy'] = json_encode(gd_mileage_give_info(), JSON_UNESCAPED_UNICODE);//마일리지정책
        $order['statusPolicy'] = json_encode($this->getOrderStatusPolicy(), JSON_UNESCAPED_UNICODE);//주문상태 정책
        if($isWrite === true){
            //수기주문일 경우
            if((int)$orderInfo['memNo'] > 0){
                $order['memberPolicy'] = json_encode($member->getMemberInfo($orderInfo['memNo']), JSON_UNESCAPED_UNICODE);
            }
        }
        else {
            $order['memberPolicy'] = json_encode($member->getMemberInfo(), JSON_UNESCAPED_UNICODE);
        }
        $order['couponPolicy'] = json_encode($couponPolicy, JSON_UNESCAPED_UNICODE);

        // 환율 정책 저장
        if (Globals::get('gGlobal.isFront')) {
            $exchangeRate = new ExchangeRate();
            $order['currencyPolicy'] = json_encode(reset($exchangeRate->getGlobalCurrency(\Component\Mall\Mall::getSession('currencyConfig.isoCode'), 'isoCode')), JSON_UNESCAPED_UNICODE);
            $order['exchangeRatePolicy'] = json_encode($exchangeRate->getExchangeRate(), JSON_UNESCAPED_UNICODE);
        }

        // 주문완료 시점의 마이앱 추가 혜택 정책 저장
        if ($this->useMyapp) {
            $myappConfig = gd_policy('myapp.config');
            $order['myappPolicy'] = json_encode($myappConfig['benefit']['orderAdditionalBenefit'], JSON_UNESCAPED_UNICODE);
        }

        // 무통장입금 데이터 재포맷
        if ($orderInfo['settleKind'] == 'gb') {
            $bankInfo = $this->getBankInfo($orderInfo['bankAccount']);
            $order['bankAccount'] = $bankInfo['bankName'] . STR_DIVISION . $bankInfo['accountNumber'] . STR_DIVISION . $bankInfo['depositor'];
        } else {
            $order['bankAccount'] = '';
        }

        // orderInfo 전화번호/휴대폰번호 재설정
        $orderInfo['orderPhone'] = StringUtils::numberToPhone(str_replace('-', '', $orderInfo['orderPhone']));
        $orderInfo['orderCellPhone'] = StringUtils::numberToPhone(str_replace('-', '', $orderInfo['orderCellPhone']));
        $orderInfo['receiverPhone'] = StringUtils::numberToPhone(str_replace('-', '', $orderInfo['receiverPhone']));
        $orderInfo['receiverCellPhone'] = StringUtils::numberToPhone(str_replace('-', '', $orderInfo['receiverCellPhone']));

        // 해외배송 국가코드를 텍스트로 전환
        $orderInfo['receiverCountry'] = $this->getCountryName($orderInfo['receiverCountryCode']);

        // 해외전화번호 숫자 변환해서 해당 필드 추가 처리
        $orderInfo['orderPhonePrefix'] = $this->getCountryCallPrefix($orderInfo['orderPhonePrefixCode']);
        $orderInfo['orderCellPhonePrefix'] = $this->getCountryCallPrefix($orderInfo['orderCellPhonePrefixCode']);
        $orderInfo['receiverPhonePrefix'] = $this->getCountryCallPrefix($orderInfo['receiverPhonePrefixCode']);
        $orderInfo['receiverCellPhonePrefix'] = $this->getCountryCallPrefix($orderInfo['receiverCellPhonePrefixCode']);

        // orderInfo 이메일
        $orderInfo['orderEmail'] = $order['orderEmail'];

        // 일반주문인 경우
        if ($isWrite === false) {
            // 회원: 최근주문일자 반영 및 회원정보 반영
            // 비회원: 주문로그인
            if (MemberUtil::checkLogin() == 'member') {
                if (empty($order['memNo']) == true) {
                    throw new AlertRedirectException(__('잘못된 접근입니다. 다시 시도해주세요.'), null, null, '../order/cart.php', 'top');
                }

                if ($orderInfo['reflectApplyMember'] === 'y' || $orderInfo['reflectApplyDirectMember'] === 'y' || $orderInfo['reflectApplyShippingMember'] === 'y') {
                    $memData = [
                        'memNo'      => $order['memNo'],
                        'zipcode'    => $orderInfo['receiverZipcode'],
                        'zonecode'   => $orderInfo['receiverZonecode'],
                        'address'    => $orderInfo['receiverAddress'],
                        'addressSub' => $orderInfo['receiverAddressSub'],
                        'phone'      => $orderInfo['receiverPhone'],
                        'cellPhone'  => $orderInfo['receiverCellPhone'],
                        'lastSaleDt' => date('Y-m-d G:i:s'),
                    ];

                    $otherValue = [
                        'reflectApplyMemberInfo' => [ 'orderNo' => $order['orderNo'] ],
                    ];
                    $session = \App::getInstance('session');
                    $memberDAO = \App::load('Component\\Member\\MemberDAO');
                    $before = $memberDAO->selectMemberByOne($memData['memNo']);
                    $session->set(Member::SESSION_MODIFY_MEMBER_INFO, $before);

                } else {
                    $memData = [
                        'lastSaleDt' => date('Y-m-d G:i:s'),
                    ];
                }
                $arrBindMem = $this->db->get_binding(
                    DBTableField::tableMember(),
                    $memData,
                    'update',
                    array_keys($memData),
                    [
                        'sno',
                        'memNo',
                    ]
                );
                $this->db->bind_param_push($arrBindMem['bind'], 'i', Session::get('member.memNo'));
                $this->db->set_update_db(DB_MEMBER, $arrBindMem['param'], 'memNo=?', $arrBindMem['bind']);
                unset($arrBindMem);

                if($orderInfo['reflectApplyMember'] === 'y' || $orderInfo['reflectApplyDirectMember'] === 'y' || $orderInfo['reflectApplyShippingMember'] === 'y') {

                    $request = \App::getInstance('request');

                    $historyFilter = array_keys($memData);
                    $historyService = \App::load('Component\\Member\\History');
                    $historyService->setMemNo($memData['memNo']);
                    $historyService->setProcessor('member');
                    $historyService->setProcessorIp($request->getRemoteAddress());
                    $historyService->initBeforeAndAfter();
                    $historyService->setOtherValue($otherValue);
                    $historyService->addFilter($historyFilter);
                    $historyService->writeHistory();
                }

                // 배송지 추가 선택시 저장
                if ($orderInfo['reflectApplyDelivery'] === 'y') {
                    $deliveryData = [
                        'shippingTitle' => $orderInfo['receiverName'],
                        'shippingName' => $orderInfo['receiverName'],
                        'shippingCountryCode' => $orderInfo['receiverCountryCode'],
                        'shippingZipcode' => $orderInfo['receiverZipcode'],
                        'shippingZonecode' => $orderInfo['receiverZonecode'],
                        'shippingAddress' => $orderInfo['receiverAddress'],
                        'shippingAddressSub' => $orderInfo['receiverAddressSub'],
                        'shippingPhonePrefix' => $orderInfo['receiverPhonePrefix'],
                        'shippingPhone' => $orderInfo['receiverPhone'],
                        'shippingCellPhonePrefix' => $orderInfo['receiverCellPhonePrefix'],
                        'shippingCellPhone' => $orderInfo['receiverCellPhone'],
                        'shippingCity' => $orderInfo['receiverCity'],
                        'shippingState' => $orderInfo['receiverState'],
                    ];
                    if (empty($this->getShippingDefaultFlYn()) === true) {
                        $deliveryData['defaultFl'] = 'y';
                    }

                    // TODO 배송지 저장 실패시 처리
                    if (!$this->registShippingAddress($deliveryData)) {
                    }
                }
                if (\Component\Order\OrderMultiShipping::isUseMultiShipping() === true && \Globals::get('gGlobal.isFront') === false && $orderInfo['multiShippingFl'] == 'y') {
                    foreach ($orderInfo['reflectApplyDeliveryAdd'] as $key => $val) {
                        if ($val == 'y') {
                            $orderInfo['receiverPhoneAdd'][$key] = StringUtils::numberToPhone(str_replace('-', '', $orderInfo['receiverPhoneAdd'][$key]));
                            $orderInfo['receiverCellPhoneAdd'][$key] = StringUtils::numberToPhone(str_replace('-', '', $orderInfo['receiverCellPhoneAdd'][$key]));
                            $deliveryData = [
                                'shippingTitle' => $orderInfo['receiverNameAdd'][$key],
                                'shippingName' => $orderInfo['receiverNameAdd'][$key],
                                'shippingCountryCode' => $orderInfo['receiverCountryCodeAdd'][$key],
                                'shippingZipcode' => $orderInfo['receiverZipcodeAdd'][$key],
                                'shippingZonecode' => $orderInfo['receiverZonecodeAdd'][$key],
                                'shippingAddress' => $orderInfo['receiverAddressAdd'][$key],
                                'shippingAddressSub' => $orderInfo['receiverAddressSubAdd'][$key],
                                'shippingPhonePrefix' => $orderInfo['receiverPhonePrefixAdd'][$key],
                                'shippingPhone' => $orderInfo['receiverPhoneAdd'][$key],
                                'shippingCellPhonePrefix' => $orderInfo['receiverCellPhonePrefixAdd'][$key],
                                'shippingCellPhone' => $orderInfo['receiverCellPhoneAdd'][$key],
                                'shippingCity' => $orderInfo['receiverCityAdd'][$key],
                                'shippingState' => $orderInfo['receiverStateAdd'][$key],
                            ];
                            if (empty($this->getShippingDefaultFlYn()) === true) {
                                $deliveryData['defaultFl'] = 'y';
                            }

                            // TODO 배송지 저장 실패시 처리
                            if (!$this->registShippingAddress($deliveryData)) {
                            }
                        }
                    }
                }
            } elseif (MemberUtil::checkLogin() == 'guest') {
                // 비회원 주문 로그인 처리
                MemberUtil::guestOrder($order['orderNo'], $orderInfo['orderName']);
            }
        } // 수기주문인 경우
        else {
            if ($order['memNo'] > 0) {
                if ($orderInfo['reflectApplyMember'] === 'y') {
                    $memData = [
                        'zipcode'    => $orderInfo['receiverZipcode'],
                        'zonecode'   => $orderInfo['receiverZonecode'],
                        'address'    => $orderInfo['receiverAddress'],
                        'addressSub' => $orderInfo['receiverAddressSub'],
                        'phone'      => $orderInfo['receiverPhone'],
                        'cellPhone'  => $orderInfo['receiverCellPhone'],
                        'lastSaleDt' => date('Y-m-d G:i:s'),
                    ];
                } else {
                    $memData = [
                        'lastSaleDt' => date('Y-m-d G:i:s'),
                    ];
                }
                $arrBindMem = $this->db->get_binding(
                    DBTableField::tableMember(),
                    $memData,
                    'update',
                    array_keys($memData),
                    [
                        'sno',
                        'memNo',
                    ]
                );
                $this->db->bind_param_push($arrBindMem['bind'], 'i', $order['memNo']);
                $this->db->set_update_db(DB_MEMBER, $arrBindMem['param'], 'memNo=?', $arrBindMem['bind']);
                unset($arrBindMem);
            }

            // 관리자 메모
            //$order['adminMemo'] = $orderInfo['adminMemo'];

            // 상품º주문번호별 메모
            if($orderInfo['adminOrderGoodsMemo']){
                $orderAdmin = new OrderAdmin();
                $arrMemoData['mode'] = 'self_order';
                $arrMemoData['orderNo'] = $this->orderNo;
                $arrMemoData['orderMemoCd'] = $orderInfo['orderMemoCd'];
                $arrMemoData['adminOrderGoodsMemo'] = $orderInfo['adminOrderGoodsMemo'];
                $orderAdmin->insertAdminOrderGoodsMemo($arrMemoData);
            }
        }

        // 주문시 배송정보 회원정보에 반영
        if ($orderInfo['shippingDefault'] == 'y') {
            $this->defaultShippingAddress($orderInfo['shippingSno']);
        }

        //간편결제 관련 데이터 설정
        if ($orderInfo['fintechData']) {
            $order['fintechData'] = $orderInfo['fintechData'];
        }
        if ($orderInfo['checkoutData']) {
            $order['checkoutData'] = $orderInfo['checkoutData'];
        }
        $order['multiShippingFl'] = $orderInfo['multiShippingFl'];
        $order['trackingKey'] = $orderInfo['trackingKey'];

        // 주문 저장
        $arrBind = $this->db->get_binding(DBTableField::tableOrder(), $order, 'insert');
        $this->db->set_insert_db(DB_ORDER, $arrBind['param'], $arrBind['bind'], 'y', false);
        unset($arrBind);

        // 최초결제 결제히스토리 저장
        $history = [
            'orderNo' => $this->orderNo,// 주문번호
            'type' => 'fs', //타입 (setOrderProcessLog의 payHistoryType 참조)
            'settlePrice' => $order['settlePrice'],// 실결제금액
            'totalGoodsPrice' => $order['totalGoodsPrice'],// 상품판매금액
            'totalDeliveryCharge' => $order['totalDeliveryCharge'],// 배송비 (지역별배송비 포함)
            'totalDeliveryInsuranceFee' => $order['totalDeliveryInsuranceFee'],// 해외배송보험료
            'totalGoodsDcPrice' => $order['totalGoodsDcPrice'],// 상품할인
            'totalMemberDcPrice' => $order['totalMemberDcPrice'],// 회원추가할인(상품)
            'totalMemberOverlapDcPrice' => $order['totalMemberOverlapDcPrice'],// 회원중복할인(상품)
            'totalMemberDeliveryDcPrice' => $order['totalMemberDeliveryDcPrice'],// 회원할인(배송비)
            'totalCouponGoodsDcPrice' => $order['totalCouponGoodsDcPrice'],// 쿠폰할인(상품)
            'totalCouponOrderDcPrice' => $order['totalCouponOrderDcPrice'],// 쿠폰할인(주문)
            'totalCouponDeliveryDcPrice' => $order['totalCouponDeliveryDcPrice'],// 쿠폰할인(배송비)
            'useDeposit' => $order['useDeposit'],// 예치금 useDeposit
            'useMileage' => $order['useMileage'],// 마일리지 useMileage
            'totalMileage' => $order['totalMileage'],// 총적립금액 totalMileage
            'memo' => '', //설명
        ];
        // 마이앱 사용에 따른 분기 처리
        if ($this->useMyapp) {
            $history['totalMyappDcPrice'] = $order['totalMyappDcPrice'];// 마이앱할인(상품)
        }
        $this->setPayHistory($history);

        // 주문자/수취인 정보 저장
        $orderInfo['orderNo'] = $this->orderNo;
        $arrBind = $this->db->get_binding(DBTableField::tableOrderInfo(), $orderInfo, 'insert');
        $this->db->set_insert_db(DB_ORDER_INFO, $arrBind['param'], $arrBind['bind'], 'y', false);
        $orderInfoSno[0] = $this->db->insert_id();
        unset($arrBind);

        if (\Component\Order\OrderMultiShipping::isUseMultiShipping() === true && \Globals::get('gGlobal.isFront') === false && $orderInfo['multiShippingFl'] == 'y') {
            $tmpOrderInfo = $orderInfo;
            $orderBasic = gd_policy('order.basic');
            foreach ($orderInfo['receiverNameAdd'] as $key => $val) {
                $orderInfo['receiverPhoneAdd'][$key] = StringUtils::numberToPhone(str_replace('-', '', $orderInfo['receiverPhoneAdd'][$key]));
                $orderInfo['receiverCellPhoneAdd'][$key] = StringUtils::numberToPhone(str_replace('-', '', $orderInfo['receiverCellPhoneAdd'][$key]));
                $tmpOrderInfo['receiverName'] = $orderInfo['receiverNameAdd'][$key];
                $tmpOrderInfo['receiverCountryCode'] = $orderInfo['receiverCountryCodeAdd'][$key];
                $tmpOrderInfo['receiverCity'] = $orderInfo['receiverCityAdd'][$key];
                $tmpOrderInfo['receiverState'] = $orderInfo['receiverStateAdd'][$key];
                $tmpOrderInfo['receiverAddress'] = $orderInfo['receiverAddressAdd'][$key];
                $tmpOrderInfo['receiverAddressSub'] = $orderInfo['receiverAddressSubAdd'][$key];
                $tmpOrderInfo['receiverZonecode'] = $orderInfo['receiverZonecodeAdd'][$key];
                $tmpOrderInfo['receiverZipcode'] = $orderInfo['receiverZipcodeAdd'][$key];
                $tmpOrderInfo['receiverPhonePrefixCode'] = $orderInfo['receiverPhonePrefixCodeAdd'][$key];
                $tmpOrderInfo['receiverPhone'] = $orderInfo['receiverPhoneAdd'][$key];
                $tmpOrderInfo['receiverCellPhonePrefixCode'] = $orderInfo['receiverCellPhonePrefixCodeAdd'][$key];
                $tmpOrderInfo['receiverCellPhone'] = $orderInfo['receiverCellPhoneAdd'][$key];
                $tmpOrderInfo['orderMemo'] = $orderInfo['orderMemoAdd'][$key];
                $tmpOrderInfo['reflectApplyDelivery'] = $orderInfo['reflectApplyDeliveryAdd'][$key];
                $tmpOrderInfo['orderInfoCd'] = $key + 1;
                if ($orderBasic['useSafeNumberFl'] == 'y') {
                    $tmpOrderInfo['receiverUseSafeNumberFl'] = $orderInfo['receiverUseSafeNumberFlAdd'][$key];
                }

                // 주문자/수취인 정보 저장
                $orderInfo['orderNo'] = $this->orderNo;
                $arrBind = $this->db->get_binding(DBTableField::tableOrderInfo(), $tmpOrderInfo, 'insert');
                $this->db->set_insert_db(DB_ORDER_INFO, $arrBind['param'], $arrBind['bind'], 'y', false);
                $orderInfoSno[$key] = $this->db->insert_id();
                unset($arrBind);
            }
        }

        //
        foreach ($orderMultiDeliverySno as $key => $val) {
            foreach ($val as $tVal) {
                $arrData['orderInfoSno'] = $orderInfoSno[$key];
                $arrBind = $this->db->get_binding(DBTableField::tableOrderDelivery(), $arrData, 'update', array_keys($arrData));
                $this->db->bind_param_push($arrBind['bind'], 'i', $tVal);
                $this->db->set_update_db(DB_ORDER_DELIVERY, $arrBind['param'], 'sno = ?', $arrBind['bind']);
                unset($arrData);
            }
        }

        // 주문 사은품 정보 저장
        if (isset($orderInfo['gift'])) {
            $giftPresentNo = null;
            // 사은품 콤포넌트 호출
            $giftPresent = \App::load('\\Component\\Gift\\Gift');
            foreach ($orderInfo['gift'] as $presentSno => $giftData) {
                // 사은품 정책 저장
                if ($presentSno != $giftPresentNo) {
                    $giftPresentNo = $presentSno;
                    $giftPresentData = $giftPresent->getGiftPresentData($giftPresentNo);
                }
                foreach ($giftData as $gift) {
                    $gift['orderNo'] = $this->orderNo;
                    $gift['presentSno'] = $presentSno;
                    $gift['minusStockFl'] = 'n'; // 무조건 n 스킨에서 값이 잘못 던져짐
                    $gift['giftPolicy'] = json_encode($giftPresentData, JSON_UNESCAPED_UNICODE);
                    // 사은품 선택한것이 있으면 저장..
                    if (isset($gift['giftNo']) === true) {
                        // 주문 사은품 정보 저장
                        $arrBind = $this->db->get_binding(DBTableField::tableOrderGift(), $gift, 'insert');
                        $this->db->set_insert_db(DB_ORDER_GIFT, $arrBind['param'], $arrBind['bind'], 'y', false);
                        unset($arrBind);
                    }
                }
                unset($giftData);
            }
            unset($orderInfo['gift']);
        }

        // 주문쿠폰 정보
        $couponInfo = $goodsCouponInfo;
        $goodsPriceArr = [
            'goodsPriceSum'      => $order['totalSumGoodsPrice']['goodsPrice'],
            'optionPriceSum'     => $order['totalSumGoodsPrice']['optionPrice'],
            'optionTextPriceSum' => $order['totalSumGoodsPrice']['optionTextPrice'],
            'addGoodsPriceSum'   => $order['totalSumGoodsPrice']['addGoodsPrice'],
        ];
        if (empty($orderInfo['couponApplyOrderNo']) === false) {
            $orderCouponNos = explode(INT_DIVISION, $orderInfo['couponApplyOrderNo']);
            $coupon = \App::load('\\Component\\Coupon\\Coupon');

            foreach ($orderCouponNos as $orderCouponNo) {
                if ($orderCouponNo) { // 적용쿠폰번호가 있을 경우 DB 삽입
                    // 장바구니에 사용된 회원쿠폰의 정율도 정액으로 계산된 금액
                    $realOrderCouponPriceData = $coupon->getMemberCouponPrice($goodsPriceArr, $orderCouponNo);
                    if ($realOrderCouponPriceData['memberCouponAlertMsg'][$orderCouponNo] == 'LIMIT_MIN_PRICE') {
                        // @todo 'LIMIT_MIN_PRICE' 일때 구매금액 제한에 걸려 사용 못하는 쿠폰 처리
                        // @todo 적용된 쿠폰 제거?
                        // @todo 수량 변경 시 구매금액 제한에 걸림
                        true;
                    }
                    $arrTmp = $coupon->getMemberCouponInfo($orderCouponNo, 'c.couponUseType, c.couponNm, c.couponUseType, mc.memberCouponStartDate, mc.memberCouponEndDate');
                    $orderCouponInfo = [
                        'orderNo' => $this->orderNo,
                        'orderCd' => '',
                        'goodsNo' => '',
                        'memberCouponNo' => $orderCouponNo,
                        'expireSdt' => $arrTmp['memberCouponStartDate'],
                        'expireEdt' => $arrTmp['memberCouponEndDate'],
                        'couponNm' => $arrTmp['couponNm'],
                        'couponUseType' => $arrTmp['couponUseType'],
                    ];

                    // 주문할인 쿠폰 금액 적용
                    if ($order['totalCouponOrderDcPrice'] > 0 && $realOrderCouponPriceData['memberCouponSalePrice'][$orderCouponNo] > 0) {
                        if ($order['totalCouponOrderDcPrice'] < $realOrderCouponPriceData['memberCouponSalePrice'][$orderCouponNo]) {
                            $orderCouponInfo['couponPrice'] = $order['totalCouponOrderDcPrice'];
                        } else {
                            $orderCouponInfo['couponPrice'] = array_shift($realOrderCouponPriceData['memberCouponSalePrice']);
                        }
                    }

                    // 배송비 쿠폰 금액 적용
                    if ($order['totalCouponDeliveryDcPrice'] > 0 && $realOrderCouponPriceData['memberCouponDeliveryPrice'][$orderCouponNo] > 0) {
                        if ($order['totalCouponDeliveryDcPrice'] < $realOrderCouponPriceData['memberCouponDeliveryPrice'][$orderCouponNo]) {
                            $orderCouponInfo['couponPrice'] = $order['totalCouponDeliveryDcPrice'];
                        } else {
                            $orderCouponInfo['couponPrice'] = array_shift($realOrderCouponPriceData['memberCouponDeliveryPrice']);
                        }
                    }

                    // 마일리지 적립 쿠폰 금액 적용
                    if ($order['totalCouponOrderMileage'] > 0 && $realOrderCouponPriceData['memberCouponAddMileage'][$orderCouponNo] > 0) {
                        if ($order['totalCouponOrderMileage'] < $realOrderCouponPriceData['memberCouponAddMileage'][$orderCouponNo]) {
                            $orderCouponInfo['couponMileage'] = $order['totalCouponOrderMileage'];
                        } else {
                            $orderCouponInfo['couponMileage'] = array_shift($realOrderCouponPriceData['memberCouponAddMileage']);
                        }
                    }

                    array_push($couponInfo, $orderCouponInfo);
                }
            }
        }

        // 쿠폰 데이터 저장
        if (empty($couponInfo) === false) {
            foreach ($couponInfo as $cKey => $cVal) {
                // 쿠폰 사용 정보 저장
                $arrBind = $this->db->get_binding(DBTableField::tableOrderCoupon(), $cVal, 'insert');
                $this->db->set_insert_db(DB_ORDER_COUPON, $arrBind['param'], $arrBind['bind'], 'y', false);
                unset($arrBind);
                unset($tmp);
            }
        }

        // 세금 계산서 저장 설정
        if ((in_array($orderInfo['settleKind'], $this->settleKindReceiptPossible) === true) && $orderInfo['receiptFl'] == 't') {
            $taxInfo = gd_policy('order.taxInvoice');
            $member = \App::load('\\Component\\Member\\Member');
            $memInfo = $member->getMemberId($order['memNo']);
            // 주문서 저장 설정
            $taxInfo['applicantNm'] =
            $taxInfo['requestNm'] = $orderInfo['orderName'];
            $taxInfo['applicantId'] =
            $taxInfo['requestId'] = $memInfo ? $memInfo['memId'] : '비회원';
            $taxInfo['orderNo'] = $this->orderNo;
            $taxInfo['issueMode'] = 'u';
            $taxInfo['statusFl'] = 'r';
            $taxInfo['requestNm'] = $orderInfo['orderName'];
            $taxInfo['requestGoodsNm'] = $order['orderGoodsNm'];
            $taxInfo['requestIP'] = Request::getRemoteAddress();
            $taxInfo['taxCompany'] = $orderInfo['taxCompany'];
            $taxInfo['taxBusiNo'] = $orderInfo['taxBusiNo'];
            $taxInfo['taxCeoNm'] = $orderInfo['taxCeoNm'];
            $taxInfo['taxService'] = $orderInfo['taxService'];
            $taxInfo['taxItem'] = $orderInfo['taxItem'];
            $taxInfo['taxEmail'] = is_array($orderInfo['taxEmail']) ? implode('@', $orderInfo['taxEmail']) : $orderInfo['taxEmail'];
            $taxInfo['taxZipcode'] = $orderInfo['taxZipcode'];
            $taxInfo['taxZonecode'] = $orderInfo['taxZonecode'];
            $taxInfo['taxAddress'] = $orderInfo['taxAddress'];
            $taxInfo['taxAddressSub'] = $orderInfo['taxAddressSub'];
            //$taxInfo['settlePrice'] = $order['settlePrice']; // 면세 상품은 빠져야 함
            //$taxInfo['settlePrice'] = $taxSupplyPrice + $taxVatPrice;
            //$taxInfo['supplyPrice'] = $taxSupplyPrice;
            //$taxInfo['taxPrice'] = $taxVatPrice;
            $taxInfo['taxStepFl'] = $taxInfo['taxStepFl'];
            $taxInfo['taxDeliveryCompleteFl'] = $taxInfo['taxDeliveryCompleteFl'];
            $taxInfo['taxPolicy'] = $taxInfo['taxDeliveryFl'] . STR_DIVISION . $taxInfo['TaxMileageFl'] . STR_DIVISION . $taxInfo['taxDepositFl']; //정책저장

            // 로그 설정
            $taxInfo['taxLog'] = '====================================================' . chr(10);
            $taxInfo['taxLog'] .= '세금계산서 신청 : 확인시간(' . date('Y-m-d H:i:s') . ')' . chr(10);
            $taxInfo['taxLog'] .= '====================================================' . chr(10);
            $taxInfo['taxLog'] .= '처리상태 : 발행 신청' . chr(10);
            $taxInfo['taxLog'] .= '요청정보 : 주문 시 고객 요청' . chr(10);
            $taxInfo['taxLog'] .= '처리 IP : ' . Request::getRemoteAddress() . chr(10);
            $taxInfo['taxLog'] .= '====================================================' . chr(10);

            // 세금 계산서 저장
            $arrBind = $this->db->get_binding(DBTableField::tableOrderTax(), $taxInfo, 'insert');
            $this->db->set_insert_db(DB_ORDER_TAX, $arrBind['param'], $arrBind['bind'], 'y', false);
            unset($arrBind);
        }

        // 현금영수증 저장 설정
        if (in_array($orderInfo['settleKind'], $this->settleKindReceiptPossible) === true && $orderInfo['receiptFl'] == 'r') {
            // --- PG 설정 불러오기
            $pgConf = gd_pgs();

            // 주문서 저장 설정
            $receipt['orderNo'] = $this->orderNo;
            $receipt['issueMode'] = 'u';
            $receipt['statusFl'] = 'r';
            $receipt['servicePrice'] = 0;
            $receipt['requestNm'] = $orderInfo['orderName'];
            $receipt['requestGoodsNm'] = $order['orderGoodsNm'];
            $receipt['requestIP'] = Request::getRemoteAddress();
            $receipt['requestEmail'] = $orderInfo['orderEmail'];
            $receipt['requestCellPhone'] = $orderInfo['orderCellPhone'];
            $receipt['useFl'] = $orderInfo['cashUseFl'];
            $receipt['certFl'] = $orderInfo['cashCertFl'];
            $receipt['certNo'] = Encryptor::encrypt($orderInfo['cashCertNo']);
            $receipt['settlePrice'] = $order['settlePrice'];
            $receipt['pgName'] = $pgConf['pgName'];
            $receipt['adminMemo'] = $orderInfo['cashCertNo'];

            $receipt['supplyPrice'] = $taxSupplyPrice;
            $receipt['taxPrice'] = $taxVatPrice;
            $receipt['freePrice'] = $taxFreePrice;

            // 현금영수증 저장
            $arrBind = $this->db->get_binding(DBTableField::tableOrderCashReceipt(), $receipt, 'insert');
            $this->db->set_insert_db(DB_ORDER_CASH_RECEIPT, $arrBind['param'], $arrBind['bind'], 'y', false);
            unset($arrBind);
            $sno = $this->db->insert_id();
            $this->db->set_update_db(DB_ORDER_CASH_RECEIPT, 'firstRegDt = regDt', 'sno = '.$sno);
        }

        // 주문처리중 접수된 주문건으로 인해 재고를 다시한번 체크
        if (!$this->recheckOrderStockCnt($this->orderNo)) {
            throw new Exception(__('재고 부족으로 구매가 불가능합니다.'));
        }

        // 상태 변경에 따른 일괄 처리 (마일리지/예치금/쿠폰사용 및 재고차감 처리)
        $arrOrderGoodsSno['changeStatus'] = $orderStatusPre;
        if ($orderInfo['settleKind'] == 'gb') {
            $this->statusChangeCodeO($this->orderNo, $arrOrderGoodsSno, false);
        } elseif ($orderInfo['settleKind'] == self::SETTLE_KIND_ZERO) {
            $this->statusChangeCodeP($this->orderNo, $arrOrderGoodsSno, false);
        } else {
            // 무통장 입금인 경우 주문 접수에 관련 된 작업을 진행하며, PG의 경우 성공 후 반드시 아래의 작업을 별도로 실행해야 합니다.
            // 입금대기 (o1) 상태에 처리해야 할 마일리지/예치금/쿠폰 사용체크와 마일리지의 경우 설정에 따라 지급합니다.
        }
    }

	function getLastOrderTaxInfo($memNo){
		$strSQL = 'SELECT taxCompany as company,taxBusiNo,taxCeoNm as ceo,taxService as service
						,taxItem as item,taxEmail as email,taxZipcode as comZipcode,taxZonecode as comZonecode,taxAddress as comAddress,taxAddressSub as comAddressSub 
						FROM es_orderTax a JOIN es_order b ON a.orderNo=b.orderNo WHERE b.memNo= '.$memNo.' ORDER BY a.sno DESC LIMIT 1';        		
		$getData = $this->db->query_fetch($strSQL, $arrBind, false);

		return gd_htmlspecialchars_stripslashes($getData);
	}
}