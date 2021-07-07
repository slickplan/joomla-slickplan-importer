<?php defined('_JEXEC') or exit('Restricted access.');

function_exists('ob_start') and ob_start();
function_exists('set_time_limit') and set_time_limit(600);

if (!class_exists('ContentHelperRoute')) {
    require_once JPATH_SITE . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'com_content' . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'route.php';
}

if (!class_exists('Slickplan_Importer_Controller')) {

    // Include required files
    require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'slickplan-importer.php';

    /**
     * Slickplan's plugin importer database option key.
     *
     * @var string
     */
    define('SLICKPLAN_PLUGIN_OPTION', 'slickplan_importer');

    class Slickplan_Importer_Controller extends Slickplan_Importer {

        /**
         * Extra variable to pass to view file.
         *
         * @var string
         */
        public $view = 'upload';

        /**
         * Parsed XML file
         *
         * @var array
         */
        public $xml = array();

        /**
         * Number of files in XML file
         *
         * @var int
         */
        public $no_of_files = 0;

        /**
         * Total files size in bytes
         *
         * @var int
         */
        public $filesize_total = 0;

        /**
         * Import summary
         *
         * @var array
         */
        private $_summary = array();

        /**
         * Import options
         *
         * @var array
         */
        private $_options = array();

        /**
         * If page has unparsed internal pages
         *
         * @var bool
         */
        private $_has_unparsed_internal_links = false;

        /**
         * Plugin router
         */
        public function __construct()
        {
            JToolBarHelper::title('Slickplan Importer');

            if (isset($_FILES['slickplanfile']) and $_FILES['slickplanfile']) {
                $result = $this->handleFileUpload();
                if ($result === true and $this->_isCorrectSlickplanXmlFile($this->xml, true)) {
                    $this->filesize_total = array();
                    if (isset($this->xml['pages']) and is_array($this->xml['pages'])) {
                        foreach ($this->xml['pages'] as $page) {
                            if (isset($page['contents']['body']) and is_array($page['contents']['body'])) {
                                foreach ($page['contents']['body'] as $body) {
                                    if (isset($body['content']['type']) and $body['content']['type'] === 'library') {
                                        ++$this->no_of_files;
                                    }
                                    if (isset($body['content']['file_size'], $body['content']['file_id']) and $body['content']['file_size']) {
                                        $this->filesize_total[$body['content']['file_id']] = (int)$body['content']['file_size'];
                                    }
                                }
                            }
                        }
                    }
                    $this->filesize_total = array_sum($this->filesize_total);
                    $size = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
                    $factor = (int)floor((strlen($this->filesize_total) - 1) / 3);
                    $this->filesize_total = round($this->filesize_total / pow(1024, $factor)) . $size[$factor];
                    $this->view = 'options';
                } else {
                    $this->_displayMessage($result, 'error');
                }
            } elseif (isset($_POST['slickplan_importer']) and is_array($_POST['slickplan_importer'])) {
                $session = JFactory::getSession();
                $this->xml = $session->get('slickplan_importer');

                if ($this->_isCorrectSlickplanXmlFile($this->xml, true)) {
                    if (is_dir(JPATH_ADMINISTRATOR . '/components/com_k2/tables')) {
                        JPluginHelper::importPlugin('k2');
                        JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_k2/tables');
                    }

                    jimport('joomla.filesystem.file');

                    $session->clear('slickplan_importer');

                    $result = $this->_doImport($_POST['slickplan_importer']);

                    $cache = JFactory::getCache('com_content');
                    $cache->clean();
                }
                else {
                    $result = 'Incorrect file content.';
                }
                if ($result === true and isset($this->xml['summary'])) {
                    $this->view = 'summary';
                    $this->_displayMessage(sprintf(
                        'All done. Thank you for using <a href="%s">Slickplan</a> Importer! ',
                        'http://slickplan.com/'
                    ));
                } else {
                    $this->_displayMessage($result, 'error');
                }
            }
            else {
                $this->_displayMessage(array(
                    'The Slickplan Importer plugin allows you to quickly import your '
                        . '<a href="http://slickplan.com" target="_blank">Slickplan</a> projects into your Joomla! site.',
                    'Upon import, your pages, navigation structure, and content will be instantly ready in your CMS.',
                    'Pick a XML file to upload and click Import.',
                ));
            }
        }

        /**
         * Handle XML file upload.
         *
         * @return bool|string
         */
        public function handleFileUpload()
        {
            $upload_error_strings = array(
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.',
            );
            $file = isset($_FILES['slickplanfile']) ? $_FILES['slickplanfile'] : array();
            if (!isset($file['tmp_name']) or !is_file($file['tmp_name'])) {
                $file['error'] = UPLOAD_ERR_NO_FILE;
            }
            if (isset($file['error'], $upload_error_strings[$file['error']]) and intval($file['error']) > 0) {
                return $upload_error_strings[$file['error']];
            }
            $xml = file_get_contents($file['tmp_name']);
            try {
                $this->xml = $this->_parseSlickplanXml($xml);
                $session = JFactory::getSession();
                $session->set('slickplan_importer', $this->xml);
            } catch (Exception $e) {
                return $e->getMessage();
            }
            return true;
        }

        /**
         * Display summary pages.
         *
         * @param string $array
         * @param integer $indent
         */
        public function displaySummaryArray(array $array)
        {
            echo '<ul>';
            foreach ($array as $page) {
                echo '<li>';
                if (!$page['published']) {
                    $page['title'] .= ' (draft)';
                }
                if (isset($page['ID']) and $page['ID']) {
//                    if (isset($this->xml['options']['k2']) and $this->xml['options']['k2']) {
//                        $link = 'com_k2&view=item&cid=';
//                    } else {
//                        $link = 'com_content&task=article.edit&a_id=';
//                    }
                    echo '<a href="', $page['full_url'], '">', $page['title'] . '</a>';
                } elseif (isset($page['error']) and $page['error']) {
                    echo $page['title'], ' - <span style="color: #e00;">', $page['error'] . '</span>';
                }
                if (isset($page['childs']) and is_array($page['childs']) and count($page['childs'])) {
                    $this->displaySummaryArray($page['childs']);
                }
            }
            echo '</ul>';
        }

        /**
         * Add a file to Media Library from URL
         *
         * @param $url
         * @param array $attrs Assoc array of attributes [title, alt, description, file_name]
         * @return bool|string
         */
        public function addMedia($url, array $attrs = array())
        {
            if (!$this->_options['content_files']) {
                return false;
            }

            $file_name = isset($attrs['file_name']) ? $attrs['file_name'] : basename($url);
            $file_name = JFile::makeSafe($file_name);

            $path = '/media/';

            $upload_path = JPATH_SITE . DIRECTORY_SEPARATOR . 'media';
            if (!is_dir($upload_path . DIRECTORY_SEPARATOR . 'slickplan')) {
                @mkdir($upload_path . DIRECTORY_SEPARATOR . 'slickplan', 0777);
            }
            if (is_dir($upload_path . DIRECTORY_SEPARATOR . 'slickplan')) {
                $upload_path .= DIRECTORY_SEPARATOR . 'slickplan';
                $path .= 'slickplan/';
            }
            $upload_path .= DIRECTORY_SEPARATOR . $file_name;

            $file = file_get_contents($url);

            if (JFile::write($upload_path, $file)) {
                return rtrim(JURI::root(), '/') . $path . $file_name;
            }
            return false;
        }

        /**
         * Display importer options and page mapping.
         *
         * @param array $form
         */
        private function _doImport(array $form)
        {
            if (isset($form['settings_title']) and $form['settings_title']) {
                $title = (isset($this->xml['settings']['title']) and $this->xml['settings']['title'])
                    ? $this->xml['settings']['title']
                    : $this->xml['title'];
                include_once JPATH_ROOT . '/components/com_config/model/cms.php';
                include_once JPATH_ROOT . '/components/com_config/model/form.php';
                include_once JPATH_ADMINISTRATOR . '/components/com_config/model/application.php';
                $model = new ConfigModelApplication;
                $data = $model->getData();
                $data['sitename'] = $title;
                if ($model->save($data)) {
                    JFactory::getApplication()->setUserState('com_config.config.global.data', $data);
                }
            }
            $this->_options = array(
                'titles' => isset($form['titles_change']) ? $form['titles_change'] : '',
                'content' => isset($form['content']) ? $form['content'] : '',
                'content_files' => (
                    isset($form['content'], $form['content_files'])
                    and $form['content'] === 'contents'
                    and $form['content_files']
                ),
                'k2' => (isset($form['k2']) and $form['k2']),
                'users' => isset($form['users_map']) ? $form['users_map'] : array(),
                'internal_links' => array(),
                'imported_pages' => array(),
            );

            foreach (array('home', '1', 'util', 'foot') as $type) {
                if (isset($this->xml['sitemap'][$type]) and is_array($this->xml['sitemap'][$type])) {
                    $this->_importPages($this->xml['sitemap'][$type]);
                }
            }

            $this->_checkForInternalLinks();

            $this->xml = array(
                'summary' => $this->_getMultidimensionalArray($this->_summary, 0, true),
                'options' => $this->_options,
            );
            return true;
        }

        /**
         * Import pages into WordPress.
         *
         * @param array $pages
         * @param int $parent_id
         */
        private function _importPages(array $pages, $parent_id = 0)
        {
            foreach ($pages as $page) {
                $this->_importPage($page, $parent_id);
            }
        }

        /**
         * Import single page into WordPress.
         *
         * @param array $data
         * @param int $parent_id
         */
        private function _importPage(array $data, $parent_id = 0)
        {
            $post_title = (isset($data['contents']['page_title']) and $data['contents']['page_title'])
                ? $data['contents']['page_title']
                : (isset($data['text']) ? $data['text'] : '');
            $post_title = $this->_getFormattedTitle($post_title, $this->_options['titles']);

            if ($this->_options['k2'] and is_dir(JPATH_ADMINISTRATOR . '/components/com_k2/tables')) {
                $row = JTable::getInstance('K2Item', 'Table');
            }
            else {
                $row = JTable::getInstance('Content', 'JTable');
            }

            $page = array(
                'catid' => 1,
                'title' => $post_title,
                'introtext' => '',
                'fulltext' => $post_title,
                'state' => 1,
                'published' => 1,
                'alias' => JApplication::stringURLSafe($post_title),
            );

            // Set post content
            if ($this->_options['content'] === 'desc') {
                if (isset($data['desc']) and !empty($data['desc'])) {
                    $page['fulltext'] = $data['desc'];
                }
            } elseif ($this->_options['content'] === 'contents') {
                if (
                    isset($data['contents']['body'])
                    and is_array($data['contents']['body'])
                    and count($data['contents']['body'])
                ) {
                    $page['fulltext'] = $this->_getFormattedContent($data['contents']['body']);
                }
            }

            $this->_has_unparsed_internal_links = false;
            if ($page['fulltext']) {
                $updated_content = $this->_parseInternalLinks($page['fulltext']);
                if ($updated_content) {
                    $page['fulltext'] = $updated_content;
                }
            }

            // Set post status
            if (isset($data['contents']['status']) and $data['contents']['status'] === 'draft') {
                $page['published'] = 0;
            }

            // Set post author
            if (isset(
                $data['contents']['assignee']['@value'],
                $this->_options['users'][$data['contents']['assignee']['@value']]
            )) {
                $page['created_by'] = $this->_options['users'][$data['contents']['assignee']['@value']];
            }

            // Set the SEO meta values
            if (
                isset($data['contents']['meta_title'])
                or isset($data['contents']['meta_description'])
                or isset($data['contents']['meta_focus_keyword'])
            ) {
                if (isset($data['contents']['meta_title']) and $data['contents']['meta_title']) {
                    // Does Joomla have it?
                }
                if (isset($data['contents']['meta_description']) and $data['contents']['meta_description']) {
                    $page['metadesc'] = $data['contents']['meta_description'];
                }
                if (isset($data['contents']['meta_focus_keyword']) and $data['contents']['meta_focus_keyword']) {
                    $page['metakey'] = $data['contents']['meta_focus_keyword'];
                }
            }

            // Set url slug
            if (isset($data['contents']['url_slug']) and $data['contents']['url_slug']) {
                $page['alias'] = str_replace('%page_name%', $post_title, $data['contents']['url_slug']);
                $page['alias'] = str_replace('%separator%', '-', $page['alias']);
            }

			$table = JTable::getInstance('Content', 'JTable');
			if ($table->load(array('alias' => $page['alias'], 'catid' => $page['catid']))) {
                $db = JFactory::getDBO();
				$db->setQuery('SHOW TABLE STATUS LIKE "' . $db->getPrefix() . 'content"');
				$table_status = $db->loadObject();
                $page['alias'] .= '-' . $table_status->Auto_increment;
			}

            if (!$row->bind($page) or !$row->check($page) or !$row->store($page)) {
                $page['ID'] = false;
                $page['error'] = $row->getError();
                $page['post_parent'] = $parent_id;
                $this->_summary[] = $page;
            } else {
                $page['ID'] = $row->id;
                $page['post_parent'] = $parent_id;

                $page['full_url'] = ContentHelperRoute::getArticleRoute($page['ID'] . ':' . $page['alias'], $page['catid']);
                $router = new JRouterSite(array('mode' => JROUTER_MODE_SEF));
                $page['full_url'] = $router
                    ->build($page['full_url'])
                    ->toString(array('path', 'query', 'fragment'));
                $page['full_url'] = str_replace(array(
                    '/administrator',
                    'component/content/article/',
                ), '', $page['full_url']);

                // Save page permalink
                if (isset($data['@attributes']['id'])) {
                    $this->_options['imported_pages'][$data['@attributes']['id']] = $page['full_url'];
                }

                // Check if page has unparsed internal links, we need to replace them later
                if ($this->_has_unparsed_internal_links) {
                    $this->_options['internal_links'][] = $page['ID'];
                }

                $this->_summary[] = $page;
                if (isset($data['childs']) and is_array($data['childs']) and $page['ID']) {
                    $this->_importPages($data['childs'], $page['ID']);
                }
            }
        }

        /**
         * Display importer errors.
         *
         * @param $errors
         */
        private function _displayMessage($errors, $type = 'success')
        {
            if ($errors) {
                if (version_compare(JPlatform::RELEASE, '12', '<')) {
                    if (is_array($errors)) {
                        $errors = implode('</li><li>', $errors);
                    }
                    $errors = trim($errors);
                    if ($errors) {
                        echo '<div id="system-message-container"><dl id="system-message">',
                             '<dd class="', $type, ' message"><ul><li>',
                             $errors,
                             '</li></ul></dd></dl></div>';
                    }
                }
                else {
                    if (is_array($errors)) {
                        $errors = implode('<br />', $errors);
                    }
                    $errors = trim($errors);
                    if ($errors) {
                        echo '<div class="alert alert-', $type, '">' . $errors . '</div>';
                    }
                }
            }
        }

        /**
         * Replace internal links with correct pages URLs.
         *
         * @param $content
         * @param $force_parse
         * @return bool
         */
        private function _parseInternalLinks($content, $force_parse = false)
        {
            preg_match_all('/href="slickplan:([a-z0-9]+)"/isU', $content, $internal_links);
            if (isset($internal_links[1]) and is_array($internal_links[1]) and count($internal_links[1])) {
                $internal_links = array_unique($internal_links[1]);
                $links_replace = array();
                foreach ($internal_links as $cell_id) {
                    if (
                        isset($this->_options['imported_pages'][$cell_id])
                        and $this->_options['imported_pages'][$cell_id]
                    ) {
                        $links_replace['="slickplan:' . $cell_id . '"'] = '="'
                            . htmlspecialchars($this->_options['imported_pages'][$cell_id]) . '"';
                    } elseif ($force_parse) {
                        $links_replace['="slickplan:' . $cell_id . '"'] = '="#"';
                    } else {
                        $this->_has_unparsed_internal_links = true;
                    }
                }
                if (count($links_replace)) {
                    return strtr($content, $links_replace);
                }
            }
            return false;
        }

        /**
         * Check if there are any pages with unparsed internal links, if yes - replace links with real URLs
         */
        private function _checkForInternalLinks()
        {
            if (isset($this->_options['internal_links']) and is_array($this->_options['internal_links'])) {
                foreach ($this->_options['internal_links'] as $page_id) {
                    if ($this->_options['k2'] and is_dir(JPATH_ADMINISTRATOR . '/components/com_k2/tables')) {
                        $page = JTable::getInstance('K2Item', 'Table');
                    }
                    else {
                        $page = JTable::getInstance('Content', 'JTable');
                    }
                    $page->load($page_id);
                    if (isset($page->fulltext) and $page->fulltext) {
                        $page_content = $this->_parseInternalLinks($page->fulltext, true);
                        if ($page_content) {
                            $page->fulltext = $page_content;
                            $page->store();
                        }
                    }
                }
            }
        }

    }

    if (version_compare(JPlatform::RELEASE, '12', '<')) {
        jimport('joomla.application.component.controller');
        jimport('joomla.application.component.view');
        if (!class_exists('JControllerAbstract')) {
            abstract class JControllerAbstract extends JController {}
        }
        if (!class_exists('JViewAbstract')) {
            abstract class JViewAbstract extends JView {}
        }
    }
    else {
        if (!class_exists('JControllerAbstract')) {
            abstract class JControllerAbstract extends JControllerLegacy {}
        }
        if (!class_exists('JViewAbstract')) {
            abstract class JViewAbstract extends JViewLegacy {}
        }
    }

    class SlickplanController extends JControllerAbstract {

        public function display($cachable = false, $urlparams = false)
        {
            global $slickplan;
            $slickplan = new Slickplan_Importer_Controller;
            JRequest::setVar('view', 'import');
            parent::display($cachable, $urlparams);
        }

    }

    $slickplan = null;

    $slickplan_controller = new SlickplanController;
    $slickplan_controller->execute(JRequest::getCmd('task', 'cpanel'));
    $slickplan_controller->redirect();

}
