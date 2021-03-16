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
namespace Controller\Mobile;

use Component\Member\Util\MemberUtil;
use Session;
use Cookie;
use Component\Storage\Storage;

/**
 * 모바일 접속 페이지
 *
 * @author Jong-tae Ahn <qnibus@godo.co.kr>
 */
class IndexController extends \Bundle\Controller\Mobile\IndexController
{
	/**
     * {@inheritdoc}
     */
    public function index()
    {
		// 리뷰 팝업 노출 여부
		$reviewStat = "0";
		if(Session::get('member.memNo') > 0){		
			if (!is_object($this->db)) {
				$this->db = \App::load('DB');
			}
			
			$strSQL = "SELECT c.orderNo, a.orderNo as 'orderNo1'
							FROM es_order a
								JOIN es_orderGoods b ON a.orderNo=b.orderNo			
								LEFT JOIN es_bd_goodsreview c ON a.orderNo=c.orderNo AND c.memNo ='".Session::get('member.memNo')."'
							WHERE a.memNo='".Session::get('member.memNo')."' AND c.orderNo IS NULL AND a.orderNo != '' LIMIT 1";		
			$reviewStat = $this->db->query_fetch($strSQL, $arrBind, false);

			if($reviewStat['orderNo'] == "" && $reviewStat['orderNo1'] != ""){
				$reviewStat = "1";
			}			
		}
		$this->setData('reviewStat', $reviewStat);

        // main/index 파일을 호출
        // naver 정책에 의해 index 파일 무조건 해당 위치로
        $this->getView()->setPageName(gd_entryway('mobile'));
    }
}