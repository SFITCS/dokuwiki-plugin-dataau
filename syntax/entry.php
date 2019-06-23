<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

/**
 * Class syntax_plugin_dataau_entry
 */
class syntax_plugin_dataau_entry extends DokuWiki_Syntax_Plugin {

    /**
     * @var helper_plugin_dataau will hold the dataau helper plugin
     */
    var $dthlp = null;

    /**
     * Constructor. Load helper plugin
     */
    function __construct() {
        $this->dthlp = plugin_load('helper', 'dataau');
        if(!$this->dthlp) msg('Loading the dataau helper failed. Make sure the dataau plugin is installed.', -1);
    }

    /**
     * What kind of syntax are we?
     */
    function getType() {
        return 'substition';
    }

    /**
     * What about paragraphs?
     */
    function getPType() {
        return 'block';
    }

    /**
     * Where to sort in?
     */
    function getSort() {
        return 155;
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *dataentry(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+', $mode, 'plugin_dataau_entry');
    }

    /**
     * Handle the match - parse the data
     *
     * @param   string       $match   The text matched by the patterns
     * @param   int          $state   The lexer state for the match
     * @param   int          $pos     The character position of the matched text
     * @param   Doku_Handler $handler The Doku_Handler object
     * @return  bool|array Return an array with all data you want to use in render, false don't add an instruction
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        if(!$this->dthlp->ready()) return null;

        // get lines
        $lines = explode("\n", $match);
        array_pop($lines);
        $class = array_shift($lines);
        $class = str_replace('dataentry', '', $class);
        $class = trim($class, '- ');

        // parse info
        $dataau = array();
        $columns = array();
        foreach($lines as $line) {
            // ignore comments
            preg_match('/^(.*?(?<![&\\\\]))(?:#(.*))?$/', $line, $matches);
            $line = $matches[1];
            $line = str_replace('\\#', '#', $line);
            $line = trim($line);
            if(empty($line)) continue;
            $line = preg_split('/\s*:\s*/', $line, 2);

            $column = $this->dthlp->_column($line[0]);
            if(isset($matches[2])) {
                $column['comment'] = $matches[2];
            }
            if($column['multi']) {
                if(!isset($dataau[$column['key']])) {
                    // init with empty array
                    // Note that multiple occurrences of the field are
                    // practically merged
                    $dataau[$column['key']] = array();
                }
                $vals = explode(',', $line[1]);
                foreach($vals as $val) {
                    $val = trim($this->dthlp->_cleanData($val, $column['type']));
                    if($val == '') continue;
                    if(!in_array($val, $dataau[$column['key']])) {
                        $dataau[$column['key']][] = $val;
                    }
                }
            } else {
                $dataau[$column['key']] = $this->dthlp->_cleanData($line[1], $column['type']);
            }
            $columns[$column['key']] = $column;
        }
        return array(
            'dataau' => $dataau, 'cols' => $columns, 'classes' => $class,
            'pos' => $pos, 'len' => strlen($match)
        ); // not utf8_strlen
    }

    /**
     * Create output or save the data
     *
     * @param   $format   string        output format being rendered
     * @param   $renderer Doku_Renderer the current renderer object
     * @param   $dataau     array         data created by handler()
     * @return  boolean                 rendered correctly?
     */
    function render($format, Doku_Renderer $renderer, $dataau) {
        if(is_null($dataau)) return false;
        if(!$this->dthlp->ready()) return false;

        global $ID;
        switch($format) {
            case 'xhtml':
                /** @var $renderer Doku_Renderer_xhtml */
                $this->_showData($dataau, $renderer);
                return true;
            case 'metadata':
                /** @var $renderer Doku_Renderer_metadata */
                $this->_saveData($dataau, $ID, $renderer->meta['title']);
                return true;
            case 'plugin_dataau_edit':
                /** @var $renderer Doku_Renderer_plugin_dataau_edit */
                $this->_editData($dataau, $renderer);
                return true;
            default:
                return false;
        }
    }

    /**
     * Output the data in a table
     *
     * @param array               $data
     * @param Doku_Renderer_xhtml $R
     */
    function _showData($dataau, $R) {
        global $ID;
        $ret = '';

        $sectionEditData = ['target' => 'plugin_dataau'];
        if (!defined('SEC_EDIT_PATTERN')) {
            // backwards-compatibility for Frusterick Manners (2017-02-19)
            $sectionEditData = 'plugin_dataau';
        }
        $dataau['classes'] .= ' ' . $R->startSectionEdit($dataau['pos'], $sectionEditData);

        $ret .= '<div class="inline dataauplugin_entry ' . $dataau['classes'] . '"><dl>';
        $class_names = array();
        foreach($dataau['dataau'] as $key => $val) {
            if($val == '' || !count($val)) continue;
            $type = $dataau['cols'][$key]['type'];
            if(is_array($type)) {
                $type = $type['type'];
            }
            if($type === 'hidden') continue;

            $class_name = hsc(sectionID($key, $class_names));
            $ret .= '<dt class="' . $class_name . '">' . hsc($dataau['cols'][$key]['title']) . '<span class="sep">: </span></dt>';
            $ret .= '<dd class="' . $class_name . '">';
            if(is_array($val)) {
                $cnt = count($val);
                for($i = 0; $i < $cnt; $i++) {
                    switch($type) {
                        case 'wiki':
                            $val[$i] = $ID . '|' . $val[$i];
                            break;
                    }
                    $ret .= $this->dthlp->_formatData($dataau['cols'][$key], $val[$i], $R);
                    if($i < $cnt - 1) {
                        $ret .= '<span class="sep">, </span>';
                    }
                }
            } else {
                switch($type) {
                    case 'wiki':
                        $val = $ID . '|' . $val;
                        break;
                }
                $ret .= $this->dthlp->_formatData($dataau['cols'][$key], $val, $R);
            }
            $ret .= '</dd>';
        }
        $ret .= '</dl></div>';
        $R->doc .= $ret;
        $R->finishSectionEdit($dataau['len'] + $dataau['pos']);
    }

    /**
     * Save date to the database
     */
    function _saveData($dataau, $id, $title) {
        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return false;

        if(!$title) {
            $title = $id;
        }

        $class = $dataau['classes'];

        // begin transaction
        $sqlite->query("BEGIN TRANSACTION");

        // store page info
        $this->replaceQuery(
            "INSERT OR IGNORE INTO pages (page,title,class) VALUES (?,?,?)",
            $id, $title, $class
        );

        // Update title if insert failed (record already saved before)
        $revision = filemtime(wikiFN($id));
        $this->replaceQuery(
            "UPDATE pages SET title = ?, class = ?, lastmod = ? WHERE page = ?",
            $title, $class, $revision, $id
        );

        // fetch page id
        $res = $this->replaceQuery("SELECT pid FROM pages WHERE page = ?", $id);
        $pid = (int) $sqlite->res2single($res);
        $sqlite->res_close($res);

        if(!$pid) {
            msg("dataau plugin: failed saving data", -1);
            $sqlite->query("ROLLBACK TRANSACTION");
            return false;
        }

        // remove old data
        $sqlite->query("DELETE FROM DATA WHERE pid = ?", $pid);

        // insert new data
        foreach($dataau['dataau'] as $key => $val) {
            if(is_array($val)) foreach($val as $v) {
                $this->replaceQuery(
                    "INSERT INTO DATA (pid, KEY, VALUE) VALUES (?, ?, ?)",
                    $pid, $key, $v
                );
            } else {
                $this->replaceQuery(
                    "INSERT INTO DATA (pid, KEY, VALUE) VALUES (?, ?, ?)",
                    $pid, $key, $val
                );
            }
        }

        // finish transaction
        $sqlite->query("COMMIT TRANSACTION");

        return true;
    }

    /**
     * @return bool|mixed
     */
    function replaceQuery() {
        $args = func_get_args();
        $argc = func_num_args();

        if($argc > 1) {
            for($i = 1; $i < $argc; $i++) {
                $dataau = array();
                $dataau['sql'] = $args[$i];
                $this->dthlp->_replacePlaceholdersInSQL($dataau);
                $args[$i] = $dataau['sql'];
            }
        }

        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return false;

        return call_user_func_array(array(&$sqlite, 'query'), $args);
    }

    /**
     * The custom editor for editing data entries
     *
     * Gets called from action_plugin_dataau::_editform() where also the form member is attached
     *
     * @param array                          $data
     * @param Doku_Renderer_plugin_dataau_edit $renderer
     */
    function _editData($dataau, &$renderer) {
        $renderer->form->startFieldset($this->getLang('dataentry'));
        $renderer->form->_content[count($renderer->form->_content) - 1]['class'] = 'plugin__dataau';
        $renderer->form->addHidden('range', '0-0'); // Adora Belle bugfix

        if($this->getConf('edit_content_only')) {
            $renderer->form->addHidden('dataau_edit[classes]', $dataau['classes']);

            $columns = array('title', 'value', 'comment');
            $class = 'edit_content_only';

        } else {
            $renderer->form->addElement(form_makeField('text', 'dataau_edit[classes]', $dataau['classes'], $this->getLang('class'), 'dataau__classes'));

            $columns = array('title', 'type', 'multi', 'value', 'comment');
            $class = 'edit_all_content';

            // New line
            $dataau['dataau'][''] = '';
            $dataau['cols'][''] = array('type' => '', 'multi' => false);
        }

        $renderer->form->addElement("<table class=\"$class\">");

        //header
        $header = '<tr>';
        foreach($columns as $column) {
            $header .= '<th class="' . $column . '">' . $this->getLang($column) . '</th>';
        }
        $header .= '</tr>';
        $renderer->form->addElement($header);

        //rows
        $n = 0;
        foreach($dataau['cols'] as $key => $vals) {
            $fieldid = 'dataau_edit[dataau][' . $n++ . ']';
            $content = $vals['multi'] ? implode(', ', $dataau['dataau'][$key]) : $dataau['dataau'][$key];
            if(is_array($vals['type'])) {
                $vals['basetype'] = $vals['type']['type'];
                if(isset($vals['type']['enum'])) {
                    $vals['enum'] = $vals['type']['enum'];
                }
                $vals['type'] = $vals['origtype'];
            } else {
                $vals['basetype'] = $vals['type'];
            }

            if($vals['type'] === 'hidden') {
                $renderer->form->addElement('<tr class="hidden">');
            } else {
                $renderer->form->addElement('<tr>');
            }
            if($this->getConf('edit_content_only')) {
                if(isset($vals['enum'])) {
                    $values = preg_split('/\s*,\s*/', $vals['enum']);
                    if(!$vals['multi']) {
                        array_unshift($values, '');
                    }
                    $content = form_makeListboxField(
                        $fieldid . '[value][]',
                        $values,
                        $dataau['dataau'][$key],
                        $vals['title'],
                        '', '',
                        ($vals['multi'] ? array('multiple' => 'multiple') : array())
                    );
                } else {
                    $classes = 'dataau_type_' . $vals['type'] . ($vals['multi'] ? 's' : '') . ' '
                        . 'dataau_type_' . $vals['basetype'] . ($vals['multi'] ? 's' : '');

                    $attr = array();
                    if($vals['basetype'] == 'date' && !$vals['multi']) {
                        $attr['class'] = 'datepicker';
                    }

                    $content = form_makeField('text', $fieldid . '[value]', $content, $vals['title'], '', $classes, $attr);

                }
                $cells = array(
                    hsc($vals['title']) . ':',
                    $content,
                    '<span title="' . hsc($vals['comment']) . '">' . hsc($vals['comment']) . '</span>'
                );
                foreach(array('multi', 'comment', 'type') as $field) {
                    $renderer->form->addHidden($fieldid . "[$field]", $vals[$field]);
                }
                $renderer->form->addHidden($fieldid . "[title]", $vals['origkey']); //keep key as key, even if title is translated
            } else {
                $check_dataau = $vals['multi'] ? array('checked' => 'checked') : array();
                $cells = array(
                    form_makeField('text', $fieldid . '[title]', $vals['origkey'], $this->getLang('title')), // when editable, always use the pure key, not a title
                    form_makeMenuField(
                        $fieldid . '[type]',
                        array_merge(
                            array(
                                '', 'page', 'nspage', 'title',
                                'img', 'mail', 'url', 'tag', 'wiki', 'dt', 'hidden'
                            ),
                            array_keys($this->dthlp->_aliases())
                        ),
                        $vals['type'],
                        $this->getLang('type')
                    ),
                    form_makeCheckboxField($fieldid . '[multi]', array('1', ''), $this->getLang('multi'), '', '', $check_dataau),
                    form_makeField('text', $fieldid . '[value]', $content, $this->getLang('value')),
                    form_makeField('text', $fieldid . '[comment]', $vals['comment'], $this->getLang('comment'), '', 'dataau_comment', array('readonly' => 1, 'title' => $vals['comment']))
                );
            }

            foreach($cells as $index => $cell) {
                $renderer->form->addElement("<td class=\"{$columns[$index]}\">");
                $renderer->form->addElement($cell);
                $renderer->form->addElement('</td>');
            }
            $renderer->form->addElement('</tr>');
        }

        $renderer->form->addElement('</table>');
        $renderer->form->endFieldset();
    }

    /**
     * Escapes the given value against being handled as comment
     *
     * @todo bad naming
     * @param $txt
     * @return mixed
     */
    public static function _normalize($txt) {
        return str_replace('#', '\#', trim($txt));
    }

    /**
     * Handles the data posted from the editor to recreate the entry syntax
     *
     * @param array $dataau data given via POST
     * @return string
     */
    public static function editToWiki($dataau) {
        $nudataau = array();

        $len = 0; // we check the maximum lenght for nice alignment later
        foreach($dataau['dataau'] as $field) {
            if(is_array($field['value'])) {
                $field['value'] = join(', ', $field['value']);
            }
            $field = array_map('trim', $field);
            if($field['title'] === '') continue;

            $name = syntax_plugin_dataau_entry::_normalize($field['title']);

            if($field['type'] !== '') {
                $name .= '_' . syntax_plugin_dataau_entry::_normalize($field['type']);
            } elseif(substr($name, -1, 1) === 's') {
                $name .= '_'; // when the field name ends in 's' we need to secure it against being assumed as multi
            }
            // 's' is added to either type or name for multi
            if($field['multi'] === '1') {
                $name .= 's';
            }

            $nudataau[] = array($name, syntax_plugin_dataau_entry::_normalize($field['value']), $field['comment']);
            $len = max($len, utf8_strlen($nudataau[count($nudataau) - 1][0]));
        }

        $ret = '---- dataentry ' . trim($dataau['classes']) . ' ----' . DOKU_LF;
        foreach($nudataau as $field) {
            $ret .= $field[0] . str_repeat(' ', $len + 1 - utf8_strlen($field[0])) . ': ';
            $ret .= $field[1];
            if($field[2] !== '') {
                $ret .= ' # ' . $field[2];
            }
            $ret .= DOKU_LF;
        }
        $ret .= "----\n";
        return $ret;
    }
}
