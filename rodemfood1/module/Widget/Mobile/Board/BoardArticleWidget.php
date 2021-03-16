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

namespace Widget\Mobile\Board;

use Component\Board\BoardList;
use Framework\Cache\CacheableProxyFactory;

/**
 * Class BoardArticleWidget
 * @package Bundle\Widget\Mobile\Board
 * @author Lee Namju <lnjts@godo.co.kr>
 */
class BoardArticleWidget extends \Widget\Mobile\Widget
{
    public function index()
    {
        $bdId = $this->getData('bdId');
        $listCount = $this->getData('listCount') ?? 5;
        $strCut = $this->getData('strCut') ?? 30;
        $boardList = new BoardList(['bdId' => $bdId]);
        if ($boardList->canUsePc()) {
            $canList = $boardList->canList();
            $this->setData('canList', $canList);
            $this->setData('bdName', $boardList->getConfig('bdNm'));
            $this->setData('bdId', $bdId);
            $this->setData('cfg', $boardList->getConfig());
            if ($canList == 'y') {
                $list = $boardList->getList(false, $listCount, $strCut);
                $this->setData('list', $list['data']);
            }
        }
    }
}
