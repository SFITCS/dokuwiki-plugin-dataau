<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

/**
 * Class syntax_plugin_dataau_table
 */
class syntax_plugin_dataau_table extends DokuWiki_Syntax_Plugin {

    /**
     * will hold the dataau helper plugin
     *
     * @var $dthlp helper_plugin_data
     */
    var $dthlp = null;

    var $sums = array();

    /**
     * Constructor. Load helper plugin
     */
    function __construct() {
        $this->dthlp = plugin_load('helper', 'dataau');
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
        $this->Lexer->addSpecialPattern('----+ *datatable(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+', $mode, 'plugin_dataau_table');
    }

    /**
     * Handle the match - parse the data
     *
     * This parsing is shared between the multiple different output/control
     * syntaxes
     *
     * @param   string       $match   The text matched by the patterns
     * @param   int          $state   The lexer state for the match
     * @param   int          $pos     The character position of the matched text
     * @param   Doku_Handler $handler The Doku_Handler object
     * @return  bool|array Return an array with all data you want to use in render, false don't add an instruction
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        if(!$this->dthlp->ready()) return null;

        // get lines and additional class
        $lines = explode("\n", $match);
        array_pop($lines);
        $class = array_shift($lines);
        $class = preg_replace('/^----+ *dataau[a-z]+/', '', $class);
        $class = trim($class, '- ');

        $dataau = array(
            'classes'       => $class,
            'limit'         => 0,
            'dynfilters'    => false,
            'summarize'     => false,
            'rownumbers'    => (bool) $this->getConf('rownumbers'),
            'sepbyheaders'  => false,
            'headers'       => array(),
            'widths'        => array(),
            'filter'        => array()
        );

        // parse info
        foreach($lines as $line) {
            // ignore comments
            $line = preg_replace('/(?<![&\\\\])#.*$/', '', $line);
            $line = str_replace('\\#', '#', $line);
            $line = trim($line);
            if(empty($line)) continue;
            $line = preg_split('/\s*:\s*/', $line, 2);
            $line[0] = strtolower($line[0]);

