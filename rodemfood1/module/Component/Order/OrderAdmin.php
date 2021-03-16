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
namespace Component\Order;

use App;
use Component\Bankda\BankdaOrder;
use Component\Database\DBTableField;
use Component\Delivery\Delivery;
use Component\Deposit\Deposit;
use Component\Godo\MyGodoSmsServerApi;
use Component\Godo\NaverPayAPI;
use Component\Mail\MailMimeAuto;
use Component\Mall\Mall;
use Component\Member\Manager;
use Component\Mileage\Mileage;
use Component\Naver\NaverPay;
use Component\Sms\Code;
use Component\Validator\Validator;
use Exception;
use Framework\Debug\Exception\AlertBackException;
use Framework\Debug\Exception\Except;
use Framework\Debug\Exception\LayerNotReloadException;
use Framework\StaticProxy\Proxy\UserFilePath;
use Framework\Utility\ArrayUtils;
use Framework\Utility\DateTimeUtils;
use Framework\Utility\NumberUtils;
use Framework\Utility\StringUtils;
use Globals;
use LogHandler;
use Request;
use Session;
use Vendor\Spreadsheet\Excel\Reader as SpreadsheetExcelReader;
use Component\Page\Page;

/**
 * 주문 class
 * 주문 관련 관리자 Class
 *
 * @package Bundle\Component\Order
 * @author  Jong-tae Ahn <qnibus@godo.co.kr>
 */
class OrderAdmin extends \Bundle\Component\Order\OrderAdmin
{
	 /**
     * 주문상세페이지 - 수령자정보 수정
     * 상품준비중 리스트 - 묶음배송 수령자 정보수정
     *
     * @param array $arrData 저장할 정보의 배열
     *
     * @throws Exception
     */
    public function updateOrderReceiverInfo($arrData)
    {
        if(trim($arrData['info']['data']) !== ''){
            $arrData['info'] = array_merge((array)$arrData['info'], (array)json_decode($arrData['info']['data'], true));
        }
        $upateArr = [
            'receiverName',
			'presentSenderNm',
            'receiverCountryCode',
            'receiverPhonePrefixCode',
            'receiverPhonePrefix',
            'receiverPhone',
            'receiverCellPhonePrefixCode',
            'receiverCellPhonePrefix',
            'receiverCellPhone',
            'receiverZipcode',
            'receiverZonecode',
            'receiverCountry',
            'receiverState',
            'receiverCity',
            'receiverAddress',
            'receiverAddressSub',
            'orderMemo',
        ];

        $arrExclude = null;

        if (isset($arrData['info']['receiverPhone']) && is_string($arrData['info']['receiverPhone']) === true) {
            $arrData['info']['receiverPhone'] = str_replace("-", "", $arrData['info']['receiverPhone']);
            $arrData['info']['receiverPhone'] = StringUtils::numberToPhone($arrData['info']['receiverPhone']);
        }
        if (isset($arrData['info']['receiverCellPhone']) && is_string($arrData['info']['receiverCellPhone']) === true) {
            $arrData['info']['receiverCellPhone'] = str_replace("-", "", $arrData['info']['receiverCellPhone']);
            $arrData['info']['receiverCellPhone'] = StringUtils::numberToPhone($arrData['info']['receiverCellPhone']);
        }

        // 해외몰 국가, 번호 치환
        if (empty($arrData['info']['mallSno']) == false && $arrData['info']['mallSno'] > 1) {
            // 데이터명 다른 경우
            if (empty($arrData['info']['receiverCountrycode']) === false && empty($arrData['info']['receiverCountryCode'])) {
                $arrData['info']['receiverCountryCode'] = $arrData['info']['receiverCountrycode'];
            }
            // 해외전화번호 숫자 변환해서 해당 필드 추가 처리
            $arrData['info']['orderPhonePrefix'] = $this->getCountryCallPrefix($arrData['info']['orderPhonePrefixCode']);
            $arrData['info']['orderCellPhonePrefix'] = $this->getCountryCallPrefix($arrData['info']['orderCellPhonePrefixCode']);
            $arrData['info']['receiverPhonePrefix'] = $this->getCountryCallPrefix($arrData['info']['receiverPhonePrefixCode']);
            $arrData['info']['receiverCellPhonePrefix'] = $this->getCountryCallPrefix($arrData['info']['receiverCellPhonePrefixCode']);
            // 해외배송 국가코드를 텍스트로 전환
            $arrData['info']['receiverCountry'] = $this->getCountryName($arrData['info']['receiverCountryCode']);
        }

        // 안심번호 통신 오류인 경우
        if (isset($arrData['info']['safeNumberMode'])) {
            if ($arrData['info']['safeNumberMode'] == 'cancel') {
                $orderBasic = gd_policy('order.basic');
                // 안심번호가 없이 해지 및 off 상태인 경우 사용안함으로 상태값만 변경
                if (empty($arrData['info']['receiverSafeNumber'])) {
                    $upateArr[] = 'receiverUseSafeNumberFl';
                    $arrData['info']['receiverUseSafeNumberFl'] = 'n';
                } else if (isset($orderBasic['safeNumberServiceFl']) && $orderBasic['safeNumberServiceFl'] == 'off') {
                    $upateArr[] = 'receiverUseSafeNumberFl';
                    $arrData['info']['receiverUseSafeNumberFl'] = 'c';
                } else {
                    $tmpData['sno'] = $arrData['info']['sno'];
                    $tmpData['phoneNumber'] = str_replace("-", "", $arrData['info']['receiverOriginCellPhone']);
                    $tmpData['safeNumber'] = str_replace("-", "", $arrData['info']['receiverSafeNumber']);
                    $safeNumber = \App::load('Component\\Service\\SafeNumber');
                    $safeNumber->cancelSafeNumber($tmpData);
                }
            } else if ($arrData['info']['safeNumberMode'] == 'except') {
                $arrExclude[] = 'receiverCellPhonePrefixCode';
                $arrExclude[] = 'receiverCellPhonePrefix';
                $arrExclude[] = 'receiverCellPhone';
            }
        }

        $updateData = array_intersect_key($arrData['info'], array_flip($upateArr));
        if(count($updateData) > 0){
            $arrBind = $this->db->get_binding(DBTableField::tableOrderInfo(), $updateData, 'update', $upateArr, $arrExclude);
            $this->db->bind_param_push($arrBind['bind'], 'i', $arrData['info']['sno']);
            $this->db->set_update_db(DB_ORDER_INFO, $arrBind['param'], 'sno = ?', $arrBind['bind']);

            $logger = \App::getInstance('logger');
            if($arrData['mode'] === 'update_receiver_info'){
                $loggerMessage = '묶음배송 팝업페이지에서';
            }
            else if ($arrData['mode'] === 'modifyReceiverInfo'){
                $loggerMessage = '주문상세페이지에서';
            }
            else {}
            $logger->channel('order')->info($loggerMessage .' 수령자정보 수정 [처리자 : ' . \Session::get('manager.managerId') . ']');
        }
        unset($arrBind);
    }
	
}