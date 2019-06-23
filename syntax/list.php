<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

/**
 * This inherits from the table syntax, because it's basically the
 * same, just different output
 */
class syntax_plugin_dataau_list extends syntax_plugin_dataau_table {

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *dataaulist(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+', $mode, 'plugin_dataau_list');
    }

    protected $before_item = '<li><div class="li">';
    protected $after_item  = '</div></li>';
    protected $before_val  = '';
    protected $after_val   = ' ';

    /**
     * Before value in listitem
     *
     * @param array $dataau  instructions by handler
     * @param int   $colno column number
     * @return string
     */
    protected function beforeVal(&$dataau, $colno) {
        if($dataau['sepbyheaders'] AND $colno === 0) {
            return $dataau['headers'][$colno];
        } else {
            return $this->before_val;
        }
    }

    /**
     * After value in listitem
     *
     * @param array $data
     * @param int   $colno
     * @return string
     */
    protected function afterVal(&$dataau, $colno) {
        if($dataau['sepbyheaders']) {
            return $dataau['headers'][$colno + 1];
        } else {
            return $this->after_val;
        }
    }

    /**
     * Create list header
     *
     * @param array $clist keys of the columns
     * @param array $dataau  instruction by handler
     * @return string html of table header
     */
    function preList($clist, $dataau) {
        return '<div class="dataaggregation"><ul class="dataauplugin_list ' . $dataau['classes'] . '">';
    }

    /**
     * Create an empty list
     *
     * @param array         $dataau  instruction by handler()
     * @param array         $clist keys of the columns
     * @param Doku_Renderer $R
     */
    function nullList($dataau, $clist, $R) {
        $R->doc .= '<div class="dataaggregation"><p class="dataauplugin_list ' . $dataau['classes'] . '">';
        $R->cdata($this->getLang('none'));
        $R->doc .= '</p></div>';
    }

    /**
     * Create list footer
     *
     * @param array $dataau   instruction by handler()
     * @param int   $rowcnt number of rows
     * @return string html of table footer
     */
    function postList($dataau, $rowcnt) {
        return '</ul></div>';
    }

}
