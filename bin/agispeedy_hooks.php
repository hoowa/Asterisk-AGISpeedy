<?php
/*
    Agispeedy - The Agispeedy is robust AGI Application Server 
                implemention in asterisk.
    Author Sun bing <hoowa.sun@gmail.com>

    See http://agispeedy.googlecode.com for more information about the 
    Agispeedy project.

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
    MA 02110-1301, USA.


    agispeedy_hooks.php provides a number of "hooks" can be seen in the
    PROCESS FLOW section.

    $CONF = &$GLOBALS['CONF'];  //config files
    $SERVER = &$GLOBALS['SERVER'];  //server variables
    $CLIENT = &$GLOBALS['CLIENT'];  //current session variables


    $Id$
*/

// take to run immediately after server configure loaded
// notice: in server process
function hooks_configure()
{
    /*
        $SERVER = &$GLOBALS['SERVER'];
        utils_message('[DEBUG]['.__FUNCTION__.']: working.',4);
    */
}

// take to run immediately after server socket bind and before server loop
// notice: in server process
function hooks_socket_blind()
{
}

// take to run immediately before server close
// notice: in server process
function hooks_server_close()
{
}

// take to run immediately after server forked children 
// notice: in each children
function hooks_fork_children()
{
    /* 
    Fast Database connect, you can connect database after fork children and register it
    in to $CLIENT variables
    for example:


    $CLIENT = &$GLOBALS['CLIENT'];  //current session variables
    $SERVER = &$GLOBALS['SERVER'];  //server variables

    $link = mysql_connect('localhost', 'root', '', false, MYSQL_CLIENT_INTERACTIVE);
    if (!$link) {
        utils_message('['.__FUNCTION__.']: Cloud not connect '.mysql_error(),1,$SERVER['runmode'],$SERVER['output_level']);
        exit;
    }
    $db_selected = mysql_select_db('test', $link);
    if (!$db_selected) {
        utils_message('['.__FUNCTION__.']: Cloud not use test '.mysql_error(),1,$SERVER['runmode'],$SERVER['output_level']);
        exit;
    }
    utils_message('['.__FUNCTION__.']: Database Connected!',4,$SERVER['runmode'],$SERVER['output_level']);
    $CLIENT['link']=$link;
    */
}

// take to run immediately after new connectio incoming
// notice: in each children
function hooks_connection_accept()
{
}

// take to run immediately after asterisk new request connected
// notice: in each children
function hooks_asterisk_connection()
{
}

// take to run immediately after an asterisk connection close
// notice: in each children
function hooks_connection_close()
{
    /* 
    Fast Database connect, after asterisk disconnect you can immediately to close database link
    for example:

    $CLIENT = &$GLOBALS['CLIENT'];  //current session variables
    $SERVER = &$GLOBALS['SERVER'];  //server variables
    mysql_close($CLIENT['link']);
    $CLIENT['link']=null;
    utils_message('['.__FUNCTION__.']: Database Disconnected!',4,$SERVER['runmode'],$SERVER['output_level']);
    */

}

?>