<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
require_once(dirname(__FILE__) . '/table.php');

/**
 * Class syntax_plugin_dataau_cloud
 */
class syntax_plugin_dataau_cloud extends syntax_plugin_dataau_table {

    /**
     * will hold the dataau helper plugin
     * @var $dthlp helper_plugin_data
     */
    var $dthlp = null;

    /**
     * Constructor. Load helper plugin
     */
    public function __construct() {
        $this->dthlp = plugin_load('helper', 'dataau');
        if(!$this->dthlp) msg('Loading the dataau helper failed. Make sure the dataau plugin is installed.', -1);
    }

    /**
     * What kind of syntax are we?
     */
    public function getType() {
        return 'substition';
    }

    /**
     * What about paragraphs?
     */
    public function getPType() {
        return 'block';
    }

    /**
     * Where to sort in?
     */
    public function getSort() {
        return 155;
    }

    /**
     * Connect pattern to lexer
     */
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *dataaucloud(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+', $mode, 'plugin_dataau_cloud');
    }

    /**
     * Builds the SQL query from the given data
     *
     * @param array &$dataau instruction by handler
     * @return bool|string SQL query or false
     */
    public function _buildSQL(&$dataau) {
        $ckey = array_keys($dataau['cols']);
        $ckey = $ckey[0];

        $from      = ' ';
        $where     = ' ';
        $pagesjoin = '';
        $tables    = array();

        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return false;

        $fields = array(
            'pageid' => 'page',
            'class' => 'class',
            'title' => 'title'
        );
        // prepare filters (no request filters - we set them ourselves)
        if(is_array($dataau['filter']) && count($dataau['filter'])) {
            $cnt = 0;

            foreach($dataau['filter'] as $filter) {
                $col = $filter['key'];
                $closecompare = ($filter['compare'] == 'IN(' ? ')' : '');

                if(preg_match('/^%(\w+)%$/', $col, $m) && isset($fields[$m[1]])) {
                    $where .= " " . $filter['logic'] . " pages." . $fields[$m[1]] .
                        " " . $filter['compare'] . " '" . $filter['value'] . "'" . $closecompare;
                    $pagesjoin = ' LEFT JOIN pages ON pages.pid = dataau.pid';
                } else {
                    // filter by hidden column?
                    if(!$tables[$col]) {
                        $tables[$col] = 'T' . (++$cnt);
                        $from .= ' LEFT JOIN dataau AS ' . $tables[$col] . ' ON ' . $tables[$col] . '.pid = dataau.pid';
                        $from .= ' AND ' . $tables[$col] . ".key = " . $sqlite->quote_string($col);
                    }

                    $where .= ' ' . $filter['logic'] . ' ' . $tables[$col] . '.value ' . $filter['compare'] .
                        " '" . $filter['value'] . "'" . $closecompare; //value is already escaped
                }
            }
        }

        // build query
        $sql = "SELECT dataau.value AS value, COUNT(dataau.pid) AS cnt
                  FROM dataau $from $pagesjoin
                 WHERE dataau.key = " . $sqlite->quote_string($ckey) . "
                 $where
              GROUP BY dataau.value";
        if(isset($dataau['min'])) {
            $sql .= ' HAVING cnt >= ' . $dataau['min'];
        }
        $sql .= ' ORDER BY cnt DESC';
        if($dataau['limit']) {
            $sql .= ' LIMIT ' . $dataau['limit'];
        }

        return $sql;
    }

    protected $before_item = '<ul class="dataauplugin_cloud %s">';
    protected $after_item = '</ul>';
    protected $before_val = '<li class="cl%s">';
    protected $after_val = '</li>';

    /**
     * Create output or save the data
     */
    public function render($format, Doku_Renderer $renderer, $dataau) {
        global $ID;

        if($format != 'xhtml') return false;
        if(is_null($dataau)) return false;
        if(!$this->dthlp->ready()) return false;
        $renderer->info['cache'] = false;

        $sqlite = $this->dthlp->_getDB();
        if(!$sqlite) return false;

        $ckey = array_keys($dataau['cols']);
        $ckey = $ckey[0];

        if(!isset($dataau['page'])) {
            $dataau['page'] = $ID;
        }

        $this->dthlp->_replacePlaceholdersInSQL($dataau);

        // build cloud data
        $res = $sqlite->query($dataau['sql']);
        $rows = $sqlite->res2arr($res);
        $min = 0;
        $max = 0;
        $tags = array();
        foreach($rows as $row) {
            if(!$max) {
                $max = $row['cnt'];
            }
            $min = $row['cnt'];
            $tags[$row['value']]['cnt'] = $row['cnt'];
            $tags[$row['value']]['value'] = $row['value'];
        }
        $this->_cloud_weight($tags, $min, $max, 5);

        // output cloud
        $renderer->doc .= sprintf($this->before_item, hsc($dataau['classes']));
        foreach($tags as $tag) {
            $tagLabelText = hsc($tag['value']);
            if($dataau['summarize'] == 1) {
                $tagLabelText .= '<sub>(' . $tag['cnt'] . ')</sub>';
            }

            $renderer->doc .= sprintf($this->before_val, $tag['lvl']);
            $renderer->doc .= '<a href="' . wl($dataau['page'], $this->dthlp->_getTagUrlparam($dataau['cols'][$ckey], $tag['value'])) .
                              '" title="' . sprintf($this->getLang('tagfilter'), hsc($tag['value'])) .
                              '" class="wikilink1">' . $tagLabelText . '</a>';
            $renderer->doc .= $this->after_val;
        }
        $renderer->doc .= $this->after_item;
        return true;
    }

    /**
     * Create a weighted tag distribution
     *
     * @param $tags array ref The tags to weight ( tag => count)
     * @param $min int      The lowest count of a single tag
     * @param $max int      The highest count of a single tag
     * @param $levels int   The number of levels you want. A 5 gives levels 0 to 4.
     */
    protected function _cloud_weight(&$tags, $min, $max, $levels) {
        $levels--;

        // calculate tresholds
        $tresholds = array();
        for($i = 0; $i <= $levels; $i++) {
            $tresholds[$i] = pow($max - $min + 1, $i / $levels) + $min - 1;
        }

        // assign weights
        foreach($tags as $tag) {
            foreach($tresholds as $tresh => $val) {
                if($tag['cnt'] <= $val) {
                    $tags[$tag['value']]['lvl'] = $tresh;
                    break;
                }
                $tags[$tag['value']]['lvl'] = $levels;
            }
        }

        // sort
        ksort($tags);
    }

}

