<?php

/**
 * This is commercial software, only users who have purchased a valid license
 * and accept to the terms of the License Agreement can install and use this
 * program.
 *
 * Do not edit or add to this file if you wish to upgrade Smart to newer
 * versions in the future.
 *
 * @copyright ⓒ 2016, NHN godo: Corp.
 * @link      http://www.godo.co.kr
 */
namespace Component\Naver;

use Component\Delivery\Delivery;

use Component\Policy\Policy;
use Framework\Utility\ArrayUtils;
use Framework\Utility\DateTimeUtils;

class NaverPay extends \Bundle\Component\Naver\NaverPay
{
    public function getNaverPayView($goodsData, $isMobile = false)
    {
        if ($this->checkUse() === false) {
            return;
        }

        if ($this->checkTest() && gd_is_admin() === false) {
            return;
        }

        if (\Globals::get('gGlobal.isUse')) {
            $mallInfo = \Session::get(SESSION_GLOBAL_MALL);
            if ($mallInfo) {
                return;
            }
        }

        $rtn = $this->config;
        $rtn['status'] = $this->checkGoods($goodsData);
        $this->setBtn2('view', $rtn, $isMobile);

        return gd_isset($rtn['javascript']) . chr(10) . gd_isset($rtn['btnScript']);
    }

    public function getNaverPayCart($item, $isMobile = false)
    {
        if ($this->checkUse() === false) {
            return;
        }

        if ($this->checkTest() && gd_is_admin() === false) {
            return;
        }

        $rtn = $this->config;
        if (ArrayUtils::isEmpty($item) === false) {
            $result = 'y';
            $allowGoods = true;
            foreach ($item as $scm) {
                if (!$allowGoods) {
                    break;
                }
                foreach ($scm as $goods) {
                    if (!$allowGoods) {
                        break;
                    }

                    foreach ($goods as $data) {
                        $status = $this->checkGoods($data['goodsNo'],$goodsData);
                        $cultureBenefitFl = $cultureBenefitFl ?? $goodsData['cultureBenefitFl'];
                        if($goodsData['cultureBenefitFl'] == 'y') {
                            $cultureBenifitGoodsNm = $goodsData['goodsNm'];
                        }
                        if($cultureBenefitFl != $goodsData['cultureBenefitFl']) {
                                $result = 'n';
                                $rtn['exceptionGoodsNm'] = $cultureBenifitGoodsNm;
                                $status['msg'] = __(sprintf("상품은 도서공연비 소득공제 상품입니다. 일반상품과 함께 네이버페이로 구매하실 수 없습니다."));
                            break;
                        }

                        if ($status['result'] != 'y') {
                            $result = 'n';
                            $rtn['exceptionGoodsNm'] = strip_tags($data['goodsNm']);
                            $allowGoods = false;
                            break;
                        }
                    }
                }
            }

            $rtn['status'] = [
                'result' => $result,
                'msg' => $status['msg'],
            ];
            $this->setBtn2('cart', $rtn, $isMobile);
        } else {
            return '';
        }

        return gd_isset($rtn['javascript']) . chr(10) . gd_isset($rtn['btnScript']);
    }

