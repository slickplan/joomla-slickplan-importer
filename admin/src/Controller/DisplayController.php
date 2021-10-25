<?php

namespace Slickplan\Component\Slickplanimporter\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Slickplan\Component\Slickplanimporter\Administrator\Helper\Importer;

class DisplayController extends BaseController
{
    protected $default_view = 'import';

    public function display($cachable = false, $urlparams = array())
    {
        global $slickplan;
        $slickplan = new Importer($this);

        return parent::display($cachable, $urlparams);
    }
}
