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
namespace Controller\Front\Order;

use Component\Cart\Cart;
use Exception;
use Framework\Debug\Exception\AlertBackException;
use Framework\Debug\Exception\AlertOnlyException;
use Framework\Debug\Exception\AlertRedirectException;
use Framework\Debug\Exception\AlertReloadException;
use Framework\Debug\Exception\Except;
use Request;
use Respect\Validation\Validator as v;
use Bundle\Component\Database\DBTableField;
use Session;

use Cookie; // test code

/**
 * 장바구니 처리 페이지
 * @author Shin Donggyu <artherot@godo.co.kr>
 */
class CartPsController extends \Bundle\Controller\Front\Order\CartPsController
{
  public function index() {
    // parent::index();
    // return;

    // $this->json([
    //     'error' => 1,
    //     'message' => __('성공'),
    // ]);


    // 장바구니 class
    $cart = \App::Load(\Component\Cart\Cart::class);
    $session = \App::getInstance('session');

    $request = \App::getInstance('request');
    // _POST , _GET 정보
    $postValue = Request::request()->toArray();
    $getValue = Request::get()->toArray();

    // 각 모드별 처리
    switch (Request::request()->get('mode')) {
        // 장바구니 추가
        case 'cartIn':
            try {
                //관련상품 관련 세션 삭제
                if($session->get('related_goods_order') == 'y') {
                    $session->del('related_goods_order');
                }
                $cart->setDeleteDirectCartCont();
                // 메인 상품 진열 통계 처리
                if (empty($postValue['mainSno']) === false && $postValue['mainSno'] > 0) {
                    $goods = \App::load('\\Component\\Goods\\Goods');
                    $getData = $goods->getDisplayThemeInfo($postValue['mainSno']);
                    $postValue['linkMainTheme'] = htmlentities($getData['sno'] . STR_DIVISION . $getData['themeNm'] . STR_DIVISION . $getData['mobileFl']);
                } else {
                    $referer = $request->getReferer();
                    unset($mtn);
                    parse_str($referer);
                    gd_isset($mtn);
                    if (empty($mtn) === false) {
                        $postValue['linkMainTheme'] = $mtn;
                    }
                }
                // 장바구니 추가
                $cart->saveInfoCart($postValue);
                if ($request->isAjax()) {
                    // test code [[
                    // $goods = \App::load('\\Component\\Goods\\Goods');
                    // $goodsNo = $postValue['goodsNo'];
                    // $goodsView = $goods->getGoodsView($goodsNo[0]);
                    // $goodsOptionIcon = $goods->getGoodsImage($goodsNo[0]);
                    // $goodsImage = $goods->getGoodsImage($goodsNo[0], 'main');
                    // $lll = $goodsView['image']['detail']['thumb'][0];
                    // test code ]]


                    $this->json([
                        'error' => 0,
                        'message' => __('성공'),
                        'cartCnt' => $cart->getCartGoodsCnt(),
                        // test code [[
                        // 'goodsNo' => $goodsNo,
                        // 'goodsView' => $goodsView,
                        // 'goodsOptionIcon' => $goodsOptionIcon,
                        // 'goodsImage' => $goodsImage,
                        // 'lll' => $lll,
                        // test code ]]
                    ]);
                } else {
                    // 처리별 이동 경로
                    if (gd_isset($postValue['cartMode']) == 'd') {
                        $returnUrl = './order.php';
                    } else {
                        $returnUrl = './cart.php';
                    }
                    $this->redirect($returnUrl, null, 'parent');
                }

            } catch (Exception $e) {
                if (Request::isAjax()) {
                    $this->json([
                        'error' => 1,
                        'message' => $e->getMessage(),
                    ]);
                } else {
                    throw new AlertBackException($e->getMessage());
                }
            }
            exit();
            break;

		case 'reOrder' :
			 try {
                //관련상품 관련 세션 삭제
                if($session->get('related_goods_order') == 'y') {
                    $session->del('related_goods_order');
                }
                $cart->setDeleteDirectCartCont();

                // 장바구니 추가
                $cart->reSaveOrder($getValue['orderNo'], Session::get('siteKey'));
				Cookie::set('isDirectCart', true, 0, '/');
                $this->json([					
					'error' => 0,
					'message' => __('성공'),
				]);

            } catch (Exception $e) {
                if (Request::isAjax()) {
                    $this->json([
                        'error' => 1,
                        'message' => $e->getMessage(),
                    ]);
                } else {
                    throw new AlertBackException($e->getMessage());
                }
            }
			exit();
			break;
    }

    parent::index();
  }
}
