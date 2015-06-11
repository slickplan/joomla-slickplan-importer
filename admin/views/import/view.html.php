<?php defined('_JEXEC') or die;

jimport('joomla.application.component.view');

class SlickplanViewImport extends JViewAbstract
{

    function display($tpl = null)
    {
        $this->loadHelper('html');
        parent::display($tpl);
    }

}
