<?php

/**
 * This is commercial software, only users who have purchased a valid license
 * and accept to the terms of the License Agreement can install and use this
 * program.
 *
 * Do not edit or add to this file if you wish to upgrade Enamoo S5 to newer
 * versions in the future.
 *
 * @copyright ⓒ 2016, NHN godo: Corp.
 * @link http://www.godo.co.kr
 */
namespace Controller\Front\Goods;

use Component\Cart\Cart;
use Component\Database\DBTableField;
use Component\Delivery\Delivery;
use Component\Goods\AddGoods;
use Component\Goods\Goods;
use Component\Naver\NaverPay;
use Component\Order\Order;
use Component\Order\OrderAdmin;
use Component\Policy\Policy;
use Framework\Debug\Exception\AlertOnlyException;
use Request;
use Framework\Utility\SkinUtils;
use Session;
use Framework\Debug\Exception\AlertRedirectException;
use Component\Storage\Storage;

class NaverPayController extends \Bundle\Controller\Front\Goods\NaverPayController
{
	const NOT_STRING = Array('%01', '%02', '%03', '%04', '%05', '%06', '%07', '%08', '%09', '%0A', '%0B', '%0C', '%0D', '%0E', '%0F', '%10', '%11', '%12', '%13', '%14', '%15', '%16', '%17', '%18', '%19', '%1A', '%1B', '%1C', '%1D', '%1E', '%1F');
    private $db;
    private $orderData;
    private $delivery;

	public function index()
    {
		// 신규회원 체크
		if (!is_object($this->db)) {
			$this->db = \App::load('DB');
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
			if ($postValue['mode'] == 'cart') { //장바구니에서 접근
				$arrCartSno = $postValue['cartSno'];
				$strCartSno = implode( ',', $arrCartSno );

				// 장바구니에 담긴 100원딜 상품 갯수
				$strSQL = "SELECT SUM(a.goodsCnt) as cnt FROM es_cart a JOIN es_goodsLinkCategory b ON a.goodsNo=b.goodsNo WHERE cateLinkFl='y' AND a.goodsNo IN (".$strCartSno.") AND cateCd ='008' LIMIT 1";
				$cartCnt = $this->db->query_fetch($strSQL, $arrBind, false);

				if($cartCnt['cnt'] > 0){
					throw new AlertRedirectException(__('신규회원 이벤트 상품은 회원만 구매 가능합니다.'), null, null, '../order/cart.php');
				}	
			} 
		}

		

		parent::index();
	}
}