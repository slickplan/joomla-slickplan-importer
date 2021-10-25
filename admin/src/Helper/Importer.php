<?php

namespace Slickplan\Component\Slickplanimporter\Administrator\Helper;

defined('_JEXEC') or die;

use ContentHelperRoute;
use Exception;
use JFactory;
use JFile;
use Joomla\CMS\Workflow\Workflow;
use Joomla\String\StringHelper;
use JRouterSite;
use JURI;
use JTable;
use JPluginHelper;
use JFilterOutput;

require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'slickplan-importer.php';

class Importer extends \Slickplan_Importer
{

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
     * Import summary
     *
     * @var Joomla\CMS\MVC\Controller\BaseController
     */
    private $_controller = null;

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
    public function __construct($controller)
    {
        $this->_controller = $controller;

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
                $this->filesize_total = round($this->filesize_total / pow(1024, $factor)).$size[$factor];
                $this->view = 'options';
            } else {
                $this->_displayMessage($result, 'error');
            }
        } elseif (isset($_POST['slickplan_importer']) and is_array($_POST['slickplan_importer'])) {
            $session = JFactory::getSession();
            $this->xml = $session->get('slickplan_importer');

            if ($this->_isCorrectSlickplanXmlFile($this->xml, true)) {
                $session->clear('slickplan_importer');

                $result = $this->_doImport($_POST['slickplan_importer']);

                $cache = JFactory::getCache('com_content');
                $cache->clean();
            } else {
                $result = 'Incorrect file content.';
            }
            if ($result === true and isset($this->xml['summary'])) {
                $this->view = 'summary';
                $this->_displayMessage(
                    sprintf(
                        'All done. Thank you for using <a href="%s">Slickplan</a> Importer! ',
                        'https://slickplan.com/'
                    )
                );
            } else {
                $this->_displayMessage($result, 'error');
            }
        } else {
            $this->_displayMessage(array(
                                       'The Slickplan Importer plugin allows you to quickly import your '
                                       .'<a href="https://slickplan.com" target="_blank">Slickplan</a> projects into your Joomla! site.',
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
        $file = $_FILES['slickplanfile'] ?? array();
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
     * @param  string  $array
     * @param  integer  $indent
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
//                        $link = 'com_content&task=article.edit&a_id=';
                echo '<a href="', $page['full_url'], '">', $page['title'].'</a>';
            } elseif (isset($page['error']) and $page['error']) {
                echo $page['title'], ' - <span style="color: #e00;">', $page['error'].'</span>';
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
     * @param  array  $attrs  Assoc array of attributes [title, alt, description, file_name]
     * @return bool|string
     */
    public function addMedia($url, array $attrs = array())
    {
        if (!$this->_options['content_files']) {
            return false;
        }

        $file_name = $attrs['file_name'] ?? basename($url);
        $file_name = JFile::makeSafe($file_name);

        $path = '/media/';

        $upload_path = JPATH_SITE.DIRECTORY_SEPARATOR.'media';
        if (!is_dir($upload_path.DIRECTORY_SEPARATOR.'slickplan')) {
            @mkdir($upload_path.DIRECTORY_SEPARATOR.'slickplan', 0777);
        }
        if (is_dir($upload_path.DIRECTORY_SEPARATOR.'slickplan')) {
            $upload_path .= DIRECTORY_SEPARATOR.'slickplan';
            $path .= 'slickplan/';
        }
        $upload_path .= DIRECTORY_SEPARATOR.$file_name;

        $file = file_get_contents($url);

        if (JFile::write($upload_path, $file)) {
            return rtrim(JURI::root(), '/').$path.$file_name;
        }
        return false;
    }

    /**
     * Display importer options and page mapping.
     *
     * @param  array  $form
     */
    private function _doImport(array $form)
    {
        $this->_options = array(
            'titles' => $form['titles_change'] ?? '',
            'content' => $form['content'] ?? '',
            'content_files' => (
                isset($form['content'], $form['content_files'])
                and $form['content'] === 'contents'
                and $form['content_files']
            ),
            'users' => $form['users_map'] ?? array(),
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
     * @param  array  $pages
     * @param  int  $parent_id
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
     * @param  array  $data
     * @param  int  $parent_id
     */
    private function _importPage(array $data, $parent_id = 0)
    {
        $post_title = (isset($data['contents']['page_title']) and $data['contents']['page_title'])
            ? $data['contents']['page_title']
            : ($data['text'] ?? '');
        $post_title = $this->_getFormattedTitle($post_title, $this->_options['titles']);

        $page = array(
            'catid' => 2,
            'title' => $post_title,
            'introtext' => '',
            'fulltext' => $post_title,
            'state' => Workflow::CONDITION_PUBLISHED,
            'published' => 1,
            'alias' => $post_title,
            'language' => '*',
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
            $page['state'] = Workflow::CONDITION_UNPUBLISHED;
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

        $page['alias'] = JFilterOutput::stringURLSafe($page['alias']);

        $table = JTable::getInstance('Content', 'JTable');
        while ($table->load(array('alias' => $page['alias'], 'catid' => $page['catid']))) {
            $page['alias'] = StringHelper::increment($page['alias'], 'dash');
        }

        $app = JFactory::getApplication();
        $mvcFactory = $app->bootComponent('com_content')->getMVCFactory();
        $row = $mvcFactory->createModel('Article', 'Administrator', ['ignore_request' => true]);

        if (!$row->save($page)) {
            $page['ID'] = false;
            $page['error'] = $row->getError();
            $page['post_parent'] = $parent_id;
            $this->_summary[] = $page;
        } else {
            $page['ID'] = $row->getState('article.id');
            $page['post_parent'] = $parent_id;

            $page['full_url'] = ContentHelperRoute::getArticleRoute($page['ID'].':'.$page['alias'], $page['catid']);
            $router = new JRouterSite();
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
            if (is_array($errors)) {
                $errors = implode('<br />', $errors);
            }
            $errors = trim($errors);
            if ($errors) {
                echo '<div class="alert alert-', $type, '">'.$errors.'</div>';
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
                    $links_replace['="slickplan:'.$cell_id.'"'] = '="'
                        .htmlspecialchars($this->_options['imported_pages'][$cell_id]).'"';
                } elseif ($force_parse) {
                    $links_replace['="slickplan:'.$cell_id.'"'] = '="#"';
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
                $page = JTable::getInstance('Content', 'JTable');
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
