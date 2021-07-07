<?php defined('_JEXEC') or exit('Restricted access.');

if (!class_exists('Slickplan_Importer')) {

    /**
     * Class Slickplan_Importer
     */
    abstract class Slickplan_Importer
    {

        /**
         * Add a file to Media Library from URL
         *
         * @param $url
         * @param array $attrs Assoc array of attributes [title, alt, description, file_name]
         * @return bool|string
         */
        abstract public function addMedia($url, array $attrs = array());

        /**
         * Get formatted HTML content.
         *
         * @param array $content
         */
        protected function _getFormattedContent(array $contents)
        {
            $post_content = array();
            foreach ($contents as $type => $content) {
                if (isset($content['content'])) {
                    $content = array($content);
                }
                foreach ($content as $element) {
                    if (!isset($element['content'])) {
                        continue;
                    }
                    $html = '';
                    switch ($type) {
                        case 'wysiwyg':
                            $html .= $element['content'];
                            break;
                        case 'text':
                            $html .= htmlspecialchars($element['content']);
                            break;
                        case 'image':
                            foreach ($this->_getMediaElementArray($element) as $item) {
                                if (isset($item['type'], $item['url'])) {
                                    $attrs = array(
                                        'alt' => isset($item['alt'])
                                            ? $item['alt']
                                            : '',
                                        'title' => isset($item['title'])
                                            ? $item['title']
                                            : '',
                                        'file_name' => isset($item['file_name'])
                                            ? $item['file_name']
                                            : '',
                                    );
                                    if ($item['type'] === 'library') {
                                        $src = $this->addMedia($item['url'], $attrs);
                                    } else {
                                        $src = $item['url'];
                                    }
                                    if ($src and is_string($src)) {
                                        $html .= '<img src="' . htmlspecialchars($src)
                                            . '" alt="' . htmlspecialchars($attrs['alt'])
                                            . '" title="' . htmlspecialchars($attrs['title']) . '" />';
                                    }
                                }
                            }
                            break;
                        case 'video':
                        case 'file':
                            foreach ($this->_getMediaElementArray($element) as $item) {
                                if (isset($item['type'], $item['url'])) {
                                    $attrs = array(
                                        'description' => isset($item['description'])
                                            ? $item['description']
                                            : '',
                                        'file_name' => isset($item['file_name'])
                                            ? $item['file_name']
                                            : '',
                                    );
                                    if ($item['type'] === 'library') {
                                        $src = $this->addMedia($item['url'], $attrs);
                                        $name = basename($src);
                                    } else {
                                        $src = $item['url'];
                                        $name = $src;
                                    }
                                    if ($src and is_string($src)) {
                                        $name = $attrs['description']
                                            ? $attrs['description']
                                            : ($attrs['file_name'] ? $attrs['file_name'] : $name);
                                        $html .= '<a href="' . htmlspecialchars($src) . '" title="'
                                            . htmlspecialchars($attrs['description']) . '">' . $name . '</a>';
                                    }
                                }
                            }
                            break;
                        case 'table':
                            if (isset($element['content']['data'])) {
                                if (!is_array($element['content']['data'])) {
                                    $element['content']['data'] = @json_decode($element['content']['data'], true);
                                }
                                if (is_array($element['content']['data'])) {
                                    $html .= '<table>';
                                    foreach ($element['content']['data'] as $row) {
                                        $html .= '<tr>';
                                        foreach ($row as $cell) {
                                            $html .= '<td>' . $cell . '</td>';
                                        }
                                        $html .= '</tr>';
                                    }
                                    $html .= '<table>';
                                }
                            }
                            break;
                    }
                    if ($html) {
                        $prepend = '';
                        $append = '';
                        if (isset($element['options']['tag']) and $element['options']['tag']) {
                            $element['options']['tag'] = preg_replace('/[^a-z]+/', '',
                                strtolower($element['options']['tag']));
                            if ($element['options']['tag']) {
                                $prepend = '<' . $element['options']['tag'];
                                if (isset($element['options']['tag_id']) and $element['options']['tag_id']) {
                                    $prepend .= ' id="' . htmlspecialchars($element['options']['tag_id']) . '"';
                                }
                                if (isset($element['options']['tag_class']) and $element['options']['tag_class']) {
                                    $prepend .= ' class="' . htmlspecialchars($element['options']['tag_class']) . '"';
                                }
                                $prepend .= '>';
                            }
                        }
                        if (isset($element['options']['tag']) and $element['options']['tag']) {
                            $append = '</' . $element['options']['tag'] . '>';
                        }
                        $post_content[] = $prepend . $html . $append;
                    }
                }
            }
            return implode("\n\n", $post_content);
        }

        /**
         * Reformat title.
         *
         * @param $title
         * @param $type
         * @return string
         */
        protected function _getFormattedTitle($title, $type)
        {
            if ($type === 'ucfirst') {
                if (function_exists('mb_strtolower')) {
                    $title = mb_strtolower($title);
                    $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
                } else {
                    $title = ucfirst(strtolower($title));
                }
            } elseif ($type === 'ucwords') {
                if (function_exists('mb_convert_case')) {
                    $title = mb_convert_case($title, MB_CASE_TITLE);
                } else {
                    $title = ucwords(strtolower($title));
                }
            }
            return $title;
        }

        /**
         * Parse Slickplan's XML file. Converts an XML DOMDocument to an array.
         *
         * @param $input_xml
         * @return array
         * @throws Exception
         */
        protected function _parseSlickplanXml($input_xml)
        {
            $input_xml = trim($input_xml);
            if (substr($input_xml, 0, 5) === '<?xml') {
                $xml = new DomDocument('1.0', 'UTF-8');
                $xml->xmlStandalone = false;
                $xml->formatOutput = true;
                $xml->loadXML($input_xml);
                if (isset($xml->documentElement->tagName) and $xml->documentElement->tagName === 'sitemap') {
                    $array = $this->_parseSlickplanXmlNode($xml->documentElement);
                    if ($this->_isCorrectSlickplanXmlFile($array)) {
                        if (isset($array['diagram'])) {
                            unset($array['diagram']);
                        }
                        if (isset($array['section']['options'])) {
                            $array['section'] = array($array['section']);
                        }
                        $array['sitemap'] = $this->_getMultidimensionalArrayHelper($array);
                        $array['users'] = array();
                        foreach ($array['section'] as $section_key => $section) {
                            if (isset($section['cells']['cell']) and is_array($section['cells']['cell'])) {
                                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                                    if (
                                        isset($section['options']['id'], $cell['level'])
                                        and $cell['level'] === 'home'
                                        and $section['options']['id'] !== 'svgmainsection'
                                    ) {
                                        unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                                    }
                                    if (isset(
                                        $cell['contents']['assignee']['@value'],
                                        $cell['contents']['assignee']['@attributes']
                                    )) {
                                        $array['users'][$cell['contents']['assignee']['@value']]
                                            = $cell['contents']['assignee']['@attributes'];
                                    }
                                    if (isset($cell['@attributes']['id'])) {
                                        $array['pages'][$cell['@attributes']['id']] = $cell;
                                    }
                                }
                            }
                        }
                        unset($array['section']);
                        return $array;
                    }
                }
            }
            throw new Exception('Invalid file format.');
        }

        /**
         * Parse single node XML element.
         *
         * @param DOMElement $node
         * @return array|string
         */
        protected function _parseSlickplanXmlNode($node)
        {
            if (isset($node->nodeType)) {
                if ($node->nodeType === XML_CDATA_SECTION_NODE or $node->nodeType === XML_TEXT_NODE) {
                    return trim($node->textContent);
                } elseif ($node->nodeType === XML_ELEMENT_NODE) {
                    $output = array();
                    for ($i = 0, $j = $node->childNodes->length; $i < $j; ++$i) {
                        $child_node = $node->childNodes->item($i);
                        $value = $this->_parseSlickplanXmlNode($child_node);
                        if (isset($child_node->tagName)) {
                            if (!isset($output[$child_node->tagName])) {
                                $output[$child_node->tagName] = array();
                            }
                            $output[$child_node->tagName][] = $value;
                        } elseif ($value !== '') {
                            $output = $value;
                        }
                    }

                    if (is_array($output)) {
                        foreach ($output as $tag => $value) {
                            if (is_array($value) and count($value) === 1) {
                                $output[$tag] = $value[0];
                            }
                        }
                        if (empty($output)) {
                            $output = '';
                        }
                    }

                    if ($node->attributes->length) {
                        $attributes = array();
                        foreach ($node->attributes as $attr_name => $attr_node) {
                            $attributes[$attr_name] = (string)$attr_node->value;
                        }
                        if (!is_array($output)) {
                            $output = array(
                                '@value' => $output,
                            );
                        }
                        $output['@attributes'] = $attributes;
                    }
                    return $output;
                }
            }
            return array();
        }

        /**
         * Check if the array is from a correct Slickplan XML file.
         *
         * @param array $array
         * @param bool $parsed
         * @return bool
         */
        protected function _isCorrectSlickplanXmlFile($array, $parsed = false)
        {
            $first_test = (
                $array
                and is_array($array)
                and isset($array['title'], $array['version'], $array['link'])
                and is_string($array['link']) and strstr($array['link'], 'slickplan.')
            );
            if ($first_test) {
                if ($parsed) {
                    if (isset($array['sitemap']) and is_array($array['sitemap'])) {
                        return true;
                    }
                } elseif (
                    isset($array['section']['options']['id'], $array['section']['cells'])
                    or isset($array['section'][0]['options']['id'], $array['section'][0]['cells'])
                ) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Get multidimensional array, put all child pages as nested array of the parent page.
         *
         * @param array $array
         * @return array
         */
        protected function _getMultidimensionalArrayHelper(array $array)
        {
            $cells = array();
            $main_section_key = -1;
            $relation_section_cell = array();
            foreach ($array['section'] as $section_key => $section) {
                if (
                    isset($section['@attributes']['id'], $section['cells']['cell'])
                    and is_array($section['cells']['cell'])
                ) {
                    foreach ($section['cells']['cell'] as $cell_key => $cell) {
                        if (isset($cell['@attributes']['id'])) {
                            $cell_id = $cell['@attributes']['id'];
                            if (isset($cell['section']) and $cell['section']) {
                                $relation_section_cell[$cell['section']] = $cell_id;
                            }
                        } else {
                            unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                        }
                    }
                } else {
                    unset($array['section'][$section_key]);
                }
            }
            foreach ($array['section'] as $section_key => $section) {
                $section_id = $section['@attributes']['id'];
                if ($section_id !== 'svgmainsection') {
                    $remove = true;
                    foreach ($section['cells']['cell'] as $cell_key => $cell) {
                        $cell['level'] = (string)$cell['level'];
                        if ($cell['level'] === 'home') {
                            unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                        } elseif ($cell['level'] === '1' and isset($relation_section_cell[$section_id])) {
                            $array['section'][$section_key]['cells']['cell'][$cell_key]['parent']
                                = $relation_section_cell[$section_id];
                            $remove = false;
                            $array['section'][$section_key]['cells']['cell'][$cell_key]['order'] *= 10;
                        }
                    }
                    if ($remove) {
                        unset($array['section'][$section_key]);
                    }
                } else {
                    $main_section_key = $section_key;
                    foreach ($section['cells']['cell'] as $cell_key => $cell) {
                        $array['section'][$section_key]['cells']['cell'][$cell_key]['order'] /= 1000;
                    }
                }
            }
            foreach ($array['section'] as $section_key => $section) {
                $section_cells = array();
                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                    $section_cells[] = $cell;
                }
                usort($section_cells, array($this, '_sortPages'));
                $array['section'][$section_key]['cells']['cell'] = $section_cells;
                $cells = array_merge($cells, $section_cells);
                unset($section_cells);
            }
            $multi_array = array();
            if (isset($array['section'][$main_section_key]['cells']['cell'])) {
                foreach ($array['section'][$main_section_key]['cells']['cell'] as $cell) {
                    if ($cell['level'] === 'home' or $cell['level'] === 'util' or $cell['level'] === 'foot'
                        or $cell['level'] === '1' or $cell['level'] === 1
                    ) {
                        $childs = $this->_getMultidimensionalArray($cells, $cell['@attributes']['id']);
                        if ($childs) {
                            $cell['childs'] = $childs;
                        }
                        if (!isset($multi_array[$cell['level']]) or !is_array($multi_array[$cell['level']])) {
                            $multi_array[$cell['level']] = array();
                        }
                        $multi_array[$cell['level']][] = $cell;
                    }
                }
            }
            unset($array, $cells, $relation_section_cell);
            return $multi_array;
        }

        /**
         * Put all child pages as nested array of the parent page.
         *
         * @param array $array
         * @param $parent
         * @param $summary
         * @return array
         */
        protected function _getMultidimensionalArray(array $array, $parent, $summary = false)
        {
            $cells = array();
            $parent_key = $summary ? 'post_parent' : 'parent';
            foreach ($array as $cell) {
                if (isset($cell[$parent_key]) and $cell[$parent_key] === $parent) {
                    $cell_id = $summary ? $cell['ID'] : $cell['@attributes']['id'];
                    $childs = $this->_getMultidimensionalArray($array, $cell_id, $summary);
                    if ($childs) {
                        $cell['childs'] = $childs;
                    }
                    $cells[] = $cell;
                }
            }
            return $cells;
        }

        /**
         * Sort cells.
         *
         * @param array $a
         * @param array $b
         * @return int
         */
        protected function _sortPages(array &$a, array &$b)
        {
            if (isset($a['order'], $b['order'])) {
                return ($a['order'] < $b['order']) ? -1 : 1;
            }
            return 0;
        }

        /**
         * @param array $element
         * @return array
         */
        protected function _getMediaElementArray(array $element): array
        {
            $items = $element['content']['contentelement'] ?? $element['content'];
            return isset($items['type'])
                ? [$items]
                : (isset($items[0]['type']) ? $items : []);
        }

    }

}
