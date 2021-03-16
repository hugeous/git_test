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
namespace Component\Design;

use Component\Validator\Validator;
use Component\Database\DBTableField;
use Component\Page\Page;
use Globals;
use League\Flysystem\Exception;
use Request;
use Message;
use UserFilePath;
use FileHandler;
use Cookie;


/**
 * 팝업 관리 클래스
 * @author Shin Donggyu <artherot@godo.co.kr>
 */
class DesignPopup extends \Bundle\Component\Design\DesignPopup
{
	/**
     * 팝업 상세 데이타
     *
     * @param integer $sno 페이지 번호
     * @return array|boolean
     * @throws \Exception
     */
    public function getPopupDetailData($sno)
    {
        // Validation
        if (Validator::number($sno, null, null, true) === false) {
            throw new \Exception(sprintf(__('%s 항목이 잘못 되었습니다.'), '페이지 번호'));
        }

        // 스킨 정보
        if (empty($this->skinPath) === true) {
            $this->setSkin(Globals::get('gSkin.' . $this->skinType . 'SkinWork'));
        }

        // Data
        $arrBind = $arrWhere = [];
        array_push($arrWhere, 'sno = ?');
        $this->db->bind_param_push($arrBind, 'i', $sno);

        $arrField = DBTableField::setTableField('tableDesignPopup');
        $strSQL = 'SELECT ' . implode(', ', $arrField) . ',contentsType, bannerCode, mobileBannerCode  FROM ' . DB_DESIGN_POPUP . ' WHERE ' . implode(' AND ', $arrWhere) . ' ORDER BY sno DESC';
        $getData = $this->db->query_fetch($strSQL, $arrBind);

        if (empty($getData) === false) {
            return gd_htmlspecialchars_stripslashes($getData[0]);
        } else {
            return false;
        }
    }
}