<?php

/**
 * 상품노출형태 관리
 * @author    atomyang
 * @version   1.0
 * @since     1.0
 * @copyright ⓒ 2016, NHN godo: Corp.
 */
namespace Component\Excel;


use Component\Database\DBTableField;
use Component\Member\MemberDAO;
use Encryptor;
use Framework\StaticProxy\Proxy\FileHandler;
use Framework\Utility\DateTimeUtils;
use Framework\Utility\StringUtils;
use Framework\Utility\NumberUtils;
use LogHandler;
use Request;
use Session;
use UserFilePath;
use Exception;
use Globals;
use Component\Member\Group\Util as GroupUtil;
use Component\Member\Manager;
use Component\Validator\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ExcelRequest extends \Bundle\Component\Excel\ExcelRequest
{
	/*
    * 주문목록
    */
    public function getOrderList($whereCondition, $excelField, $defaultField, $excelFieldName)
    {
        $orderAdmin = \App::load('\\Component\\Order\\OrderAdmin');

        $excelField = explode(STR_DIVISION, $excelField);

        $orderType = "order";
        $isGlobal = false;

        foreach ($excelField as $k => $v) {
            if ($defaultField[$v]['orderFl'] != 'y') {
                $orderType = "goods";
            }
            if (strpos($v, 'global_') !== false) {
                $isGlobal = true;
                $globalFiled[] = str_replace("global_","",$v);
            }

            //임의의 추가항목이 있을 경우.
            $defaultAddField = '{addFieldNm}_';
            if (strstr($v, $defaultAddField)) {
                $tmpAddFieldName = str_replace($defaultAddField, '', $v);
                $excelFieldName[$v]['name'] =  $tmpAddFieldName;
            }
        }

        //if(gd_isset($whereCondition['periodFl']) === null) $whereCondition['periodFl'] = "-1";
        gd_isset($whereCondition['periodFl'], 7);

        $userHandleMode = ['order_list_user_exchange', 'order_list_user_return', 'order_list_user_refund'];
        if (in_array($this->fileConfig['location'], $userHandleMode)) {
            $whereCondition['userHandleMode'] = $whereCondition['statusMode'];
            unset($whereCondition['statusMode']);
            $isUserHandle = true;
        } else {
            $isUserHandle = false;
        }

        // --- 검색 설정
        $orderData = $orderAdmin->getOrderListForAdminExcel($whereCondition, $whereCondition['periodFl'], $isUserHandle, $orderType,$excelField,0,$this->excelPageNum);

        //튜닝한 업체를 위해 데이터 맞춤
        if(empty($orderData['totalCount']) === true && empty($orderData['orderList']) === true) {
            $isGenerator = false;
            $totalNum = count($orderData);
            if ($this->excelPageNum) $orderList= array_chunk($orderData, $this->excelPageNum, true);
            else $orderList = array_chunk($orderData, $totalNum, true);
        } else {
            $isGenerator = true;
            $totalNum = count($orderData['totalCount']);
            $orderList = $orderData['orderList'];
            if(gd_is_provider() && $orderType =='goods') {
                $totalScmInfo = $orderData['totalScmInfo'];
            }
        }
        unset($goodsData);

        //값이 입력되지 않은 것으로 간주하고 전체 다운로드.
        if ($this->excelPageNum == 0) {
            $pageNum = 0;
        } else if ($this->excelPageNum >= $totalNum) {
            $pageNum = 0;
        } else {
            $pageNum = ceil($totalNum / $this->excelPageNum) - 1;
        }

        $setHedData[] = "<tr>";


        $arrTag = [
            'orderGoodsNm',
            'goodsNm',
            'orderGoodsNmStandard',
            'goodsNmStandard',
        ];

        for ($i = 0; $i <= $pageNum; $i++) {

            $fileName = $this->fileConfig['location'] . array_sum(explode(' ', microtime()));
            $tmpFilePath = UserFilePath::data('excel', $this->fileConfig['menu'],  $fileName . ".xls")->getRealPath();

            $fh = fopen($tmpFilePath, 'a+');
            fwrite($fh, $this->excelHeader."<table border='1'>");

            if($isGenerator) {
                if($i == '0') {
                    $data = $orderList;
                }  else {
                    $data = $orderAdmin->getOrderListForAdminExcel($whereCondition, $whereCondition['periodFl'], $isUserHandle, $orderType,$excelField,$i,$this->excelPageNum)['orderList'];
                }
            } else {
                $data = $orderList[$i];
            }

            foreach($data as $key => $val) {
                $progress = round((100 / ($totalNum- 1)) * ($key+($i*$this->excelPageNum)));
                if ($progress%20  == 0  || $progress == '100') {
                    echo "<script> parent.progressExcel('" .gd_isset($progress,0) . "'); </script>";
                }

                if($val['orderGoodsNmStandard']) {
                    list($val['orderGoodsNm'],$val['orderGoodsNmStandard']) =[$val['orderGoodsNmStandard'],$val['orderGoodsNm']];
                }

                if($val['goodsNmStandard']) {
                    list($val['goodsNm'],$val['goodsNmStandard']) =[$val['goodsNmStandard'],$val['goodsNm']];
                }

                if($whereCondition['statusMode'] === 'o'){
                    // 입금대기리스트에서 '주문상품명' 을 입금대기 상태의 주문상품명만 구성
                    $noPay = (int)$val['noPay'] - 1;
                    if(trim($val['goodsNm']) !== '') {
                        if ($noPay > 0) {
                            $val['orderGoodsNm'] = $val['goodsNm'] . ' 외 ' . $noPay . ' 건';
                        } else {
                            $val['orderGoodsNm'] = $val['goodsNm'];
                        }
                    }
                    if(trim($val['goodsNmStandard']) !== '') {
                        if($noPay > 0){
                            $val['orderGoodsNmStandard'] = $val['goodsNmStandard'] . ' ' . __('외') . ' ' . $noPay . ' ' . __('건');
                        }
                        else {
                            $val['orderGoodsNmStandard'] = $val['goodsNmStandard'];
                        }
                    }
                }

                if($isGlobal && $val['mallSno'] != $this->gGlobal['defaultMallSno']) {
                    $val['currencyPolicy'] = json_decode($val['currencyPolicy'], true);
                    $val['exchangeRatePolicy'] = json_decode($val['exchangeRatePolicy'], true);
                    $val['currencyIsoCode'] = $val['currencyPolicy']['isoCode'];
                    $val['exchangeRate'] = $val['exchangeRatePolicy']['exchangeRate' . $val['currencyPolicy']['isoCode']];

                    foreach($globalFiled as $globalKey =>$globalValue) {
                        $val["global_".$globalValue] = NumberUtils::globalOrderCurrencyDisplay($val[$globalValue], $val['exchangeRate'], $val['currencyPolicy']);
                    }
                }

                if($val['refundBankName']) {
                    $_tmpRefundAccount[] = $val['refundBankName'];
                    $_tmpRefundAccountNumber[] = $val['refundBankName'];
                }
                if ($val['refundAccountNumber'] && gd_str_length($val['refundAccountNumber']) > 50) {
                    $val['refundAccountNumber'] = \Encryptor::decrypt($val['refundAccountNumber']);
                }
                if ($val['userRefundAccountNumber'] && gd_str_length($val['userRefundAccountNumber']) > 50) {
                    $val['userRefundAccountNumber'] = \Encryptor::decrypt($val['userRefundAccountNumber']);
                }
                if($val['refundAccountNumber']) {
                    $_tmpRefundAccount[] = $val['refundAccountNumber'];
                }
                if($val['userRefundAccountNumber']) {
                    $_tmpRefundAccountNumber[] = $val['userRefundAccountNumber'];
                }

                if($val['refundDepositor']) {
                    $_tmpRefundAccount[] = $val['refundDepositor'];
                    $_tmpRefundAccountNumber[] = $val['refundDepositor'];
                }

                if(empty($_tmpRefundAccount) == false) {
                    $val['refundAccountNumber'] = implode(STR_DIVISION, $_tmpRefundAccount);
                    unset($_tmpRefundAccount);
                }
                if(empty($_tmpRefundAccountNumber) == false) {
                    $val['userRefundAccountNumber'] = implode(STR_DIVISION, $_tmpRefundAccountNumber);
                    unset($_tmpRefundAccountNumber);
                }

                if(gd_is_provider()) {
                    if($orderType =='goods') {
                        $val['scmOrderCnt'] = $totalScmInfo[$val['orderNo']]['scmOrderCnt'];
                        $val['scmGoodsCnt'] = $totalScmInfo[$val['orderNo']]['scmGoodsCnt'];
                        $val['scmGoodsNm'] = $totalScmInfo[$val['orderNo']]['scmGoodsNm'];
                        $val['totalGoodsPrice'] = $totalScmInfo[$val['orderNo']]['totalGoodsPrice'];
                        $tmpScmDeliveryCharge = explode(STR_DIVISION,$totalScmInfo[$val['orderNo']]['scmDeliveryCharge']);
                        $tmpScmDeliverySno =  explode(STR_DIVISION,$totalScmInfo[$val['orderNo']]['scmDeliverySno']);
                        $val['scmDeliveryCharge'] = array_sum(array_combine($tmpScmDeliverySno,$tmpScmDeliveryCharge));
                    } else {
                        $tmpScmDeliveryCharge = explode(STR_DIVISION,$val['scmDeliveryCharge']);
                        $tmpScmDeliverySno =  explode(STR_DIVISION,$val['scmDeliverySno']);
                        $val['scmDeliveryCharge'] = array_sum(array_combine($tmpScmDeliverySno,$tmpScmDeliveryCharge));
                    }
                    $val['scmGoodsNm'] = gd_htmlspecialchars(gd_htmlspecialchars_stripslashes(StringUtils::stripOnlyTags($val['scmGoodsNm'])));
                    unset($tmpScmDeliveryCharge);
                    unset($tmpScmDeliverySno);
                }

                $tmpData[] = "<tr>";
                foreach ($excelField as $excelKey => $excelValue) {

                    if (!$excelFieldName[$excelValue]) continue;

                    if (in_array($excelValue, $arrTag)) {
                        $val[$excelValue] = gd_htmlspecialchars(gd_htmlspecialchars_stripslashes(StringUtils::stripOnlyTags($val[$excelValue])));
                    }

                    if ($key == '0') {
                        $setHedData[] = "<td class='title'>" . $excelFieldName[$excelValue]['name'] . "</td>";
                    }

                    $tmpOptionInfo = [];
                    $tmpOptionCode = [];
                    if ($val['optionInfo']) {
                        $tmpOption = json_decode(gd_htmlspecialchars_stripslashes($val['optionInfo']), true);
                        foreach ($tmpOption as $optionKey => $optionValue) {
                            if ($optionValue[2]) $tmpOptionCode[] = $optionValue[2];
                            $tmpOptionInfo[] = $optionValue[0] . " : " . $optionValue[1];
                        }
                        unset($tmpOption);
                    }

                    switch ($excelValue) {
                        case 'orderGoodsCnt':
                            if(gd_is_provider()) {
                                $tmpData[]="<td>".$val['scmOrderCnt'] . "</td>";
                            } else {
                                $tmpData[]="<td>".$val[$excelValue] . "</td>";
                            }
                            break;
                        case 'orderGoodsNm':
                            if(gd_is_provider()) {
                                if($val['scmOrderCnt']==1) {
                                    $tmpData[]="<td>".$val['scmGoodsNm'] ."</td>";
                                } else {
                                    $tmpData[]="<td>".$val['scmGoodsNm'] ." 외 ". ($val['scmOrderCnt']-1) . " 건</td>";
                                }
                            } else {
                                $tmpData[]="<td>".$val[$excelValue] . "</td>";
                            }

                            break;
                        case 'totalSettlePrice':
                            if(gd_is_provider()) {
                                $tmpData[] = "<td>". ($val['totalOrderGoodsPrice']+$val['scmDeliveryCharge']) . "</td>";
                            } else {
                                if($val['orderChannelFl'] === 'naverpay'){
                                    $checkoutData = json_decode($val['checkoutData'], true);
                                    $tmpData[] = "<td>" . $checkoutData['orderData']['GeneralPaymentAmount'] . " </td>";
                                }
                                else {
                                    $tmpData[] = "<td>" . $val['totalRealSettlePrice'] . " </td>";
                                }
                            }
                            break;
                        case 'totalDeliveryCharge':
                            if(gd_is_provider()) {
                                $tmpData[] = "<td>" .  $val['scmDeliveryCharge'] . "</td>";
                            } else {
                                $tmpData[] = "<td>" . $val[$excelValue] . "</td>";
                            }
                            break;
                        case 'goodsDeliveryCollectFl':
                            if($val['goodsDeliveryCollectFl'] =='pre') {
                                $tmpData[] = "<td>선불</td>";
                            } else {
                                $tmpData[] = "<td>착불</td>";
                            }
                            break;
                        case 'mallSno':
                            $tmpData[] = "<td>" . $this->gGlobal['mallList'][$val['mallSno']]['mallName'] . "</td>";
                            break;
                        case 'hscode':

                            if ($val['hscode']) {

                                $hscode = json_decode(gd_htmlspecialchars_stripslashes($val['hscode']), true);

                                if ($hscode) {
                                    foreach ($hscode as $k1 => $v1) {
                                        $_tmpHsCode[] = $k1 . " : " . $v1;
                                    }

                                    $tmpData[] = "<td>" . implode("<br>", $_tmpHsCode) . "</td>";
                                    unset($_tmpHsCode);

                                } else {
                                    $tmpData[] = "<td></td>";
                                }

                                unset($hscode);

                            } else {
                                $tmpData[] = "<td></td>";
                            }

                            break;
                        case 'addField':
                            unset($excelAddField);
                            $addFieldData = json_decode($val[$excelValue], true);
                            $excelAddField[] = "<td><table border='1'><tr>";
                            foreach ($addFieldData as $addFieldKey => $addFieldVal) {
                                if ($addFieldVal['process'] == 'goods') {
                                    foreach ($addFieldVal['data'] as $addDataKey => $addDataVal) {
                                        $goodsVal = $addDataVal;
                                        if ($addFieldVal['type'] == 'text' && $addFieldVal['encryptor'] == 'y') {
                                            $goodsVal = Encryptor::decrypt($goodsVal);
                                        }
                                        $excelAddField[] = "<td>" . $addFieldVal['name'] . " (" . $addFieldVal['goodsNm'][$addDataKey] . ") : " . $goodsVal . "</td>";
                                    }
                                } else {
                                    $excelAddField[] = "<td>" . $addFieldVal['name'] . " : ";
                                    $goodsVal = $addFieldVal['data'];
                                    if ($addFieldVal['type'] == 'text' && $addFieldVal['encryptor'] == 'y') {
                                        $goodsVal = Encryptor::decrypt($goodsVal);
                                    }
                                    $excelAddField[] = $goodsVal;
                                    $excelAddField[] = "</td>";
                                }
                            }
                            if ($val['orderChannelFl'] == 'naverpay') {
                                $checkoutData = json_decode($val['checkoutData'], true);
                                if (empty($checkoutData['orderGoodsData']['IndividualCustomUniqueCode']) === false) {
                                    $excelAddField[] = "<td> 개인통관 고유번호(네이버) : " . $checkoutData['orderGoodsData']['IndividualCustomUniqueCode'] . "</td>";
                                }
                            }
                            $excelAddField[] = "</tr></table></td>";
                            $tmpData[] = implode('', $excelAddField);
                            break;
                        case 'goodsType':
                            if ($val['goodsType'] == 'addGoods') {
                                $tmpData[] = "<td>추가</td>";
                            } else {
                                $tmpData[] = "<td>일반</td>";
                            }

                            break;
                        case 'goodsNm':
                            if ($val['goodsType'] == 'addGoods') {
                                $tmpData[] = "<td><span style='color:red'>[" . __('추가') . "]</span>" . $val[$excelValue] . "</td>";
                            } else {
                                $tmpData[] = "<td>" . $val[$excelValue] . "</td>";
                            }

                            break;
                        case 'optionInfo':
                            if ($tmpOptionInfo) {
                                $tmpData[] = "<td>" . implode("<br/>", $tmpOptionInfo) . "</td>";
                            } else {
                                $tmpData[] = "<td></td>";
                            }
                            break;
                        case 'optionCode':
                            if ($tmpOptionCode) {
                                $tmpData[] = "<td>" . implode("<br/>", $tmpOptionCode) . "</td>";
                            } else {
                                $tmpData[] = "<td></td>";
                            }

                            break;
                        case 'optionTextInfo':
                            $tmpOptionTextInfo = [];
                            if ($val[$excelValue]) {
                                $tmpOption = json_decode($val[$excelValue], true);
                                foreach ($tmpOption as $optionKey => $optionValue) {
                                    //$tmpOptionTextInfo[] = $optionValue[0] . " : " . $optionValue[1] . " / " . __('옵션가') . " : " . $optionValue[2];
                                    $tmpOptionTextInfo[] = gd_htmlspecialchars_stripslashes($optionValue[0]) . " : " . gd_htmlspecialchars_stripslashes($optionValue[1]);
                                }
                            }
                            if ($tmpOptionTextInfo) {
                                $tmpData[] = "<td>" . implode("<br/>", $tmpOptionTextInfo) . "</td>";
                            } else {
                                $tmpData[] = "<td></td>";
                            }

                            unset($tmpOptionTextInfo);
                            unset($tmpOption);
                            break;
                        case 'orderStatus':
                            $tmpData[] = "<td>" . $orderAdmin->getOrderStatusAdmin($val[$excelValue]) . "</td>";
                            break;
                        case 'settleKind':
                            $tmpData[] = "<td>" . $orderAdmin->printSettleKind($val[$excelValue]) . "</td>";
                            break;
                        // 숫자 처리 - 주문 번호 및 우편번호(5자리) 숫자에 대한 처리
                        case 'orderNo':
                        case 'apiOrderNo':
                        case 'apiOrderGoodsNo':
                        case 'receiverZonecode':
                        case 'invoiceNo':
                        case 'pgTid':
                        case 'pgAppNo':
                        case 'pgResultCode':
                        case 'orderPhone':
                        case 'orderCellPhone':
                        case 'receiverPhone':
                        case 'receiverCellPhone':
                            $tmpData[] = "<td class=\"xl24\">" . $val[$excelValue] . "</td>";
                            break;

                        case 'totalGift':
                        case 'ogi.presentSno':
                        case 'ogi.giftNo':
                            $gift = $orderAdmin->getOrderGift($val['orderNo'], $val['scmNo'], 40);
                            $presentTitle = [];
                            $giftInfo = [];
                            $totalGift = [];
                            if ($gift) {
                                foreach ($gift as $gk => $gv) {
                                    $presentTitle[] = $gv['presentTitle'];
                                    $giftInfo[] = $gv['giftNm'] . INT_DIVISION . $gv['giveCnt'] . "개";
                                    $totalGift[] = $gv['presentTitle'] . INT_DIVISION . $gv['giftNm'] . INT_DIVISION . $gv['giveCnt'] . "개";
                                }

                                if ($excelValue == 'ogi.presentSno') {
                                    $tmpData[] = "<td>" . implode("<br>", $presentTitle) . "</td>";
                                } else if ($excelValue == 'ogi.giftNo') {
                                    $tmpData[] = "<td>" . implode("<br>", $giftInfo) . "</td>";
                                } else if ($excelValue == 'totalGift') {
                                    $tmpData[] = "<td>" . implode("<br>", $totalGift) . "</td>";
                                }
                            } else {
                                $tmpData[] = "<td></td>";
                            }

                            break;

                        case 'memNo':
                            $tmpData[] = "<td class=\"xl24\">" . $val['memId'] . "</td>";
                            break;
                        case 'receiverAddressTotal':
                            if($val['mallSno'] != $this->gGlobal['defaultMallSno']) {
                                $countriesCode = $orderAdmin->getCountriesList();
                                $countriesCode = array_combine (array_column($countriesCode, 'code'), array_column($countriesCode, 'countryNameKor'));
                                $tmpData[] = "<td>" .$countriesCode[$val['receiverCountryCode']]." ". $val['receiverCity'] . " ". $val['receiverState'] . " ". $val['receiverAddress'] . " " . $val['receiverAddressSub'] . " (" . $val['orderName'] . ")</td>";
                            } else {
                                $tmpData[] = "<td>" . $val['receiverAddress'] . " " . $val['receiverAddressSub'] . " (" . $val['orderName'] . ")</td>";
                            }
                            break;
                        case 'receiverAddress':
                            $tmpData[] = "<td class='xl24'>" . $val['receiverAddress'] . "</td>";
                            break;
                        case 'receiverAddressSub':
                            $tmpData[] = "<td class='xl24'>" . $val['receiverAddressSub'] . " (". $val['orderName'] . ")</td>";
                            break;
                        case 'addGoodsNo':

                            $addGoods = $orderAdmin->getOrderAddGoods(
                                $val['orderNo'],
                                $val['orderCd'],
                                [
                                    'sno',
                                    'addGoodsNo',
                                    'goodsNm',
                                    'goodsCnt',
                                    'goodsPrice',
                                    'optionNm',
                                    'goodsImage',
                                    'addMemberDcPrice',
                                    'addMemberOverlapDcPrice',
                                    'addCouponGoodsDcPrice',
                                    'addGoodsMileage',
                                    'addMemberMileage',
                                    'addCouponGoodsMileage',
                                    'divisionAddUseDeposit',
                                    'divisionAddUseMileage',
                                    'divisionAddCouponOrderDcPrice',
                                ]
                            );

                            $addGoodsInfo = [];
                            if ($addGoods) {
                                foreach ($addGoods as $av => $ag) {
                                    $addGoodsInfo[] = $ag['goodsNm'];
                                }
                                $tmpData[] = "<td>" . implode("<br>", $addGoodsInfo) . "</td>";

                            } else {
                                $tmpData[] = "<td></td>";
                            }

                            break;
                        case 'orderMemo' :
                            if($val['orderChannelFl'] == 'naverpay'){
                                $checkoutData= json_decode($val['checkoutData'], true);
                                $tmpData[] = "<td>" . $checkoutData['orderGoodsData']['ShippingMemo'] . "</td>";
                            }
                            else {
                                $tmpData[] = "<td>" . $val['orderMemo'] . "</td>";
                            }
                            break;

                        case 'packetCodeFl' :
                            $tmpData[] = (trim($val['packetCode'])) ? "<td>y</td>" : "<td>n</td>";
                            break;

                            //복수배송지 배송지
                        case 'multiShippingOrder' :
                            $multiShippingName = ((int)$val['orderInfoCd'] === 1) ? '메인 배송지' : '추가' . ((int)$val['orderInfoCd'] - 1) . ' 배송지';
                            $tmpData[] = "<td>" . $multiShippingName . "</td>";
                            break;

                            //복수배송지 배송지별배송비
                        case 'multiShippingPrice' :
                            $tmpData[] = "<td>" . $val['deliveryCharge'] . "</td>";
                            break;

                            // 안심번호 (사용하지 않을경우 휴대폰번호 노출)
                        case 'receiverSafeNumber':
                            if ($val['receiverUseSafeNumberFl'] == 'y' && empty($val['receiverSafeNumber']) == false && empty($val['receiverSafeNumberDt']) == false && DateTimeUtils::intervalDay($val['receiverSafeNumberDt'], date('Y-m-d H:i:s')) <= 30) {
                                $tmpData[] = "<td class='xl24'>" . $val['receiverSafeNumber'] . "</td>";
                            } else {
                                $tmpData[] = "<td class='xl24'>" . $val['receiverCellPhone'] . "</td>";
                            }

                            break;
                        case 'userHandleInfo':
                            $userHandleInfo = '';
                            if ($whereCondition['userHandleViewFl'] != 'y') {
                                $userHandleInfo = $orderAdmin->getUserHandleInfo($val['orderNo'], $val['orderGoodsSno'])[0];
                            }
                            $tmpData[] = "<td>" . $userHandleInfo . "</td>";

                            break;
                        case 'goodsCnt' :
                            $tmpOrderCnt = $val[$excelValue];
                            if ($whereCondition['optionCountFl'] === 'per') { $tmpOrderCnt = 1; }
                            $tmpData[] = "<td>" . $tmpOrderCnt . "</td>";
                            break;
                        case 'orderChannelFl':
                            $channel = $val[$excelValue];
                            if ($val['trackingKey']) $channel .= '<br />페이코쇼핑';
                            $tmpData[] = "<td>" . $channel . "</td>";
                            break;
                        default  :
                            if ($excelFieldName[$excelValue]['type'] == 'price' && $val[$excelValue] != '') {
                                $tmpData[] = "<td>" . $val[$excelValue] . "</td>";
                            } else if ($excelFieldName[$excelValue]['type'] == 'mileage' && $val[$excelValue] != '') {
                                $tmpData[] = "<td>" . number_format($val[$excelValue]) . $this->mileageGiveInfo['basic']['unit'] . "</td>";
                            } else if ($excelFieldName[$excelValue]['type'] == 'deposit' && $val[$excelValue] != '') {
                                $tmpData[] = "<td>" . number_format($val[$excelValue]) . $this->depositInfo['unit'] . "</td>";
                            } else {
                                $tmpData[] = "<td>" . $val[$excelValue] . "</td>";
                            }
                            break;
                    }

                    unset($tmpOptionInfo);
                    unset($tmpOptionCode);

                }
                $tmpData[] = "</tr>";

                if ($key == '0') {
                    fwrite($fh, implode(chr(10), $setHedData));
                    unset($setHedData);
                }

                if ($whereCondition['optionCountFl'] === 'per') {
                    $tmpGoodsCnt = ($val['goodsCnt'] == '0' || empty($val['goodsCnt']) === true) ? 1 : $val['goodsCnt'];
                    for ($tmpDataCnt = 1; $tmpDataCnt <= $tmpGoodsCnt; $tmpDataCnt++) {
                        fwrite($fh, implode(chr(10), $tmpData));
                    }
                } else {
                    fwrite($fh, implode(chr(10), $tmpData));
                }
                unset($tmpData);
            }
//exit;
            fwrite($fh, "</table>");
            fwrite($fh,  $this->excelFooter);
            fclose($fh);

            $this->fileConfig['fileName'][] = $fileName . ".xls";
        }




        return true;
    }
}