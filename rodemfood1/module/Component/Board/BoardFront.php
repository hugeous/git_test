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
namespace Component\Board;

use Component\Storage\Storage;
use Component\Member\Manager;
use Component\Member\Member;
use Component\Member\Util\MemberUtil;
use Component\Order\Order;
use Framework\Utility\DateTimeUtils;
use Framework\Utility\FileUtils;
use Framework\Utility\SkinUtils;
use Request;
use Session;


class BoardFront extends \Bundle\Component\Board\BoardFront
{
	/**
     * 웹서비스 형태로 데이터 가공
     *
     * @param array &$arrData
     */
    public function applyConfigList(&$arrData)
    {
        if (!$arrData) {
            return;
        }
        foreach ($arrData as &$data) {
            // 프론트 게시글의 답변작성자가 관리자면서 답변상태가 답변완료가 아닐때 체크
            if($this->cfg['bdAnswerStatusFl'] == 'y'){
                if($data['groupThread'] != ''){
                    $data['frontAdminReplyFl'] = $this->chkAdminData($data['writerId']);
                    if($data['frontAdminReplyFl'] == 1 && $data['replyStatus'] != 3){
                        $data['adminReplyFl'] = 'y';
                    }
                }
            }

            $data['bdId'] = $this->cfg['bdId'];
            $data['regDate'] = date_format(date_create($data['regDt']), "Y.m.d");
            if (date('Y.m.d') == $data['regDate']) {
                $data['regDate'] = date_format(date_create($data['regDt']), "H:i");
            }

            if ($this->cfg['bdEventFl'] == 'y' && (empty($data['eventStart']) === false) && (empty($data['eventEnd']) === false)) {
                $data['eventStart'] = date_format(date_create($data['eventStart']), "Y.m.d H:i");
                $data['eventEnd'] = date_format(date_create($data['eventEnd']), "Y.m.d H:i");
            }
            $data['replyStatusText'] = '-';
            $data['replyComplete'] = false;
            if ($this->cfg['bdReplyStatusFl'] == 'y' && $data['replyStatus'] > 0) {
                $array = Board::REPLY_STATUS_LIST;
                $data['replyStatusText'] = $array[$data['replyStatus']];
                $data['replyComplete'] = ($data['replyStatus'] == Board::REPLY_STATUS_COMPLETE);
            }

            if (!$data['recommend']) {
                $data['recommend'] = 0;
            }

            $data['gapReply'] = '';
            $data['isNew'] = 'n';
            $data['isHot'] = 'n';
            $data['isAdmin'] = 'n';
            $data['isFile'] = 'n';

            // 이미지 설정
            $data['imgSizeW'] = $this->cfg['bdListImgWidth'];
            $data['imgSizeH'] = $this->cfg['bdListImgHeight'];

            if ($data['groupThread'] != '') {
                $data['gapReply'] = '<span style="margin-left:' . (((strlen($data['groupThread']) / 2) - 1) * 15) . 'px"></span>';
            }

            if ($data['isDelete'] == 'y') {
                $data['auth']['view'] = $data['auth']['modify'] = $data['auth']['delete'] = 'n';
            }

            $data['isFile'] = 'n';
            if (gd_isset($data['saveFileNm'])) {
                $data['isFile'] = 'y';
            }

            $data['isImage'] = 'n';
            preg_match("/<img[^>]*src=[\"']?([^>\"']+)[\"']?[^>]*>/i", $data['contents'], $match);
            $imgSrc = $match[1];
            if ($imgSrc) {
                $data['isImage'] = 'y';
                $data['editorImageSrc'] = $imgSrc;
            }

            if ($data['isSecret'] == 'y') {
                if ($this->cfg['bdSecretTitleFl'] == 1) {
                    if (gd_is_login() && $this->member['memNo'] == $data['memNo']) {
                    } else {
                        $data['subject'] = $this->cfg['bdSecretTitleTxt'];
                    }
                }
            }

            if ($this->cfg['bdGoodsPtFl'] == 'y') {
                $data['goodsPtPer'] = $data['goodsPt'] * 20;
            }

            if ($this->cfg['bdSubjectLength']) {
                $data['subject'] = gd_html_cut($data['subject'], $this->cfg['bdSubjectLength']);
            }

            $data['subject'] = $this->xssClean($data['subject']);
            if ($this->canList() == 'n') {
                $data['subject'] = '볼 수 있는 권한이 없습니다.';
            }

            $data['auth']['view'] = $this->canRead($data);
            $data['auth']['modify'] = $this->canModify($data);
            $data['auth']['delete'] = $this->canRemove($data);

            // 아이콘 설정
            if ($this->cfg['bdNewFl'] && (time() - strtotime($data['regDt'])) / 60 / 60 < $this->cfg['bdNewFl']) $data['isNew'] = 'y';
            if ($this->cfg['bdHotFl'] && $data['hit'] >= $this->cfg['bdHotFl']) $data['isHot'] = 'y';

            $data['writer'] = $this->getWriterInfo($data);
            //리스트 노출이미지
            $imgStr = '<img src="%s" width="%d" height="%d" />';
            $data['viewListImage'] = '';

            if ($data['uploadFileNm']) {
                $uploadFileNms = explode(STR_DIVISION, $data['uploadFileNm']);
                $imageFileNum = -1;
                for ($i = 0; $i < count($uploadFileNms); $i++) {
                    if (FileUtils::isImageExtension($uploadFileNms[$i])) {
                        $imageFileNum = $i;
                        break;
                    }
                }
                if ($imageFileNum > -1) {
                    $saveFileNames = explode(STR_DIVISION, $data['saveFileNm']);
                    if (gd_isset($data['bdUploadStorage'])) {
                        $storage = Storage::disk(Storage::PATH_CODE_BOARD, $data['bdUploadStorage']);
                        if (Request::isMobile()) {
                            $data['attachImageSrc'] = $storage->getHttpPath($data['bdUploadPath'] . $saveFileNames[$imageFileNum]);
                        } else {
                            try {
                                $existsThumPath = null;
                                if($data['bdUploadStorage'] == 'local') {
                                    $existsThumPath = $storage->isFileExists($data['bdUploadThumbPath'] . $saveFileNames[$imageFileNum]);
                                }
                                if ($existsThumPath) {
                                    if ($this->cfg['bdKind'] == Board::KIND_EVENT) {
                                        $data['attachImageSrc'] = $storage->getHttpPath($data['bdUploadPath'] . $saveFileNames[$imageFileNum]);
                                    } else {
                                        $data['attachImageSrc'] = $storage->getHttpPath($data['bdUploadThumbPath'] . $saveFileNames[$imageFileNum]);
                                    }
                                } else if ($data['bdUploadPath']) {
                                    $data['attachImageSrc'] = $storage->getHttpPath($data['bdUploadPath'] . $saveFileNames[$imageFileNum]);
                                }
                            } catch(\Throwable $e){

                            }
                        }
                    }
                }
            }

            if ($this->cfg['goodsType'] == 'order' && substr($data['orderGoodsNoText'], 0, 1) == 'A') {
                $order = new Order();
                $orderGoodsNo = substr($data['orderGoodsNoText'], 1);
                $_addOrderGoodsData = $order->getOrderGoodsData($data['orderNo'], $orderGoodsNo);
                $scmNo = key($_addOrderGoodsData);
                $addOrderGoodsData = $_addOrderGoodsData[$scmNo][0];
                $data['goodsImageSrc'] = SkinUtils::imageViewStorageConfig($addOrderGoodsData['addImageName'], $addOrderGoodsData['addImagePath'], $addOrderGoodsData['addImageStorage'], 100, 'add_goods')[0];
            } else {
                if ($data['onlyAdultFl'] == 'y' && gd_check_adult() === false && $data['onlyAdultImageFl'] =='n') {
                    if (\Request::isMobile()) {
                        $data['goodsImageSrc'] = "/data/icon/goods_icon/only_adult_mobile.png";
                    } else {
                        $data['goodsImageSrc'] = "/data/icon/goods_icon/only_adult_pc.png";
                    }
                } else {
                    $data['goodsImageSrc'] = SkinUtils::imageViewStorageConfig($data['imageName'], $data['imagePath'], $data['imageStorage'], 100, 'goods')[0];
                }
            }

            switch ($this->cfg['bdListImageTarget']) {
                case 'upload' :
					if($data['attachImageSrc'] != ""){
						$data['viewListImage'] = $data['attachImageSrc'];
					} else if($data['editorImageSrc'] != ""){
						$data['viewListImage'] = $data['editorImageSrc'];
					} else if($data['goodsImageSrc'] != ""){
						$data['viewListImage'] = $data['goodsImageSrc'];
					}
                    
                    break;
                case'editor' :
                    preg_match("/<img[^>]*src=[\"']?([^>\"']+)[\"']?[^>]*>/i", $data['contents'], $match);
                    $imgSrc = $match[1];
                    if ($imgSrc) {
                        $data['isImage'] = 'y';
                        $data['editorImageSrc'] = $imgSrc;
                    }                    

					if($data['editorImageSrc'] != ""){
						$data['viewListImage'] = $data['editorImageSrc'];
					} else if($data['attachImageSrc'] != ""){
						$data['viewListImage'] = $data['attachImageSrc'];
					} else if($data['goodsImageSrc'] != ""){
						$data['viewListImage'] = $data['goodsImageSrc'];
					}
                    break;
                case 'goods' :                   

					if($data['goodsImageSrc'] != ""){
						$data['viewListImage'] = $data['goodsImageSrc'];
					} else if($data['attachImageSrc'] != ""){
						$data['viewListImage'] = $data['attachImageSrc'];
					} else if($data['editorImageSrc'] != ""){
						$data['viewListImage'] = $data['editorImageSrc'];
					}

                    break;
            }

			$data['isEnd'] = 'n';
            
            if ($this->cfg['bdEventFl'] == 'y' && (empty($data['eventStart']) === false) && (empty($data['eventEnd']) === false)) {
				$today = date("Y-m-d H:i:s");
				if (strtotime(str_replace('.' , '-', $data['eventEnd'])) < strtotime($today)) {
					$data['isEnd'] = 'y';					
				}                
            }    
        }

    }
}