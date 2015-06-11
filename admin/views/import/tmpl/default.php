<?php defined('_JEXEC') or die; ?>
<?php global $slickplan; ?>

<div class="wrap" id="slickplan-importer">
    <form action="" method="post" enctype="multipart/form-data">
        <?php if ($slickplan->view === 'options') { ?>
            <br>
            <fieldset class="radiocheck">
                <legend><span>Website Settings</span></legend>
                <?php
                $title = (isset($slickplan->xml['settings']['title']) and $slickplan->xml['settings']['title'])
                    ? $slickplan->xml['settings']['title']
                    : $slickplan->xml['title'];
                ?>
                <label for="slickplan-settings_title">
                    <input type="checkbox" name="slickplan_importer[settings_title]" id="slickplan-settings_title" value="1">
                    Set site name to <cite>&bdquo;<?php echo htmlspecialchars($title); ?>&rdquo;</cite>
                </label>
                <p class="description">It will change the Site Name in Global Configuration</p>
                <?php if (is_dir(JPATH_ADMINISTRATOR . '/components/com_k2/tables')) { ?>
                    <label for="slickplan-importk2">
                        <input type="checkbox" name="slickplan_importer[k2]" id="slickplan-importk2" value="1">
                        Import for K2 Content
                    </label>
                <?php } ?>
            </fieldset>
            <br>
            <fieldset class="radiocheck">
                <legend><span>Pages Titles Modification</span></legend>
                <label for="slickplan-titles_change1">
                    <input type="radio" name="slickplan_importer[titles_change]" id="slickplan-titles_change1" value="" checked>
                    No change
                </label>
                <label for="slickplan-titles_change2">
                    <input type="radio" name="slickplan_importer[titles_change]" id="slickplan-titles_change2" value="ucfirst">
                    Make just the first character uppercase:
                </label>
                <p class="description">This is an example page title</p>
                <label for="slickplan-titles_change3">
                    <input type="radio" name="slickplan_importer[titles_change]" id="slickplan-titles_change3" value="ucwords">
                    Uppercase the first character of each word:
                </label>
                <p class="description">This Is An Example Page Title</p>
            </fieldset>
            <br>
            <fieldset class="radiocheck" id="slickplan-page-content-radios">
                <legend><span>Pages Settings</span></legend>
                <label for="slickplan-content-contents">
                    <input type="radio" name="slickplan_importer[content]" id="slickplan-content-contents" value="contents" checked>
                    Import page content
                </label>
                <div class="content-suboption">
                    <label for="slickplan-content_files">
                        <input type="checkbox" name="slickplan_importer[content_files]" id="slickplan-content_files" value="1">
                        Import files to media manager
                    </label>
                    <p class="description ">Downloading files may take a while</p>
                </div>
                <label for="slickplan-content-notes">
                    <input type="radio" name="slickplan_importer[content]" id="slickplan-content-notes" value="desc">
                    Import page notes as content
                </label>
                <label for="slickplan-content-none">
                    <input type="radio" name="slickplan_importer[content]" id="slickplan-content-none" value="">
                    Don&#8217;t import any content
                </label>
            </fieldset>
            <?php if (isset($slickplan->xml['users']) and is_array($slickplan->xml['users']) and count($slickplan->xml['users'])) { ?>
                <br>
                <fieldset>
                    <legend><span>Users Mapping</span></legend>
                    <table>
                    <?php
                    $db = JFactory::getDBO();
                    $db->setQuery('SELECT * FROM #__users');
                    $rows = $db->loadObjectList();
                    $options = '';
                    foreach ($rows as $row) {
                        $options .= '<option value="' . $row->id . '">' . $row->username . '</option>';
                    }
                    foreach ($slickplan->xml['users'] as $user_id => $data) {
                        $name = array();
                        if (isset($data['firstName']) and $data['firstName']) {
                            $name[] = $data['firstName'];
                        }
                        if (isset($data['lastName']) and $data['lastName']) {
                            $name[] = $data['lastName'];
                        }
                        if (isset($data['email']) and $data['email']) {
                            if (count($name)) {
                                $data['email'] = '(' . $data['email'] . ')';
                            }
                            $name[] = $data['email'];
                        }
                        if (!count($name)) {
                            $name[] = $user_id;
                        }
                        ?>
                        <tr>
                            <td><?php echo implode(' ', $name); ?>:</td>
                            <td><select name="slickplan_importer[users_map][<?php echo $user_id; ?>]"><?php echo $options; ?></select></td>
                        </tr>
                    <?php } ?>
                    </table>
                </fieldset>
            <?php } ?>
            <br>
            <div class="form-actions">
                <input type="submit" value="Import" class="btn btn-primary">
            </div>
        <?php
        } elseif ($slickplan->view === 'summary') {
            if (isset($slickplan->xml['summary']) and is_array($slickplan->xml['summary'])) {
                $slickplan->displaySummaryArray($slickplan->xml['summary']);
            }
        } else {
        ?>
            <fieldset class="uploadform">
                <legend>Upload Slickplan&#8217;s XML file</legend>
                <input type="file" size="57" name="slickplanfile" id="slickplan-slickplanfile" class="input_box">
            </fieldset>
            <br>
            <div class="form-actions">
                <input type="submit" value="Upload" class="btn btn-primary">
            </div>
        <?php } ?>
    </form>
</div>

<style type="text/css">
    #slickplan-importer fieldset.radiocheck input {
        margin: 2px 5px 0 0;
        vertical-align: top;
    }
    #slickplan-importer p.description {
        clear: both;
        color: #999;
        font-size: 11px;
        margin: 0 0 3px;
        text-indent: 22px;
    }
    #slickplan-importer .content-suboption {
        padding-left: 20px;
    }
</style>

<script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#slickplan-page-content-radios')
            .find('input[type="radio"]').on('change', function() {
                $(this).closest('fieldset')
                    .find('.content-suboption').css(
                        'display',
                        (this.value === 'contents') ? 'block' : 'none'
                    );
            })
            .filter(':checked')
            .trigger('change');
    });
</script>