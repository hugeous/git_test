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
namespace Controller\Front\Mypage;

use Component\Order\OrderAdmin;
use Component\Sms\Code;
use Component\Sms\SmsAutoOrder;
use Component\Database\DBTableField;
use Component\Goods\GoodsCate;
use Component\Page\Page;
use Exception;
use Framework\Debug\Exception\AlertOnlyException;
use Framework\Debug\Exception\AlertReloadException;
use Framework\Debug\Exception\AlertRedirectException;
use Message;
use Request;

/**
 * Class MypageQnaController
 *
 * @package Bundle\Controller\Front\Mypage
 * @author  Jong-tae Ahn <qnibus@godo.co.kr>
 * @author  Shin Donggyu <artherot@godo.co.kr>
 */
class OrderPsController extends \Bundle\Controller\Front\Mypage\OrderPsController
{
	/**
     * {@inheritdoc}
     */
    public function index()
    {
        try {
            // 리퀘스트 처리
            $postValue = Request::post()->xss()->toArray();

            // 모듈 설정
            /** @var OrderAdmin $order */
            $order = \App::load('\\Component\\Order\\OrderAdmin');
            $originOrder = \App::load('\\Component\\Order\\Order');

            switch ($postValue['mode']) {
                // 주문취소 처리
                case 'cancelRegist':
                    $orderCancelData = $originOrder->getOrderData($postValue['orderNo']);
                    if (substr($orderCancelData['orderStatus'], 0, 1) !== 'o') {
                        $this->json(
                            [
                                'code'    => 200,
                                'message' => __('주문을 취소할 수 없습니다.'),
                            ]
                        );
                        break;
                    }
                    $orderData = $order->getOrderView($postValue['orderNo']);
                    if($orderData['orderChannelFl'] === 'etc'){
                        $externalOrder = \App::load('\\Component\\Order\\ExternalOrder');
                        $externalOrder->setStatusChangeCancel($orderData);
                    }
                    else {
                        $order->setStatusChangeCancel($postValue['orderNo']);
                    }

                    $this->json(
                        [
                            'code'    => 200,
                            'message' => __('주문이 정상취소 되었습니다.'),
                        ]
                    );
                    break;

                // 구매확정
                case 'settleRegist':
                    $orderData = $order->getOrderView($postValue['orderNo']);
                    if($orderData['orderChannelFl'] === 'etc'){
                        $externalOrder = \App::load('\\Component\\Order\\ExternalOrder');
                        $externalOrder->setStatusChangeSettle($orderData, $postValue['orderGoodsNo']);
                    }
                    else {
                        $order->setStatusChangeSettle($postValue['orderNo'], $postValue['orderGoodsNo']);
                    }
                    $this->json(
                        [
                            'code'    => 200,
                            'message' => __('구매확정이 정상처리 되었습니다.'),
                        ]
                    );
                    break;

                //구매확정 일괄적용
                case 'settleRegistAll':
                    foreach ($postValue['orderGoodsNo'] as $orderGoodsNo) {
                        $order->setStatusChangeSettle($postValue['orderNo'], $orderGoodsNo);
                    }

                    throw new AlertReloadException(__('구매확정이 정상처리 되었습니다.'), null, null, 'parent');

                    break;

                // 수취확인
                case 'deliveryCompleteRegist':
                    $order->setStatusChangeDeliveryComplete($postValue['orderNo'], $postValue['orderGoodsNo']);
                    $this->json(
                        [
                            'code'    => 200,
                            'message' => __('수취확인이 정상처리 되었습니다.'),
                        ]
                    );
                    break;

                // 환불/반품 처리
                case 'refundRegist':
                case 'backRegist':
                    // 플러스샵을 사용하는 경우에만 처리
                    if (gd_is_plus_shop(PLUSSHOP_CODE_USEREXCHANGE) === true) {
                        // 처리할 상품별 수량
                        foreach ($postValue['orderGoodsNo'] as $cKey => $cVal) {
                            $goodsData[$cVal] = $postValue['claimGoodsCnt'][$cVal];
                        }

                        $handleMode = substr($postValue['mode'], 0, 1);
                        $bundleData = [
                            'userHandleReason'        => $postValue['userHandleReason'],
                            'userHandleDetailReason'  => $postValue['userHandleDetailReason'],
                            'userRefundBankName'      => $postValue['userRefundBankName'],
                            'userRefundAccountNumber' => $postValue['userRefundAccountNumber'],
                            'userRefundDepositor'     => $postValue['userRefundDepositor'],
                            'userHandleGoodsCnt'     => $goodsData,
                        ];
                        $order->requestUserHandle($postValue['orderNo'], $postValue['orderGoodsNo'], $handleMode, $bundleData);
                        if ($handleMode == 'r') {
                            $config = ['smsAutoCodeType' => Code::REFUND];
                        } else {
                            $config = ['smsAutoCodeType' => Code::BACK];
                        }
                        $smsAutoOrder = new SmsAutoOrder($config);
                        $smsAutoOrder->setOrderNo($postValue['orderNo']);
                        $smsAutoOrder->setOrderGoodsNo($postValue['orderGoodsNo']);
                        $smsAutoOrder->autoSend();
                        if (empty($goodsData) === false && empty($postValue['claimGoodsCnt']) === false) {
                            throw new AlertRedirectException(($handleMode == 'r' ? __('주문 취소') : __('반품')) . ' ' . __('신청이 완료되었습니다.'), null, null, $postValue['returnUrl'], 'parent');
                        } else {
                            throw new AlertReloadException(($handleMode == 'r' ? __('주문 취소') : __('반품')) . ' ' . __('접수가 완료되었습니다.'), null, null, 'parent');
                        }
                    }

                    break;

                // 교환 처리
                case 'exchangeRegist':
                    // 플러스샵을 사용하는 경우에만 처리
                    if (gd_is_plus_shop(PLUSSHOP_CODE_USEREXCHANGE) === true) {
                        // 처리할 상품별 수량
                        foreach ($postValue['orderGoodsNo'] as $cKey => $cVal) {
                            $goodsData[$cVal] = $postValue['claimGoodsCnt'][$cVal];
                        }

                        $handleMode = substr($postValue['mode'], 0, 1);
                        $bundleData = [
                            'userHandleReason'       => $postValue['userHandleReason'],
                            'userHandleDetailReason' => $postValue['userHandleDetailReason'],
                            'userHandleGoodsCnt'     => $goodsData,
                        ];
                        $order->requestUserHandle($postValue['orderNo'], $postValue['orderGoodsNo'], $handleMode, $bundleData);
                        $config = ['smsAutoCodeType' => Code::EXCHANGE];
                        $smsAutoOrder = new SmsAutoOrder($config);
                        $smsAutoOrder->setOrderNo($postValue['orderNo']);
                        $smsAutoOrder->setOrderGoodsNo($postValue['orderGoodsNo']);
                        $smsAutoOrder->autoSend();
                        if (empty($goodsData) === false && empty($postValue['claimGoodsCnt']) === false) {
                            throw new AlertRedirectException(__('교환 신청이 완료되었습니다.'), null, null, $postValue['returnUrl'], 'parent');
                        } else{
                            throw new AlertReloadException(__('교환 접수가 완료되었습니다.'), null, null, 'parent');
                        }
                    }

                    break;

                // 현금영수증 신청
                case 'cashReceiptRequest':
                    try {
                        $orderData = $order->getOrderView($postValue['orderNo']);
                        if($orderData['orderChannelFl'] === 'etc'){
                            throw new Exception(__("기타채널주문건은 현금영수증 발행을 할 수 없습니다."));
                        }

                        $cashReceipt = \App::load('\\Component\\Payment\\CashReceipt');
                        $result = $cashReceipt->saveCashReceiptUser($postValue);

                        // 현금영수증 입력 정보 저장
                        if ($result['status'] == 'ok') {
                            $cashReceipt->saveMemberCashReceiptInfo();
                        }

                        $this->alert($result['msg'], null, null, null, 'parent.location.reload();');
                    } catch (Exception $e) {
                        throw new AlertOnlyException($e->getMessage());
                    }
                    break;

                // 세금계산서
                case 'taxInvoiceRequest':
                    try {
                        $postValue['memberOrder'] = true;
                        $orderData = $order->getOrderView($postValue['orderNo']);
                        if($orderData['orderChannelFl'] === 'etc'){
                            throw new Exception(__("기타채널주문건은 세금계산서 발행을 할 수 없습니다."));
                        }

                        $tax = \App::load('\\Component\\Order\\Tax');
                        $tax->saveTaxInvoice($postValue);

                        $this->alert(__("세금계산서 신청이 완료되었습니다."), null, null, null, 'parent.location.reload();');
                    } catch (Exception $e) {
                        throw new AlertOnlyException($e->getMessage());
                    }
                    break;

                // 세금계산서 업데이트
                case 'taxInvoicePrint':
                    try {

                        $tax = \App::load('\\Component\\Order\\Tax');
                        $tax->taxInvoicePrint($postValue);

                    } catch (Exception $e) {
                        throw new AlertOnlyException($e->getMessage());
                    }
                    break;

                // 배송지 정보 수정 (레이어처리)
                case 'receiver_correct':
                    try {
                        $order->setOrderReceiverCorrect($postValue);
                        throw new AlertReloadException(__('배송정보가 수정되었습니다.'), null, null, 'parent');

                    } catch (Exception $e) {
                        throw new AlertOnlyException($e->getMessage());
                    }

                    break;

                // 에스크로 구매확인
                case 'escrowConfirm':
                    try {
                        $this->getView()->setPageName('mypage/escrow_confirm');
                        $this->setData('orderNo', $postValue['orderNo']);
                        $this->setData('pgName', $postValue['pgName']);
                    } catch (Exception $e) {
                        //echo $e->getMessage();
                    }

                    break;

            }

            // 에스크로 구매확인을 제외하고는 exit() 처리
            if ($postValue['mode'] != 'escrowConfirm') {
                exit();
            }

        } catch (Exception $e) {
            if (Request::isAjax()) {
                $this->json(
                    [
                        'code'    => 0,
                        'message' => $e->getMessage(),
                    ]
                );
            } else {
                throw $e;
            }
        }
    }
}