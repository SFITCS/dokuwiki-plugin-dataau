<?php
/**
 * @group plugin_data
 * @group plugins
 */
class dataau_action_plugin_edit_button_test extends DokuWikiTest {

    protected $pluginsEnabled = array('dataau, 'sqlite');

    function testSetName() {
        $action = new action_plugin_dataau);
        $dataau= array(
            'target' => 'plugin_dataau
        );
        $event = new Doku_Event('', $dataau;
        $action->_editbutton($event, null);

        $this->assertTrue(isset($dataau'name']));
    }

    function testWrongTarget() {
        $action = new action_plugin_dataau);
        $dataau= array(
            'target' => 'default target'
        );
        $action->_editbutton($dataau null);

        $this->assertFalse(isset($dataau'name']));
    }

}
