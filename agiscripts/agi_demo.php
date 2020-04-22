<?php
/*
    agi_demo.php:
    agi scripts demo, asterisk request agi://127.0.0.1/demo,key=value
    agispeedy call function agi_main() in agi_demo.php in agiscripts
    path.

    agi_main() is entry function and your agiscripts must have this.

    $CONF = &$GLOBALS['CONF'];  //config files
    $SERVER = &$GLOBALS['SERVER'];  //server variables
    $CLIENT = &$GLOBALS['CLIENT'];  //current session variables

    $agi is object of agi class
    $agi->input array agi enviroment variables
    $agi->param array agi input params


    $Id$
*/

function agi_main(&$agi)
{
    $agi->answer();
    $agi->hangup();

return(true);
}
?>