            $logic = 'OR';
            // handle line commands (we allow various aliases here)
            switch($line[0]) {
                case 'select':
                case 'cols':
                case 'field':
                case 'col':
                    $cols = explode(',', $line[1]);
                    foreach($cols as $col) {
                        $col = trim($col);
                        if(!$col) continue;
                        $column = $this->dthlp->_column($col);
                        $dataau['cols'][$column['key']] = $column;
                    }
                    break;
                case 'title':
                    $dataau['title'] = $line[1];
                    break;
                case 'head':
                case 'header':
                case 'headers':
                    $cols = $this->parseValues($line[1]);
                    $dataau['headers'] = array_merge($dataau['headers'], $cols);
                    break;
                case 'align':
                    $cols = explode(',', $line[1]);
                    foreach($cols as $col) {
                        $col = trim(strtolower($col));
                        if($col[0] == 'c') {
                            $col = 'center';
                        } elseif($col[0] == 'r') {
                            $col = 'right';
                        } else {
                            $col = 'left';
                        }
                        $dataau['align'][] = $col;
                    }
                    break;
                case 'widths':
                    $cols = explode(',', $line[1]);
                    foreach($cols as $col) {
                        $col = trim($col);
                        $dataau['widths'][] = $col;
                    }
                    break;
                case 'min':
                    $dataau['min'] = abs((int) $line[1]);
                    break;
                case 'limit':
                case 'max':
                    $dataau['limit'] = abs((int) $line[1]);
                    break;
                case 'order':
                case 'sort':
                    $column = $this->dthlp->_column($line[1]);
                    $sort = $column['key'];
                    if(substr($sort, 0, 1) == '^') {
                        $dataau['sort'] = array(substr($sort, 1), 'DESC');
                    } else {
                        $dataau['sort'] = array($sort, 'ASC');
                    }
                    break;
                case 'where':
                case 'filter':
                case 'filterand':
                    /** @noinspection PhpMissingBreakStatementInspection */
                case 'and':
                    $logic = 'AND';
                case 'filteror':
                case 'or':
                    if(!$logic) {
                        $logic = 'OR';
                    }
                    $flt = $this->dthlp->_parse_filter($line[1]);
                    if(is_array($flt)) {
                        $flt['logic'] = $logic;
                        $dataau['filter'][] = $flt;
                    }
                    break;
                case 'page':
                case 'target':
                    $dataau['page'] = cleanID($line[1]);
                    break;
                case 'dynfilters':
                    $dataau['dynfilters'] = (bool) $line[1];
                    break;
                case 'rownumbers':
                    $dataau['rownumbers'] = (bool) $line[1];
                    break;
                case 'summarize':
                    $dataau['summarize'] = (bool) $line[1];
                    break;
                case 'sepbyheaders':
                    $dataau['sepbyheaders'] = (bool) $line[1];
                    break;
                default:
                    msg("dataau plugin: unknown option '" . hsc($line[0]) . "'", -1);
            }
        }

        // we need at least one column to display
        if(!is_array($dataau['cols']) || !count($dataau['cols'])) {
            msg('dataau plugin: no columns selected', -1);
            return null;
        }

        // fill up headers with field names if necessary
        $dataau['headers'] = (array) $dataau['headers'];
        $cnth = count($dataau['headers']);
        $cntf = count($dataau['cols']);
        for($i = $cnth; $i < $cntf; $i++) {
            $column = array_slice($dataau['cols'], $i, 1);
            $columnprops = array_pop($column);
            $dataau['headers'][] = $columnprops['title'];
        }

        $dataau['sql'] = $this->_buildSQL($dataau);

        // Save current request params for comparison in updateSQL
        $dataau['cur_param'] = $this->dthlp->_get_current_param(false);
        return $dataau;
    }

    protected $before_item = '<tr>';
    protected $after_item  = '</tr>';
    protected $before_val  = '<td %s>';
    protected $after_val   = '</td>';

    /**
     * Handles the actual output creation.
     *
     * @param   string        $format output format being rendered
     * @param   Doku_Renderer $R      the current renderer object
     * @param   array         $dataau   data created by handler()
     * @return  boolean               rendered correctly? (however, returned value is not used at the moment)
     */
    function render($format, Doku_Renderer $R, $dataau) {
        if($format != 'xhtml') return false;
        /** @var Doku_Renderer_xhtml $R */

        if(is_null($dataau)) return false;
        if(!$this->dthlp->ready()) return false;
        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return false;

        $R->info['cache'] = false;

        //reset counters
        $this->sums = array();

        if($this->hasRequestFilter() OR isset($_REQUEST['dataauofs'])) {
            $this->updateSQLwithQuery($dataau); // handles request params
        }
        $this->dthlp->_replacePlaceholdersInSQL($dataau);

        // run query
        $clist = array_keys($dataau['cols']);
        $res = $sqlite->query($dataau['sql']);

        $rows = $sqlite->res2arr($res);
        $cnt = count($rows);

        if($cnt === 0) {
            $this->nullList($dataau, $clist, $R);
            return true;
        }

        if($dataau['limit'] && $cnt > $dataau['limit']) {
            $rows = array_slice($rows, 0, $dataau['limit']);
        }

        //build classnames per column
        $classes = array();
        $class_names_cache = array();
        $offset = 0;
        if($dataau['rownumbers']) {
            $offset = 1; //rownumbers are in first column
            $classes[] = $dataau['align'][0] . 'align rownumbers';
        }
        foreach($clist as $index => $col) {
            $class = $dataau['align'][$index + $offset] . 'align';
            $class .= ' ' . hsc(sectionID($col, $class_names_cache));
            $classes[] = $class;
        }

        //start table/list
        $R->doc .= $this->preList($clist, $dataau);

        foreach($rows as $rownum => $row) {
            // build data rows
            $R->doc .= $this->before_item;

            if($dataau['rownumbers']) {
                $R->doc .= sprintf($this->before_val, 'class="' . $classes[0] . '"');
                $R->doc .= $rownum + 1;
                $R->doc .= $this->after_val;
            }

            foreach(array_values($row) as $num => $cval) {
                $num_rn = $num + $offset;

                $R->doc .= sprintf($this->beforeVal($dataau, $num_rn), 'class="' . $classes[$num_rn] . '"');
                $R->doc .= $this->dthlp->_formatData(
                    $dataau['cols'][$clist[$num]],
                    $cval, $R
                );
                $R->doc .= $this->afterVal($dataau, $num_rn);

                // clean currency symbols
                $nval = str_replace('$€₤', '', $cval);
                $nval = str_replace('/ [A-Z]{0,3}$/', '', $nval);
                $nval = str_replace(',', '.', $nval);
                $nval = trim($nval);

                // summarize
                if($dataau['summarize'] && is_numeric($nval)) {
                    if(!isset($this->sums[$num])) {
                        $this->sums[$num] = 0;
                    }
                    $this->sums[$num] += $nval;
                }

            }
            $R->doc .= $this->after_item;
        }
        $R->doc .= $this->postList($dataau, $cnt);

        return true;
    }

    /**
     * Before value in table cell
     *
     * @param array $dataau  instructions by handler
     * @param int   $colno column number
     * @return string
     */
    protected function beforeVal(&$dataau, $colno) {
        return $this->before_val;
    }

    /**
     * After value in table cell
     *
     * @param array $data
     * @param int   $colno
     * @return string
     */
    protected function afterVal(&$dataau, $colno) {
        return $this->after_val;
    }

    /**
     * Create table header
     *
     * @param array $clist keys of the columns
     * @param array $dataau  instruction by handler
     * @return string html of table header
     */
    function preList($clist, $dataau) {
        global $ID;
        global $conf;

        // Save current request params to not loose them
        $cur_params = $this->dthlp->_get_current_param();

        //show active filters
        $text = '<div class="table dataaggregation">';
        if(isset($_REQUEST['dataflt'])) {
            $filters = $this->dthlp->_get_filters();
            $fltrs = array();
            foreach($filters as $filter) {
                if(strpos($filter['compare'], 'LIKE') !== false) {
                    if(strpos($filter['compare'], 'NOT') !== false) {
                        $comparator_value = '!~' . str_replace('%', '*', $filter['value']);
                    } else {
                        $comparator_value = '*~' . str_replace('%', '', $filter['value']);
                    }
                    $fltrs[] = $filter['key'] . $comparator_value;
                } else {
                    $fltrs[] = $filter['key'] . $filter['compare'] . $filter['value'];
                }
            }

            $text .= '<div class="filter">';
            $text .= '<h4>' . sprintf($this->getLang('tablefilteredby'), hsc(implode(' & ', $fltrs))) . '</h4>';
            $text .= '<div class="resetfilter">' .
                '<a href="' . wl($ID) . '">' . $this->getLang('tableresetfilter') . '</a>' .
                '</div>';
            $text .= '</div>';
        }
        // build table
        $text .= '<table class="inline dataauplugin_table ' . $dataau['classes'] . '">';
        // build column headers
        $text .= '<tr>';

        if($dataau['rownumbers']) {
            $text .= '<th>#</th>';
        }

        foreach($dataau['headers'] as $num => $head) {
            $ckey = $clist[$num];

            $width = '';
            if(isset($dataau['widths'][$num]) AND $dataau['widths'][$num] != '-') {
                $width = ' style="width: ' . $dataau['widths'][$num] . ';"';
            }
            $text .= '<th' . $width . '>';

            // add sort arrow
            if(isset($dataau['sort']) && $ckey == $dataau['sort'][0]) {
                if($dataau['sort'][1] == 'ASC') {
                    $text .= '<span>&darr;</span> ';
                    $ckey = '^' . $ckey;
                } else {
                    $text .= '<span>&uarr;</span> ';
                }
            }

            // Clickable header for dynamic sorting
            $text .= '<a href="' . wl($ID, array('dataausrt' => $ckey) + $cur_params) .
                '" title="' . $this->getLang('sort') . '">' . hsc($head) . '</a>';
            $text .= '</th>';
        }
        $text .= '</tr>';

        // Dynamic filters
        if($dataau['dynfilters']) {
            $text .= '<tr class="dataflt">';

            if($dataau['rownumbers']) {
                $text .= '<th></th>';
            }

            foreach($dataau['headers'] as $num => $head) {
                $text .= '<th>';
                $form = new Doku_Form(array('method' => 'GET'));
                $form->_hidden = array();
                if(!$conf['userewrite']) {
                    $form->addHidden('id', $ID);
                }

                $key = 'dataflt[' . $dataau['cols'][$clist[$num]]['colname'] . '*~' . ']';
                $val = isset($cur_params[$key]) ? $cur_params[$key] : '';

                // Add current request params
                foreach($cur_params as $c_key => $c_val) {
                    if($c_val !== '' && $c_key !== $key) {
                        $form->addHidden($c_key, $c_val);
                    }
                }

                $form->addElement(form_makeField('text', $key, $val, ''));
                $text .= $form->getForm();
                $text .= '</th>';
            }
            $text .= '</tr>';
        }

        return $text;
    }

    /**
     * Create an empty table
     *
     * @param array         $dataau  instruction by handler()
     * @param array         $clist keys of the columns
     * @param Doku_Renderer $R
     */
    function nullList($dataau, $clist, $R) {
        $R->doc .= $this->preList($clist, $dataau);
        $R->tablerow_open();
        $R->tablecell_open(count($clist), 'center');
        $R->cdata($this->getLang('none'));
        $R->tablecell_close();
        $R->tablerow_close();
        $R->doc .= '</table></div>';
    }

    /**
     * Create table footer
     *
     * @param array $dataau   instruction by handler()
     * @param int   $rowcnt number of rows
     * @return string html of table footer
     */
    function postList($dataau, $rowcnt) {
        global $ID;
        $text = '';
        // if summarize was set, add sums
        if($dataau['summarize']) {
            $text .= '<tr>';
            $len = count($dataau['cols']);

            if($dataau['rownumbers']) $text .= '<td></td>';

            for($i = 0; $i < $len; $i++) {
                $text .= '<td class="' . $dataau['align'][$i] . 'align">';
                if(!empty($this->sums[$i])) {
                    $text .= '∑ ' . $this->sums[$i];
                } else {
                    $text .= '&nbsp;';
                }
                $text .= '</td>';
            }
            $text .= '<tr>';
        }

        // if limit was set, add control
        if($dataau['limit']) {
            $text .= '<tr><th colspan="' . (count($dataau['cols']) + ($dataau['rownumbers'] ? 1 : 0)) . '">';
            $offset = (int) $_REQUEST['dataauofs'];
            if($offset) {
                $prev = $offset - $dataau['limit'];
                if($prev < 0) {
                    $prev = 0;
                }

                // keep url params
                $params = $this->dthlp->_a2ua('dataflt', $_REQUEST['dataflt']);
                if(isset($_REQUEST['dataausrt'])) {
                    $params['dataausrt'] = $_REQUEST['dataausrt'];
                }
                $params['dataauofs'] = $prev;

                $text .= '<a href="' . wl($ID, $params) .
                    '" title="' . $this->getLang('prev') .
                    '" class="prev">' . $this->getLang('prev') . '</a>';
            }

            $text .= '&nbsp;';

            if($rowcnt > $dataau['limit']) {
                $next = $offset + $dataau['limit'];

                // keep url params
                $params = $this->dthlp->_a2ua('dataflt', $_REQUEST['dataflt']);
                if(isset($_REQUEST['dataausrt'])) {
                    $params['dataausrt'] = $_REQUEST['dataausrt'];
                }
                $params['dataauofs'] = $next;

                $text .= '<a href="' . wl($ID, $params) .
                    '" title="' . $this->getLang('next') .
                    '" class="next">' . $this->getLang('next') . '</a>';
            }
            $text .= '</th></tr>';
        }

        $text .= '</table></div>';
        return $text;
    }

    /**
     * Builds the SQL query from the given data
     *
     * @param array &$dataau instruction by handler
     * @return bool|string SQL query or false
     */
    function _buildSQL(&$dataau) {
        $cnt = 0;
        $tables = array();
        $select = array();
        $from = '';

        $from2 = '';
        $where2 = '1 = 1';

        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return false;

        // prepare the columns to show
        foreach($dataau['cols'] as &$col) {
            $key = $col['key'];
            if($key == '%pageid%') {
                // Prevent stripping of trailing zeros by forcing a CAST
                $select[] = '" " || pages.page';
            } elseif($key == '%class%') {
                // Prevent stripping of trailing zeros by forcing a CAST
                $select[] = '" " || pages.class';
            } elseif($key == '%lastmod%') {
                $select[] = 'pages.lastmod';
            } elseif($key == '%title%') {
                $select[] = "pages.page || '|' || pages.title";
            } else {
                if(!isset($tables[$key])) {
                    $tables[$key] = 'T' . (++$cnt);
                    $from .= ' LEFT JOIN dataau AS ' . $tables[$key] . ' ON ' . $tables[$key] . '.pid = W1.pid';
                    $from .= ' AND ' . $tables[$key] . ".key = " . $sqlite->quote_string($key);
                }
                $type = $col['type'];
                if(is_array($type)) {
                    $type = $type['type'];
                }
                switch($type) {
                    case 'pageid':
                    case 'wiki':
                        //note in multivalued case: adds pageid only to first value
                        $select[] = "pages.page || '|' || group_concat(" . $tables[$key] . ".value,'\n')";
                        break;
                    default:
                        // Prevent stripping of trailing zeros by forcing a CAST
                        $select[] = 'group_concat(" " || ' . $tables[$key] . ".value,'\n')";
                }
            }
        }
        unset($col);

        // prepare sorting
        if(isset($dataau['sort'])) {
            $col = $dataau['sort'][0];

            if($col == '%pageid%') {
                $order = 'ORDER BY pages.page ' . $dataau['sort'][1];
            } elseif($col == '%class%') {
                $order = 'ORDER BY pages.class ' . $dataau['sort'][1];
            } elseif($col == '%title%') {
                $order = 'ORDER BY pages.title ' . $dataau['sort'][1];
            } elseif($col == '%lastmod%') {
                $order = 'ORDER BY pages.lastmod ' . $dataau['sort'][1];
            } else {
                // sort by hidden column?
                if(!$tables[$col]) {
                    $tables[$col] = 'T' . (++$cnt);
                    $from .= ' LEFT JOIN dataau AS ' . $tables[$col] . ' ON ' . $tables[$col] . '.pid = W1.pid';
                    $from .= ' AND ' . $tables[$col] . ".key = " . $sqlite->quote_string($col);
                }

                $order = 'ORDER BY ' . $tables[$col] . '.value ' . $dataau['sort'][1];
            }
        } else {
            $order = 'ORDER BY 1 ASC';
        }

        // may be disabled from config. as it decreases performance a lot
        $use_dataresolve = $this->getConf('use_dataresolve');

        // prepare filters
        $cnt = 0;
        if(is_array($dataau['filter']) && count($dataau['filter'])) {

            foreach($dataau['filter'] as $filter) {
                $col = $filter['key'];
                $closecompare = ($filter['compare'] == 'IN(' ? ')' : '');

                if($col == '%pageid%') {
                    $where2 .= " " . $filter['logic'] . " pages.page " . $filter['compare'] . " '" . $filter['value'] . "'" . $closecompare;
                } elseif($col == '%class%') {
                    $where2 .= " " . $filter['logic'] . " pages.class " . $filter['compare'] . " '" . $filter['value'] . "'" . $closecompare;
                } elseif($col == '%title%') {
                    $where2 .= " " . $filter['logic'] . " pages.title " . $filter['compare'] . " '" . $filter['value'] . "'" . $closecompare;
                } elseif($col == '%lastmod%') {
                    # parse value to int?
                    $filter['value'] = (int) strtotime($filter['value']);
                    $where2 .= " " . $filter['logic'] . " pages.lastmod " . $filter['compare'] . " " . $filter['value'] . $closecompare;
                } else {
                    // filter by hidden column?
                    $table = 'T' . (++$cnt);
                    $from2 .= ' LEFT JOIN dataau AS ' . $table . ' ON ' . $table . '.pid = pages.pid';
                    $from2 .= ' AND ' . $table . ".key = " . $sqlite->quote_string($col);

                    // apply dataau resolving?
                    if($use_dataresolve && $filter['colname'] && (substr($filter['compare'], -4) == 'LIKE')) {
                        $where2 .= ' ' . $filter['logic'] . ' DATARESOLVE(' . $table . '.value,\'' . $sqlite->escape_string($filter['colname']) . '\') ' . $filter['compare'] .
                            " '" . $filter['value'] . "'"; //value is already escaped
                    } else {
                        $where2 .= ' ' . $filter['logic'] . ' ' . $table . '.value ' . $filter['compare'] .
                            " '" . $filter['value'] . "'" . $closecompare; //value is already escaped
                    }
                }
            }
        }

        // build the query
        $sql = "SELECT " . join(', ', $select) . "
                FROM (
                    SELECT DISTINCT pages.pid AS pid
                    FROM pages $from2
                    WHERE $where2
                ) AS W1
                $from
                LEFT JOIN pages ON W1.pid=pages.pid
                GROUP BY W1.pid
                $order";

        // offset and limit
        if($dataau['limit']) {
            $sql .= ' LIMIT ' . ($dataau['limit'] + 1);
            // offset is added from REQUEST params in updateSQLwithQuery
        }

        return $sql;
    }

    /**
     * Handle request paramaters, rebuild sql when needed
     *
     * @param array $dataau instruction by handler()
     */
    function updateSQLwithQuery(&$dataau) {
        if($this->hasRequestFilter()) {
            if(isset($_REQUEST['dataausrt'])) {
                if($_REQUEST['dataausrt']{0} == '^') {
                    $dataau['sort'] = array(substr($_REQUEST['dataausrt'], 1), 'DESC');
                } else {
                    $dataau['sort'] = array($_REQUEST['dataausrt'], 'ASC');
                }
            }

            // add request filters
            $dataau['filter'] = array_merge($dataau['filter'], $this->dthlp->_get_filters());

            // Rebuild SQL FIXME do this smarter & faster
            $dataau['sql'] = $this->_buildSQL($dataau);
        }

        if($dataau['limit'] && (int) $_REQUEST['dataauofs']) {
            $dataau['sql'] .= ' OFFSET ' . ((int) $_REQUEST['dataauofs']);
        }
    }

    /**
     * Check whether a sort or filter request parameters are available
     *
     * @return bool
     */
    function hasRequestFilter() {
        return isset($_REQUEST['dataausrt']) || isset($_REQUEST['dataflt']);
    }

    /**
     * Split values at the commas,
     * - Wrap with quotes to escape comma, quotes escaped by two quotes
     * - Within quotes spaces are stored.
     *
     * @param string $line
     * @return array
     */
    protected function parseValues($line) {
        $values = array();
        $inQuote = false;
        $escapedQuote = false;
        $value = '';

        $len = strlen($line);
        for($i = 0; $i < $len; $i++) {
            if($line{$i} == '"') {
                if($inQuote) {
                    if($escapedQuote) {
                        $value .= '"';
                        $escapedQuote = false;
                        continue;
                    }
                    if($line{$i + 1} == '"') {
                        $escapedQuote = true;
                        continue;
                    }
                    array_push($values, $value);
                    $inQuote = false;
                    $value = '';
                    continue;

                } else {
                    $inQuote = true;
                    $value = ''; //don't store stuff before the opening quote
                    continue;
                }
            } else if($line{$i} == ',') {
                if($inQuote) {
                    $value .= ',';
                    continue;
                } else {
                    if(strlen($value) < 1) {
                        continue;
                    }
                    array_push($values, trim($value));
                    $value = '';
                    continue;
                }
            }

            $value .= $line{$i};
        }
        if(strlen($value) > 0) {
            array_push($values, trim($value));
        }
        return $values;
    }
}