    // goods_view.html 에서 네이버페이 구매 버튼 누를 시 하단 옵션관련 부분을 제거하여
    // 한번만 주문이 되도록 설정 (기존 setBtn을 수정한 버전)
    private function setBtn2($mode, &$rtn, $isMobile)
    {
        $naverPayUrl = "../goods/naver_pay.php";
        $naverPayWishUrl = "../goods/naver_pay_wish.php";
        $goodsNo = \Request::get()->get('goodsNo');
        $failMsg = $rtn['status']['msg'];
        switch ($mode) {
            case 'view': {
                $btnCnt = 2;
                if ($rtn['status']['result'] == 'y') {
                    $javascript = 'function naverPay() {' . chr(10);
                    if(gd_is_skin_division()){
                        $funcName = "gd_goods_order";
                    }
                    else {
                        $funcName = "goods_order";
                    }

                    $javascript .= 'hide2ndOptionDisplayArea();' . chr(10); // 하단 옵션 관련 부분 지우는 함수 추가
                    $javascript .= 'if(!'.$funcName.'(\'pa\')){ return false; };' . chr(10);
                    $javascript .= 'var frm = $("[name=frmView]");' . chr(10);
                    $javascript .= 'frm.attr("action", "' . $naverPayUrl . '");' . chr(10);
                    $javascript .= 'frm.attr("target","ifrmProcess")' . chr(10);;
                    $javascript .= 'frm.submit();' . chr(10);
                    $javascript .= 'frm.attr("action", "");' . chr(10);
                    $javascript .= '}' . chr(10);

                    $javascript .= 'var naverCheckoutWin = "";' . chr(10);

                    $javascript .= 'function wishNaverPay() {' . chr(10);
                    $javascript .= 'var frm = $("[name=frmView]");' . chr(10);
                    $javascript .= 'var htmlGoodsNo = "<input type=\"hidden\" name=\"wishGoodsNo\" value=\"' . $goodsNo . '\">"; ' . chr(10);
                    $javascript .= 'frm.append(htmlGoodsNo)' . chr(10);

                    $javascript .= 'frm.attr("action", "' . $naverPayWishUrl . '");' . chr(10);
                    $javascript .= 'frm.attr("target","ifrmProcess")' . chr(10);;
                    $javascript .= 'frm.submit();' . chr(10);
                    $javascript .= 'frm.attr("action", "");' . chr(10);
                    $javascript .= '}' . chr(10);
                } else {
                    $javascript = 'function naverPay() {' . chr(10);
                    $javascript .= 'alert("' . $failMsg . '");' . chr(10);
                    $javascript .= '}' . chr(10);

                    $javascript .= 'function wishNaverPay() {' . chr(10);
                    $javascript .= 'alert("' . $failMsg . '");' . chr(10);
                    $javascript .= '}' . chr(10);
                }
                break;
            }
            case 'cart': {
                $btnCnt = 1;
                if ($rtn['status']['result'] == 'n') {
                    $javascript = 'function naverPay() {' . chr(10);
                    $javascript .= 'alert("[' . gd_htmlspecialchars_slashes(strip_tags($rtn['exceptionGoodsNm']), 'add') . '] ' . $failMsg . '");' . chr(10);
                    $javascript .= '}' . chr(10);
                    $javascript .= 'function wishNaverPay() {' . chr(10);
                    $javascript .= 'alert("[' . gd_htmlspecialchars_slashes(strip_tags($rtn['exceptionGoodsNm']), 'add') . '] ' . $failMsg . '");' . chr(10);
                    $javascript .= '}' . chr(10);
                } else {
                    $funcName = 'cart_cnt_info';
                    if (gd_is_skin_division()) $funcName = 'gd_cart_cnt_info';

                    $javascript = 'function naverPay() {' . chr(10);

                    $javascript .= "var checkedCnt = $('#frmCart  input:checkbox[name=\"cartSno[]\"]:checked').length;" . chr(10);
                    $javascript .= "if (checkedCnt == 0) {" . chr(10);
                    $javascript .= " alert('" . __('선택하신 상품이 없습니다.') . "');" . chr(10);
                    $javascript .= "return false;" . chr(10);
                    $javascript .= "}" . chr(10);
                    //장바구니 상품수량 체크
                    $javascript .= "var cartAlertMsg = '';" . chr(10);
                    $javascript .= "if (typeof " . $funcName . " !== 'undefined') {" . chr(10);
                    $javascript .= "cartAlertMsg = " . $funcName . "();" . chr(10);
                    $javascript .= "if (cartAlertMsg) {" . chr(10);
                    $javascript .= "alert(cartAlertMsg);" . chr(10);
                    $javascript .= "return false;" . chr(10);
                    $javascript .= "}" . chr(10);
                    $javascript .= "}" . chr(10);
                    //장바구니 상품수량 체크
                    $javascript .= 'var frm = $("#frmCart");' . chr(10);
                    $javascript .= 'var tmpAction = frm.attr("action");' . chr(10);
                    $javascript .= 'var tmpMode = frm.find("[name=mode]:hidden").val();' . chr(10);
                    $javascript .= 'frm.attr("action", "' . $naverPayUrl . '");' . chr(10);
                    $javascript .= 'frm.find("[name=mode]:hidden").val("cart");' . chr(10);
                    $javascript .= 'frm.submit();' . chr(10);
                    $javascript .= 'frm.attr("action", tmpAction);' . chr(10);
                    $javascript .= 'frm.find("[name=mode]:hidden").val(tmpMode);' . chr(10);
                    $javascript .= '}' . chr(10);
                }
                break;
            }
        }

        if ($this->checkTest()) {
            $btnScriptDomain = \Request::getScheme() . '://test-pay.naver.com';
        } else {
            $btnScriptDomain = \Request::getScheme() . '://pay.naver.com';
        }

        if ($isMobile) {
            $buttonColor = $rtn['mobileImgColor'];
            $buttonType = $rtn['mobileImgType'];
            $rtn['btnScript'] = '<script type="text/javascript" src="' . $btnScriptDomain . '/customer/js/mobile/naverPayButton.js" charset="UTF-8"></script>' . chr(10);
        } else {
            $buttonColor = $rtn['imgColor'];
            $buttonType = $rtn['imgType'];
            $rtn['btnScript'] = '<script type="text/javascript" src="' . $btnScriptDomain . '/customer/js/naverPayButton.js" charset="UTF-8"></script>' . chr(10);
        }
        $rtn['btnScript'] .= '<script type="text/javascript" >//<![CDATA[' . chr(10);
        $rtn['btnScript'] .= $javascript . chr(10);
        $rtn['btnScript'] .= '</script>' . chr(10);
        $rtn['btnScript'] .= '<script type="text/javascript" >//<![CDATA[' . chr(10);
        $rtn['btnScript'] .= 'naver.NaverPayButton.apply({' . chr(10);
        $rtn['btnScript'] .= 'BUTTON_KEY: "' . $rtn['imageId'] . '", // 체크아웃에서 제공받은 버튼 인증 키 입력' . chr(10);
        $rtn['btnScript'] .= 'TYPE: "' . $buttonType . '", // 버튼 모음 종류 설정' . chr(10);
        $rtn['btnScript'] .= 'COLOR: ' . $buttonColor . ', // 버튼 모음의 색 설정' . chr(10);
        $rtn['btnScript'] .= 'COUNT: ' . $btnCnt . ', // 버튼 개수 설정. 구매하기 버튼만 있으면(장바구니 페이지) 1, 관심상품 버튼도 있으면(상품 상세 페이지) 2를 입력.' . chr(10);
        $rtn['btnScript'] .= 'BUY_BUTTON_HANDLER: naverPay, ' . chr(10);
        if ($mode == 'view' && $btnCnt == 2) {
            $rtn['btnScript'] .= 'WISHLIST_BUTTON_HANDLER: wishNaverPay, ' . chr(10);
        }
        $rtn['btnScript'] .= 'ENABLE: "' . strtoupper($rtn['status']['result']) . '", // 품절 등의 이유로 버튼 모음을 비활성화할 때에는 "N" 입력' . chr(10);

        $rtn['btnScript'] .= '"":""' . chr(10);
        $rtn['btnScript'] .= '});' . chr(10);
        $rtn['btnScript'] .= '//]]></script>';
    }

}
