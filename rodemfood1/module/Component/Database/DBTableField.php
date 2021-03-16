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
namespace Component\Database;

use Session;

/**
 * DB Table 기본 Field 클래스 - DB 테이블의 기본 필드를 설정한 클래스 이며, prepare query 생성시 필요한 기본 필드 정보임
 * @package Component\Database
 * @static  tableConfig
 */
class DBTableField extends \Bundle\Component\Database\DBTableField
{
	public static function tableOrderInfo($conf = null)
    {
		// 부모 method 상속
		$arrField = parent::tableOrderInfo();

		$arrField[] = ['val' => 'presentSenderNm', 'typ' => 's', 'def' => null, 'name' => '선물보내는사람이름'];

        return $arrField;
    }

	public static function tableDesignPopup()
    {
		// 부모 method 상속
		$arrField = parent::tableDesignPopup();

		$arrField[] = ['val' => 'contentsType', 'typ' => 's', 'def' => null, 'name' => '팝업 컨텐츠 타입(0:일반,1:움직이는배너,2:일반배너)'];
		$arrField[] = ['val' => 'bannerCode', 'typ' => 's', 'def' => null, 'name' => '배너일경우 코드'];
		$arrField[] = ['val' => 'mobileBannerCode', 'typ' => 's', 'def' => null, 'name' => '배너일경우 코드'];

        return $arrField;
    }

	/**
     * 플러스 리뷰 게시글
     *
     * @author sj
     * @return array board 테이블 필드 정보
     */
    public static function tablePlusReviewArticle()
    {
		// 부모 method 상속
		$arrField = parent::tablePlusReviewArticle();

		$arrField[] = ['val' => 'mainViewStat', 'typ' => 's', 'def' => '0', 'name' => '메인 노출 여부(0:비노출,1:노출)'];

		return $arrField;
	}

	public static function tableTimeSale()
    {
		// 부모 method 상속
		$arrField = parent::tableTimeSale();

		$arrField[] = ['val' => 'mainDisplayFl', 'typ' => 's', 'def' => 'y']; 

		return $arrField;
	}

}
