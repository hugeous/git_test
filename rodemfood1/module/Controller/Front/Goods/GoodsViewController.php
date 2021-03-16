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
namespace Controller\Front\Goods;

use Session;
use Cookie;
use Component\Storage\Storage;
use Request;

class GoodsViewController extends \Bundle\Controller\Front\Goods\GoodsViewController
{
  public function index() {

	// 신규회원 체크
	if (!is_object($this->db)) {
		$this->db = \App::load('DB');
	}	


	// 기본 정보
	$strSQL = "SELECT cateNm  FROM es_goods a JOIN es_categoryGoods b ON a.cateCd = b.cateCd WHERE goodsNo=".Request::get()->get('goodsNo');	
	$relation2 = $this->db->query_fetch($strSQL, $arrBind, false);
	
	$this->setData('cateNm', $relation2['cateNm']);

	// 100원딜 상품 구매여부, 가입후 3일이내
	$buyMsg = "";
	if(Session::get('member.memNo') > 0){		
		$strSQL = "SELECT groupSno, regDt FROM es_member WHERE memNo='".Session::get('member.memNo')."' LIMIT 1";						        
		$mem = $this->db->query_fetch($strSQL, $arrBind, false);

		// 100원딜 상품 여부
		$goodsNo = Request::get()->get('goodsNo');

		$strSQL = "SELECT COUNT(1) as cnt FROM es_goodsLinkCategory WHERE cateLinkFl='y' AND goodsNo='".$goodsNo."' AND cateCd ='008' LIMIT 1";						        
		$cateCnt = $this->db->query_fetch($strSQL, $arrBind, false);

		// 100딜 상품 구매여부
		$strSQL = "SELECT orderNo 
						FROM es_order a
							JOIN es_orderGoods b ON a.orderNo=b.orderNo
							JOIN es_goodsLinkCategory c ON b.goodsNo=c.goodsNo
						WHERE memNo='".Session::get('member.memNo')."' AND c.cateCd = '008' AND orderStatus IN ('o1', 'p1', 'g1', 'g2', 'g3', 'g4', 'd1', 'd2', 's1', 'e1', 'e2', 'e3', 'e4', 'e5') LIMIT 1";						        
		$deal = $this->db->query_fetch($strSQL, $arrBind, false);

		$timestamp = strtotime($mem['regDt']."+4 days");
		$timechk = strtotime(date("Y-m-d", $timestamp)." 00:00:00");
		$timenow = strtotime(date("Y-m-d H:i:s"));
		
		if($cateCnt['cnt'] > 0 && $timenow < $timechk && empty($deal['orderNo'])) {
			$dateMsg = strtotime($mem['regDt']."+3 days");			
			$buyMsg = "구매조건 : ".date("m",$dateMsg)."월 ".date("d",$dateMsg)."일 23시 59분까지 구매 가능 / 9,900원 이상 결제 시 구매 가능";						
		}

	}
	$this->setData('buyMsg', $buyMsg);

    parent::index();

	/*

    $todayCookieName = 'todayGoodsNo';
    $arrTodayGoodsNo = $_COOKIE[$todayCookieName];
    $goods = \App::load('\\Component\\Goods\\Goods');
    $arrTodayGoodsNo = json_decode($arrTodayGoodsNo); // to array

    $arrTodayGoods = array();
    if (Cookie::has($todayCookieName)) {
      foreach ($arrTodayGoodsNo as $value) {
        try {
          $goodsView = $goods->getGoodsView($value);
          // $arrTodayGoods[$value] = $goodsView['image']['magnify']['thumb'][0];
          $arrTodayGoods[$value] = $goodsView['image']['detail']['thumb'][0];
        } catch (Exception $e) {
          continue;
        }
      }
      Cookie::set('todayGoods', json_encode($arrTodayGoods), time() + 42000);
    }
	*/
  }
}
