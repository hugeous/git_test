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
namespace Component\Goods;

use Component\Database\DBTableField;
use Component\ExchangeRate\ExchangeRate;
use Component\Member\Group\Util;
use Component\Validator\Validator;
use Cookie;
use Exception;
use Framework\Utility\ArrayUtils;
use Framework\Utility\SkinUtils;
use Framework\Utility\StringUtils;
use Globals;
use Request;
use Session;
use UserFilePath;
use Framework\Utility\DateTimeUtils;

/**
 * 상품 class
 */
class Goods extends \Bundle\Component\Goods\Goods
{
	 /**
     * 상품 리스트용 필드
     */
    protected function setGoodsListField()
    {
		parent::setGoodsListField();
		$this->goodsListField .= ', CASE WHEN (SELECT COUNT(sno) FROM es_goodsLinkCategory WHERE goodsNo=g.goodsNo AND cateCd="008") >0 THEN "1" ELSE "0" END AS "DealStat" ';	
    }

}