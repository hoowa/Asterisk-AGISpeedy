#!/usr/bin/php
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
*/


/*-------------------------------------------------------------------------
  PHP Enviroment sets
-------------------------------------------------------------------------*/
error_reporting(E_ALL); //Everything report
set_time_limit(0);  //Tuneoff time limit
ob_implicit_flush();    //flash
declare(ticks = 1);    //fix som bugs with SIG

// check php version > 5.0.0
$PHPVERSION = explode('.', PHP_VERSION);
if (($PHPVERSION[0] * 10000 + $PHPVERSION[1] * 100 + $PHPVERSION[2]) < 50300) {
    print "Exception : PHP VERSION MUST > 5.3.0\n";
    exit;
}

/*-------------------------------------------------------------------------
  Basic variables
-------------------------------------------------------------------------*/
define('AST_STATE_DOWN', 0);
define('AST_STATE_RESERVED', 1);
define('AST_STATE_OFFHOOK', 2);
define('AST_STATE_DIALING', 3);
define('AST_STATE_RING', 4);
define('AST_STATE_RINGING', 5);
define('AST_STATE_UP', 6);
define('AST_STATE_BUSY', 7);
define('AST_STATE_DIALING_OFFHOOK', 8);
define('AST_STATE_PRERING', 9);

$VERSION = '1.6';
$CONF = null;       //config file
$SERVER = array();  //server variable
$CLIENT = array();  //client variable
$CHILDRENS = array();  //children lists
$CHILDRENIDLE_SHMID = null;  //idle
$CHILDRENIDLE_LOCKER = null; //idle locker

$ALLOW_RUN = true;  // tune allow server continue to run
$ALLOW_FORK = true;  // tune allow server continue to fork

$HANDLE_STDERR = fopen("php://stderr","w");    //stderr handle
$HANDLE_LOGFILE = null; //logfile handle

// server config
chdir(dirname(__FILE__));
$SERVER['name'] = basename($_SERVER['SCRIPT_NAME'],'.php');
$SERVER['workdir'] = getcwd();  //work directory
$SERVER['sock'] = null;  // sock handle
$SERVER['pid'] = null;  // pid of main process
$SERVER['cli_args'] = array();
$SERVER['log_file'] = '/tmp/agispeedy.log';  // pid of main process

/*-------------------------------------------------------------------------
  incoming args check
-------------------------------------------------------------------------*/

foreach ($argv as $id=>$each) {
    if ($id===0)
        continue;
    $SERVER['cli_args'][$each]=true;
}

if (isset($SERVER['cli_args']['--verbose'])==false && isset($SERVER['cli_args']['--quiet'])==false) {
    print   wordwrap("AGISPEEDY-PHP version ".$VERSION."\n".
            "Sun bing <hoowa.sun@gmail.com>\n".
            "This is free software, and you are welcome to modify and redistribute it\n".
            "under the GPL version 2 license.\n".
            "This software comes with ABSOLUTELY NO WARRANTY.\n".
            "\n".
            "Usage: ".$_SERVER['SCRIPT_NAME']." [--verbose|--quiet] [msgopt]\n".
            "  --verbose     Service in front and default messages level 4.\n".
            "  --quiet       Service as background default no messages, enable 'msgopt'".
            "                  for save message into '/tmp/agispeedy.log'.\n".
            "msgopt: \n".
            "  --debug       Message Level 4.\n".
            "  --info        Message Level 3.\n".
            "  --notice      Message Level 2.\n".
            "  --warning     Message Level 1.\n".
            "  --error       Message Level 0.\n\n");
    exit;
}

$SERVER['output_level'] = false;

if (isset($SERVER['cli_args']['--verbose'])==true) {
    $SERVER['runmode']=0; // 0 means verbose
    $SERVER['output_level']=4;
} else {
    $SERVER['runmode']=1; // 1 means quiet
}

if (isset($SERVER['cli_args']['--debug'])==true) {
    $SERVER['output_level'] = 4;
} elseif (isset($SERVER['cli_args']['--info'])==true) {
    $SERVER['output_level'] = 3;
} elseif (isset($SERVER['cli_args']['--notice'])==true) {
    $SERVER['output_level'] = 2;
} elseif (isset($SERVER['cli_args']['--warning'])==true) {
    $SERVER['output_level'] = 1;
} elseif (isset($SERVER['cli_args']['--error'])==true) {
    $SERVER['output_level'] = 0;
}

if ($SERVER['output_level'] !== false && $SERVER['runmode'] != 0) {
    $HANDLE_LOGFILE = fopen($SERVER['log_file'],"a");    //logfile handler
}

/*-------------------------------------------------------------------------
  Initilization
-------------------------------------------------------------------------*/
/*
    READ CONF
    find config files : 
    /agispeedy/etc/agispeedy.conf
    /etc/asterisk.conf
    /etc/freeiris/agispeedy.conf
    /etc/asterisk/agispeedy.conf
*/
if (is_file('/agispeedy/etc/agispeedy.conf')==true) {
    $SERVER['config_file'] = '/agispeedy/etc/agispeedy.conf';
    $CONF = parse_ini_file($SERVER['config_file'],true);

} elseif (is_file('/etc/agispeedy.conf')==true) {
    $SERVER['config_file'] = '/etc/agispeedy.conf';
    $CONF = parse_ini_file($SERVER['config_file'],true);

} elseif (is_file($SERVER['workdir'].'/../etc/agispeedy.conf')==true) {
    $SERVER['config_file'] = $SERVER['workdir'].'/../etc/agispeedy.conf';
    $CONF = parse_ini_file($SERVER['config_file'],true);

} elseif (is_file('/etc/asterisk/agispeedy.conf')==true) {
    $SERVER['config_file'] = '/etc/asterisk/agispeedy.conf';
    $CONF = parse_ini_file($SERVER['config_file'],true);

} else {
    utils_message(': Not Found agispeedy.conf service abort.',0,$SERVER['runmode'],$SERVER['output_level']);
    exit;
}

// checking configure
if (is_dir($CONF['general']['agiscripts_path'])==false) {
    utils_message(': agiscripts_path is not exists in agispeedy.conf',0,$SERVER['runmode'],$SERVER['output_level']);
    exit;
}
if (is_dir($CONF['general']['pidfile_path'])==false) {
    utils_message(': pidfile_path is not exists in agispeedy.conf',0,$SERVER['runmode'],$SERVER['output_level']);
    exit;
}
if (is_null($CONF['daemon']['host'])==true) {
    utils_message(': host is null in agispeedy.conf',0,$SERVER['runmode'],$SERVER['output_level']);
    exit;
}
if (is_numeric($CONF['daemon']['port'])==false) {
    utils_message(': port must be in 0-9 digits in agispeedy.conf',0,$SERVER['runmode'],$SERVER['output_level']);
    exit;
}
if (is_numeric($CONF['daemon']['max_idle_servers'])==false || $CONF['daemon']['max_idle_servers'] < 2 || $CONF['daemon']['max_idle_servers'] > 64) {
    utils_message(': max_idle_servers must be in 0-9 digits and must be >= 2 and <= 64 in agispeedy.conf',0,$SERVER['runmode'],$SERVER['output_level']);
    exit;
}
if (is_numeric($CONF['daemon']['max_connections'])==false || $CONF['daemon']['max_connections'] < 4 || $CONF['daemon']['max_connections'] > 4096) {
    utils_message(': max_connections must be in 0-9 digits and must be >= 4 and <= 4096 in agispeedy.conf',0,$SERVER['runmode'],$SERVER['output_level']);
    exit;
}
if ($CONF['daemon']['max_connections'] < $CONF['daemon']['max_idle_servers']) {
    utils_message(': max_connections must be greater than perfork_idle_servers in agispeedy.conf',0,$SERVER['runmode'],$SERVER['output_level']);
    exit;
}

/*-------------------------------------------------------------------------
  Server Runtime
-------------------------------------------------------------------------*/
utils_message(': Agispeedy - AGI ApplicationServer '.$VERSION.' starting...',3,$SERVER['runmode'],$SERVER['output_level']);
server_start(); //start the server
server_loop();  //server looping for services
server_stop(); // cleanup all
exit;


/*-------------------------------------------------------------------------
  Server functions
  perfork and process
-------------------------------------------------------------------------*/
// start the server
function server_start()
{
    $SERVER = &$GLOBALS['SERVER'];
    $CONF = &$GLOBALS['CONF'];

    // run me as background
    if ($SERVER['runmode']==1) {
        $pid = pcntl_fork();
        //fork failed
        if ($pid == -1) {
            utils_message('['.__FUNCTION__.']: fork failure!',0,$SERVER['runmode'],$SERVER['output_level']);
            exit();
        //in parent to close the parent
    	} elseif ($pid)	{
            exit();
        //in child
    	} else {
        }
    }

    // followed now is server main
    // register sig for main server
    pcntl_signal(SIGTERM	, 'utils_sig_main');
    pcntl_signal(SIGINT	    , 'utils_sig_main');
    pcntl_signal(SIGHUP     , 'utils_sig_main');
    pcntl_signal(SIGCHLD    , 'utils_sig_main_chld');

    // pid check and process
    posix_setsid();
    //chdir($SERVER['workdir']);
    umask(0);
    $SERVER['pid']=posix_getpid();

    // Checking myself in pid and locker make
    $SERVER['pid_file']=$CONF['general']['pidfile_path'].'/'.$SERVER['name'].'.pid';
    if (utils_checkpid($SERVER['pid_file'],$SERVER['name']) == true) {
        utils_message('['.__FUNCTION__.']: I was alreadly exists in memory, Please kill the old and try me again!',0,$SERVER['runmode'],$SERVER['output_level']);
        exit;
    }
    if (file_put_contents($SERVER['pid_file'],$SERVER['pid'],LOCK_EX)===false) {
        utils_message('['.__FUNCTION__.']: Write pid file failure, abort.!',0,$SERVER['runmode'],$SERVER['output_level']);
        exit;
    }

    // idle memory and locker
    $GLOBALS['CHILDRENIDLE_LOCKER']='/var/run/'.$SERVER['name'].'.memidle';
    $GLOBALS['CHILDRENIDLE_SHMID'] = utils_mem_idle();

    // Load all hook functions
    if(file_exists($SERVER['workdir']."/agispeedy_hooks.php")) {
        require_once($SERVER['workdir']."/agispeedy_hooks.php");
    }
    if (function_exists('hooks_configure')==true) // run hooks
        hooks_configure();

    socket_open(); //try to open socket

}

// save to stop server and cleanup all
function server_stop()
{
    $SERVER = &$GLOBALS['SERVER'];

    utils_message('['.__FUNCTION__.']: Server stopping...',3,$SERVER['runmode'],$SERVER['output_level']);

    //last hooks
    if (function_exists('hooks_server_close')==true) // run hooks
        hooks_server_close();

    $GLOBALS['ALLOW_RUN'] = false;  // tune allow server continue to run

    // close main socket
	if(is_resource($SERVER['sock']))
        socket_close($SERVER['sock']);

    // cleanup all children
    server_children_cleanup();

    //close memory
    utils_mem_idle_close();

    // close locker stderr
    if (is_resource($GLOBALS['HANDLE_STDERR']))
        fclose($GLOBALS['HANDLE_STDERR']);

    if (is_resource($GLOBALS['HANDLE_LOGFILE']))
        fclose($GLOBALS['HANDLE_LOGFILE']);

    //delete pid
    if (isset($SERVER['pid_file']) && is_file($SERVER['pid_file']))
        unlink($SERVER['pid_file']);
}

// in server: cleanup children
function server_children_cleanup()
{
    $GLOBALS['ALLOW_FORK'] = false;
    foreach ($GLOBALS['CHILDRENS'] as $each_pid=>$value) {
        utils_message('['.__FUNCTION__.']: Children ['.$each_pid.'] terminated...',3,$GLOBALS['SERVER']['runmode'],$GLOBALS['SERVER']['output_level']);
        posix_kill($each_pid,9);
    }
    $GLOBALS['CHILDRENS']=array();
}


// server loop for waitting for incoming
function server_loop()
{
    $SERVER = &$GLOBALS['SERVER'];
    $CONF = &$GLOBALS['CONF'];
    $CHILDRENS = &$GLOBALS['CHILDRENS'];

    // create perfork children loop
    $trymask=0;
    while ($GLOBALS['ALLOW_RUN']==true)
    {

        // does allow fork children?
        if ($GLOBALS['ALLOW_FORK'] == false)
            continue;

        //create
        $idlecount = utils_mem_idle_get();
        for ($ice=$idlecount;$ice<$CONF['daemon']['max_idle_servers'];$ice++) {

            // max of connections
            $children_list_count = count($CHILDRENS);
            if ($children_list_count > $CONF['daemon']['max_connections']) {
                utils_message('['.__FUNCTION__.']: Connection max of limits!',0,$SERVER['runmode'],$SERVER['output_level']);
                break;
            }

            //block all sig before fork children
            pcntl_sigprocmask(SIG_BLOCK,array(SIGTERM,SIGINT,SIGCHLD,SIGHUP));

            // current
            $pid = pcntl_fork();
            if ($pid == -1) {
                utils_message('['.__FUNCTION__.']: Children fork failure!',0,$SERVER['runmode'],$SERVER['output_level']);
                die;
            }
            if ($pid == 0) {//in child

                //in child signal must default and unlock in children
                pcntl_signal(SIGTERM	, SIG_DFL);
                pcntl_signal(SIGINT	    , SIG_DFL);
                pcntl_signal(SIGCHLD    , SIG_DFL);
                pcntl_signal(SIGHUP     , SIG_DFL);
                pcntl_sigprocmask(SIG_UNBLOCK,array(SIGTERM,SIGINT,SIGCHLD,SIGHUP));

                //run children work
                server_children_work();
                exit;

            } else {//in parent

                utils_message('['.__FUNCTION__.']: children '.$pid.' created!',4,$SERVER['runmode'],$SERVER['output_level']);

                //save memory
                utils_mem_idle_inc();

                //recored childrens
                $CHILDRENS[$pid]=null;

                // unblock
                pcntl_sigprocmask(SIG_UNBLOCK,array(SIGTERM,SIGINT,SIGCHLD,SIGHUP));

            }

        }
    


        usleep(10000);
    }

}

// in children
// work wait for apcept
function server_children_work()
{
    $SERVER = &$GLOBALS['SERVER'];
    $CLIENT = &$GLOBALS['CLIENT'];
    $CONF = &$GLOBALS['CONF'];

    $newpid=posix_getpid();
    $CLIENT['pid']=$newpid;
    //$CLIENT['create_time']=time();

    if (function_exists('hooks_fork_children')==true) // run hooks
        hooks_fork_children();

    /*
        maybe php bug.
        flock work with accept will block connect in
    */
    //$locker = fopen($SERVER['pid_file'],'w'); //locker accept
    //flock($locker,LOCK_EX);
    //utils_message('['.__FUNCTION__.']: Waitting for request!',4,$SERVER['runmode'],$SERVER['output_level']);
    //$a = time();

    $connection = @socket_accept($SERVER['sock']);

    //$b = time();
    //flock($locker,LOCK_UN);

    utils_mem_idle_dec();

    if ($connection === false)	{
        usleep(1000); //sleep 0.0001 sec

    // here incoming
    } elseif ($connection > 0)  {
        socket_close($SERVER['sock']);// we have incoming and close main sock
        $CLIENT['sock']=$connection;
        utils_message('['.__FUNCTION__.']: catch one!',3,$SERVER['runmode'],$SERVER['output_level']);

        if (function_exists('hooks_connection_accept')==true) // run hooks
            hooks_connection_accept();

        server_process_connection();

        // after done close client sock
        server_process_exit();
    }
}

// in children
// close and clean and exit children
function server_process_exit()
{
    $SERVER = &$GLOBALS['SERVER'];
    $CLIENT = &$GLOBALS['CLIENT'];
    $CONF = &$GLOBALS['CONF'];

    socket_close($CLIENT['sock']);
    utils_message('['.__FUNCTION__.']: exit!',3,$SERVER['runmode'],$SERVER['output_level']);

    if (function_exists('hooks_connection_close')==true) // run hooks
        hooks_connection_close();

    exit;
}

// in children
// when an request connection now
function server_process_connection()
{
    $SERVER = &$GLOBALS['SERVER'];
    $CLIENT = &$GLOBALS['CLIENT'];
    $CONF = &$GLOBALS['CONF'];

    // AGI OBJECT
    $agi = new agispeedy_agi($CLIENT['sock']);
    $agi->loadenviromentvars();  // enviroments load

    // check request
    if(isset($agi->scriptname) && !empty($agi->scriptname))
    {
        if (function_exists('hooks_asterisk_connection')==true) // run hooks
            hooks_asterisk_connection();
        // try to load
        if (file_exists($CONF['general']['agiscripts_path']."/agi_".$agi->scriptname.".php")==true)  {
            utils_message('['.__FUNCTION__.']: loading agi_'.$agi->scriptname.'.php',3,$SERVER['runmode'],$SERVER['output_level']);
            require($CONF['general']['agiscripts_path']."/agi_".$agi->scriptname.".php");
            if (function_exists('agi_main')==true) {
                agi_main($agi);
            } else {
                $agi->evaluate("HANGUP");
                utils_message('['.__FUNCTION__.']: No Entry function agi_main in agi_'.$agi->scriptname.'.php',1,$SERVER['runmode'],$SERVER['output_level']);
            }

        } else {
            $agi->evaluate("HANGUP");
            utils_message('['.__FUNCTION__.']: agi_'.$agi->scriptname.'.php not found!',1,$SERVER['runmode'],$SERVER['output_level']);
        }

        return(true);
    }

    $agi->evaluate("HANGUP");

return(true);
}



/*-------------------------------------------------------------------------
  Sockets Functions
-------------------------------------------------------------------------*/
// open and create socket
function socket_open()
{
    $SERVER = &$GLOBALS['SERVER'];
    $CONF = &$GLOBALS['CONF'];

    // main socket create
    if(($SERVER['sock'] = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP))===FALSE) {
        utils_message('['.__FUNCTION__.']: Call to socket_create failed to create socket: '.socket_strerror($SERVER['sock']),0,$SERVER['runmode'],$SERVER['output_level']);
        server_stop();
        exit();
    }
    if (!socket_set_option($SERVER['sock'], SOL_SOCKET, SO_REUSEADDR, 1)) {   //not lookup server name in DNS
        utils_message('['.__FUNCTION__.']: Unable to set option on socket: '.socket_strerror($SERVER['sock']),0,$SERVER['runmode'],$SERVER['output_level']);
        server_stop();
        exit();
    }
    if(($ret = @socket_bind($SERVER['sock'], $CONF['daemon']['host'], $CONF['daemon']['port']))===FALSE) {    //bind
        utils_message('['.__FUNCTION__.']: Call to socket_bind failed to bind socket: '.socket_strerror($ret),0,$SERVER['runmode'],$SERVER['output_level']);
        server_stop();
        exit();
    }
    if(($ret = @socket_listen($SERVER['sock'], 256))===FALSE ) {    //listen
        utils_message('['.__FUNCTION__.']: Call to socket listen failed to listen to socket: '.socket_strerror($ret),0,$SERVER['runmode'],$SERVER['output_level']);
        $server_stop();
        exit();
    }
    //socket_set_nonblock($SERVER['sock']);     //perfork close nonblocking
    utils_message('['.__FUNCTION__.']: Services on '.$CONF['daemon']['host'].':'.$CONF['daemon']['port'],3,$SERVER['runmode'],$SERVER['output_level']);

    if (function_exists('hooks_socket_blind')==true) // run hooks
        hooks_socket_blind();
}
//
//// wonderful close socket
//function socket_close_graceful()
//{
//    $SERVER = &$GLOBALS['SERVER'];
//	socket_shutdown($SERVER['sock'], 1);  //remote host yet can read
//	usleep(500);//wait remote host
//	socket_shutdown($SERVER['sock'], 0);//close reading
//	socket_close($SERVER['sock']);//finaly we can free resource
//	$SERVER['sock']=NULL;
//}
//
// read socket response
function socket_read_response($sock,$eof="\012")
{
    $szRead = null;//buffer of read
    $timeout = 60;//timeout read

    if(is_resource($sock)===FALSE)
        return $szRead;

	socket_set_nonblock($sock);
	$iTimeStart	= time(); //begin time
	$iTimeLastRead = 0; //last read time
    $select_tv_usec = 10000; //microseconds 0.001s
	$bFOREVER = true;
	for(;$bFOREVER;)
    {
		if(is_resource($sock)===FALSE) // may be socket disconnect to return
            return $szRead;

        //does this io can read?
        $r = array($sock);
        $w =NULL;
        $e= NULL;
        $vSelect = @socket_select($r, $w, $e, 0,$select_tv_usec);
        if($vSelect===FALSE)// Select Error
        {
            utils_message('['.__FUNCTION__.']: socket_select() failed, reason: '.socket_strerror(socket_last_error()),1,$SERVER['runmode'],$SERVER['output_level']);
            break;
        }

        $iTimeNow = time();//current time
        // select timeouted this time no data
		if($vSelect==0)	{

            //has data and (no $EOF or only $EOF), check timeout 5sec
            if ($iTimeLastRead > 0 && (strpos($szRead, $eof) === false || $szRead == $eof)) {
                if  (($iTimeNow-$iTimeLastRead) > $timeout) {
                    utils_message('['.__FUNCTION__.']: Timed out no more data.',4,$GLOBALS['SERVER']['runmode'],$GLOBALS['SERVER']['output_level']);
                    break;
                }

            //has data and has $EOF, end of read
            } elseif ($iTimeLastRead > 0 && strpos($szRead, $eof) !== false) {
                break;

            //no data and always no data, check timeout 5sec
            } elseif (($iTimeNow-$iTimeStart) > $timeout) {
                utils_message('['.__FUNCTION__.']: Timed out for data.',4,$GLOBALS['SERVER']['runmode'],$GLOBALS['SERVER']['output_level']);
                break;
            }
			continue;
		}// Nothing Happened

        //read
		foreach($r as $rs)
		{
			if (is_resource($rs)===FALSE)
                return $szRead;

			$szReadThis=@socket_read($rs,2048,PHP_BINARY_READ);
			if ($szReadThis===FALSE || strlen($szReadThis)==0){
                $bFOREVER=FALSE;
                break;
            }

            utils_message('['.__FUNCTION__.']: read a bit.',4,$GLOBALS['SERVER']['runmode'],$GLOBALS['SERVER']['output_level']);

            $iTimeLastRead=time();
			$szRead .= str_replace("\015\012","\012",$szReadThis);  //replace \r\n to \n
			break;
		}

    }//End Forever

    //exit direct
    if ($szRead == "HANGUP\012") {
        server_process_exit();
    }

    utils_message('['.__FUNCTION__.']: read ('.strlen($szRead).')bytes end.',3,$GLOBALS['SERVER']['runmode'],$GLOBALS['SERVER']['output_level']);

return $szRead;
}

// send to socket command and read response
function socket_send_command($szCommand,$thisSock,$eof="\012")
{
	$szSocketRead = null;

	if(!is_resource($thisSock))
    	return $szSocketRead;

    utils_message('['.__FUNCTION__.']: Send "'.$szCommand.'"',3,$GLOBALS['SERVER']['runmode'],$GLOBALS['SERVER']['output_level']);

    //write success
	if(@socket_write($thisSock,$szCommand.$eof)!==FALSE) {

        $szSocketRead=socket_read_response($thisSock);  // by the way read from socket response

        utils_message('['.__FUNCTION__.']: Received '.trim($szSocketRead),4,$GLOBALS['SERVER']['runmode'],$GLOBALS['SERVER']['output_level']);

    //write failed
    } else {
        utils_message('['.__FUNCTION__.']: Send failure :'.$szCommand.socket_strerror(socket_last_error()),3,$GLOBALS['SERVER']['runmode'],$GLOBALS['SERVER']['output_level']);

        return $szSocketRead;
    }

return $szSocketRead;
}


/*-------------------------------------------------------------------------
  Utils
-------------------------------------------------------------------------*/
/* output messages
1 messages string
2 msg level number
3 runmode number
4 output level number
*/
function utils_message($msg,$msglevel,$runmode,$outputlevel=false)
{
    //output to verbose
    if ($runmode === 0) {
        $handle = &$GLOBALS['HANDLE_STDERR'];
    } else {
        $handle = &$GLOBALS['HANDLE_LOGFILE'];
    }

    if ($outputlevel !== false && $msglevel <= $outputlevel) {
        $type=null;
        switch($msglevel) {
            case 4	:   $type='DEBUG';break;
            case 3	:	$type='INFO';break;
            case 2 	:   $type='NOTICE';break;
            case 1 	:   $type='WARNING';break;
            case 0 	:   $type='ERROR';break;
        }
        fwrite($handle,"[".$type."][".time().",".posix_getpid()."]".$msg."\n");
    }

}

// check pid is exists
function utils_checkpid($pid,$thisname)
{
	$exists = false;

	if (is_file($pid)==true) {

		$pid_number = `cat $pid`;
		$pid_number = trim($pid_number);

		// if this process is exists?
		if (is_file("/proc/".$pid_number."/cmdline")) {
			$pid_cmdline = `cat /proc/$pid_number/cmdline`;
			$pid_cmdline = trim($pid_cmdline);

			$scriptname = preg_replace("/\//","\\\/",$thisname);

			// if equal name
			if (preg_match("/".$scriptname."/i",$pid_cmdline)) {
				$exists = true;
			// not equal
			} else {
				$exists = false;
			}
		// this process is no exists
		} else {
			$exists = false;
		}
	}

return($exists);
}

/*-------------------------------------------------------------------------
  SIG control
-------------------------------------------------------------------------*/
// for main server sig
function utils_sig_main($sig)
{
    switch($sig) {
        case SIGTERM	:   server_stop();exit();break;
        case SIGINT	    :	server_stop();exit();break;
        case SIGHUP 	:   server_stop();exit();break;
    }
}
// for main server sig chld only
function utils_sig_main_chld()
{
    while (($pid = pcntl_waitpid(-1, $status,WNOHANG)) > 0)
    {
        if (array_key_exists($pid,$GLOBALS['CHILDRENS'])) {
            unset($GLOBALS['CHILDRENS'][$pid]);
        }
    }
}

/*-------------------------------------------------------------------------
  Memory work
-------------------------------------------------------------------------*/
// memory control
// create an memcontrol
function utils_mem_idle()
{
    $hexid = 18600061138;
    $size = 5120;
    $shmid = null;

    // shm for chldidle pid
    for ($i=1;$i<=3;$i++) {
        $shmid = @shm_attach($hexid,$size,0600);
        if ($shmid != true) {
            sleep(1);
        } else {
            break;
        }
    }

    shm_remove($shmid);

    if ($shmid===false) {
        utils_message('['.__FUNCTION__.']: failure to request shm memory.',0,$GLOBALS['SERVER']['runmode'],$GLOBALS['SERVER']['output_level']);
        exit;
    }

    shm_put_var($shmid, 1, 0);

    return($shmid);
}

function utils_mem_idle_get()
{
    $shmid = &$GLOBALS['CHILDRENIDLE_SHMID'];

    $locker = fopen($GLOBALS['CHILDRENIDLE_LOCKER'],'w');

    flock($locker,LOCK_EX);
    $result = shm_get_var($shmid,1);
    flock($locker,LOCK_UN);

    fclose($locker);

    return($result);
}

// + inc
function utils_mem_idle_inc()
{
    $shmid = &$GLOBALS['CHILDRENIDLE_SHMID'];

    $locker = fopen($GLOBALS['CHILDRENIDLE_LOCKER'],'w');

    flock($locker,LOCK_EX);
    $result = shm_get_var($shmid,1);
    $result = $result+1;
    shm_put_var($shmid, 1, $result);
    flock($locker,LOCK_UN);

    fclose($locker);

    return($result);
}

// - dec
function utils_mem_idle_dec()
{
    $shmid = &$GLOBALS['CHILDRENIDLE_SHMID'];

    $locker = fopen($GLOBALS['CHILDRENIDLE_LOCKER'],'w');

    flock($locker,LOCK_EX);
    $result = shm_get_var($shmid,1);
    $result = $result-1;
    shm_put_var($shmid, 1, $result);
    flock($locker,LOCK_UN);

    fclose($locker);

    return($result);
}

// close and remove memory
function utils_mem_idle_close()
{
    $shmid = &$GLOBALS['CHILDRENIDLE_SHMID'];
    shm_remove($shmid);
    shm_detach($shmid);
}

/*-------------------------------------------------------------------------
  AGI Class
-------------------------------------------------------------------------*/
class agispeedy_agi {
    
    var $sock = null;
    var $scriptname = null;
    var $input = array();
    var $param = array();

    function agispeedy_agi($in_sock)
    {
        $this->sock = $in_sock;
    }

    // load enviroments from agi header
    function loadenviromentvars()
    {
        $szSocketRead=socket_read_response($this->sock,"\012\012");  //ENVIROMENT is \n\n end of
        $agienv = $this->envresult2array($szSocketRead);
        $this->input = $agienv[0];
        $this->param = $agienv[1];

        if (isset($this->input['agi_network_script'])==false)
            return(true);

        // fix scriptname if end with ?
        // check params in url mode like asterisk 1.4
        $agi_request = $this->input['agi_network_script'];
        if (strpos($agi_request,'?')!==false) {
            $fullname = explode("?",$agi_request);
            $this->scriptname = $fullname[0];
            //have params
            if (isset($fullname[1])) {
                foreach (explode("&",$fullname[1]) as $each) {
                    $kv = explode("=",$each);
                    if (count($kv) < 1)
                        continue;
                    $kv[0] = trim($kv[0]);
                    if (isset($kv[1])) {
                        $kv[1] = trim($kv[1]);
                        $this->param[$kv[0]]=$kv[1];
                    } else {
                        $this->param[$kv[0]]=null;
                    }
                }
            }
        } else {
		$this->scriptname = $agi_request;
	}

        return(true);
    }

    // AGI ENV VARIABLES TO ARRAY
    function envresult2array($szResultIn)
    {
        $aResultOut=Array();
        $aParams=array();

        $szResultIn	= trim($szResultIn,"\r\n");
        $aResultIn	= explode("\n",$szResultIn);

        foreach($aResultIn as $key => $value)
        {
            $name = substr($value, 0, strpos($value, ':'));
            if (preg_match("/^agi_arg_([0-9]+)/",$name)) {
                $kv = explode('=',trim(substr($value, strpos($value, ':') + 1)));
                $kv[0]=trim($kv[0]);
                if (isset($kv[1])) {
                    $aParams[$kv[0]]=$kv[1];
                } else {
                    $aParams[$kv[0]]=null;
                }
            } else {
                $aResultOut[$name] = trim(substr($value, strpos($value, ':') + 1));
            }
        }

    return (array($aResultOut,$aParams));
    }

    // send an agi send and waitting for receive
    function evaluate($command)
    {

        $response = socket_send_command($command,$this->sock);
        $response = $this->utils_agiresult2array($response);
        return($response);
    }

    // AGI COMMAND RESULT DATA TO ARRAY
    function utils_agiresult2array($szResultIn)
    {
        $code = substr($szResultIn,0,3);
        $chunk = substr($szResultIn,4);
        $result = null;
        $data = null;
        if ($code=='200') {

            $fristspeace = strpos($chunk, " ");
            if ($fristspeace==false) {
                $kv = explode('=',$chunk);
                $kv[1] = trim($kv[1]);
                $result = $kv[1];
            } else {
                $result = substr($chunk,0,$fristspeace);
                $kv = explode('=',$result);
                $kv[1] = trim($kv[1]);
                $result = $kv[1];
                $data = substr($chunk,$fristspeace);
                $data = trim($data);
                $data = ltrim($data,"(");
                $data = rtrim($data,")");
            }

        } else {

            if (isset($string)){
                $result = substr($string,4);
            }

        }

        return(array('code'=>$code, 'result'=>$result, 'data'=>$data));
    }


    /* Answer channel if not already in answer state.
    * @link http://www.voip-info.org/wiki-answer
    * @return array, see evaluate for return information.  ['result'] is 0 on success, -1 on failure.
    */
    function answer()
    {
        return $this->evaluate('ANSWER');
    }


    /* Get the status of the specified channel. If no channel name is specified, return the status of the current channel.
    *
    * @link http://www.voip-info.org/wiki-channel+status
    * @param string $channel
    * @return array, see evaluate for return information. ['data'] contains description.
    */
    function channel_status($channel='')
    {
        $ret = $this->evaluate("CHANNEL STATUS $channel");
        switch($ret['result'])
        {
            case -1: $ret['data'] = trim("There is no channel that matches $channel"); break;
            case AST_STATE_DOWN: $ret['data'] = 'Channel is down and available'; break;
            case AST_STATE_RESERVED: $ret['data'] = 'Channel is down, but reserved'; break;
            case AST_STATE_OFFHOOK: $ret['data'] = 'Channel is off hook'; break;
            case AST_STATE_DIALING: $ret['data'] = 'Digits (or equivalent) have been dialed'; break;
            case AST_STATE_RING: $ret['data'] = 'Line is ringing'; break;
            case AST_STATE_RINGING: $ret['data'] = 'Remote end is ringing'; break;
            case AST_STATE_UP: $ret['data'] = 'Line is up'; break;
            case AST_STATE_BUSY: $ret['data'] = 'Line is busy'; break;
            case AST_STATE_DIALING_OFFHOOK: $ret['data'] = 'Digits (or equivalent) have been dialed while offhook'; break;
            case AST_STATE_PRERING: $ret['data'] = 'Channel has detected an incoming call and is waiting for ring'; break;
            default: $ret['data'] = "Unknown ({$ret['result']})"; break;
        }
        return $ret;
    }

    /**
    * Deletes an entry in the Asterisk database for a given family and key.
    *
    * @link http://www.voip-info.org/wiki-database+del
    * @param string $family
    * @param string $key
    * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise.
    */
    function database_del($family, $key)
    {
        return $this->evaluate("DATABASE DEL \"$family\" \"$key\"");
    }

    /**
    * Deletes a family or specific keytree within a family in the Asterisk database.
    *
    * @link http://www.voip-info.org/wiki-database+deltree
    * @param string $family
    * @param string $keytree
    * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise.
    */
    function database_deltree($family, $keytree='')
    {
        $cmd = "DATABASE DELTREE \"$family\"";
        if($keytree != '') $cmd .= " \"$keytree\"";
        return $this->evaluate($cmd);
    }

    /**
    * Retrieves an entry in the Asterisk database for a given family and key.
    *
    * @link http://www.voip-info.org/wiki-database+get
    * @param string $family
    * @param string $key
    * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 failure. ['data'] holds the value
    */
    function database_get($family, $key)
    {
        return $this->evaluate("DATABASE GET \"$family\" \"$key\"");
    }

    /**
    * Adds or updates an entry in the Asterisk database for a given family, key, and value.
    *
    * @param string $family
    * @param string $key
    * @param string $value
    * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise
    */
    function database_put($family, $key, $value)
    {
        $value = str_replace("\n", '\n', addslashes($value));
        return $this->evaluate("DATABASE PUT \"$family\" \"$key\" \"$value\"");
    }


//    /**
//    * Sets a global variable, using Asterisk 1.6 syntax.
//    *
//    * @link http://www.voip-info.org/wiki/view/Asterisk+cmd+Set
//    *
//    * @param string $pVariable
//    * @param string|int|float $pValue
//    * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise
//    */
//    function set_global_var($pVariable, $pValue)
//    {
//        if (is_numeric($pValue))
//            return $this->agi_exec("Set","{$pVariable}={$pValue},g");
////            return $this->evaluate("Set({$pVariable}={$pValue},g);");
//        else
//            return $this->agi_exec("Set","{$pVariable}=\"{$pValue}\",g");
////            return $this->evaluate("Set({$pVariable}=\"{$pValue}\",g);");
//    }


    /**
    * Sets a variable
    *
    * @link http://www.voip-info.org/wiki/view/set+variable
    *
    * @param string $pVariable
    * @param string|int|float $pValue
    * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 otherwise
    */
    function set_var($pVariable, $pValue)
    {
        if (is_numeric($pValue))
            return $this->agi_exec("Set","{$pVariable}={$pValue}");
//            return $this->evaluate("Set({$pVariable}={$pValue});");
        else
            return $this->agi_exec("Set","{$pVariable}=\"{$pValue}\"");
//            return $this->evaluate("Set({$pVariable}=\"{$pValue}\");");
    }


    /**
    * Executes the specified Asterisk application with given options.
    *
    * @link http://www.voip-info.org/wiki-exec
    * @link http://www.voip-info.org/wiki-Asterisk+-+documentation+of+application+commands
    * @param string $application
    * @param mixed $options
    * @return array, see evaluate for return information. ['result'] is whatever the application returns, or -2 on failure to find application
    */
    function agi_exec($application, $options=null)
    {
        if(is_array($options)) $options = join('|', $options);
        return $this->evaluate("EXEC $application $options");
    }

    /**
    * Plays the given file and receives DTMF data.
    *
    * This is similar to STREAM FILE, but this command can accept and return many DTMF digits,
    * while STREAM FILE returns immediately after the first DTMF digit is detected.
    *
    * Asterisk looks for the file to play in /var/lib/asterisk/sounds by default.
    *
    * If the user doesn't press any keys when the message plays, there is $timeout milliseconds
    * of silence then the command ends. 
    *
    * The user has the opportunity to press a key at any time during the message or the
    * post-message silence. If the user presses a key while the message is playing, the
    * message stops playing. When the first key is pressed a timer starts counting for
    * $timeout milliseconds. Every time the user presses another key the timer is restarted.
    * The command ends when the counter goes to zero or the maximum number of digits is entered,
    * whichever happens first. 
    *
    * If you don't specify a time out then a default timeout of 2000 is used following a pressed
    * digit. If no digits are pressed then 6 seconds of silence follow the message. 
    *
    * If you don't specify $max_digits then the user can enter as many digits as they want. 
    *
    * Pressing the # key has the same effect as the timer running out: the command ends and
    * any previously keyed digits are returned. A side effect of this is that there is no
    * way to read a # key using this command.
    *
    * @example examples/ping.php Ping an IP address
    *
    * @link http://www.voip-info.org/wiki-get+data
    * @param string $filename file to play. Do not include file extension.
    * @param integer $timeout milliseconds
    * @param integer $max_digits
    * @return array, see evaluate for return information. ['result'] holds the digits and ['data'] holds the timeout if present.
    *
    * This differs from other commands with return DTMF as numbers representing ASCII characters.
    */
    function get_data($filename, $timeout=NULL, $max_digits=NULL)
    {
        return $this->evaluate(rtrim("GET DATA $filename $timeout $max_digits"));
    }

    /**
    * Fetch the value of a variable.
    *
    * Does not work with global variables. Does not work with some variables that are generated by modules.
    *
    * @link http://www.voip-info.org/wiki-get+variable
    * @link http://www.voip-info.org/wiki-Asterisk+variables
    * @param string $variable name
    * @param boolean $getvalue return the value only
    * @return array, see evaluate for return information. ['result'] is 0 if variable hasn't been set, 1 if it has. ['data'] holds the value. returns value if $getvalue is TRUE
    */
    function get_variable($variable,$getvalue=FALSE)
    {
        $res=$this->evaluate("GET VARIABLE $variable");

        if($getvalue==FALSE)
          return($res);

        return($res['data']);
    }


    /**
    * Fetch the value of a full variable.
    *
    *
    * @link http://www.voip-info.org/wiki/view/get+full+variable
    * @link http://www.voip-info.org/wiki-Asterisk+variables
    * @param string $variable name
    * @param string $channel channel
    * @param boolean $getvalue return the value only 
    * @return array, see evaluate for return information. ['result'] is 0 if variable hasn't been set, 1 if it has. ['data'] holds the value.  returns value if $getvalue is TRUE
    */
    function get_fullvariable($variable,$channel=FALSE,$getvalue=FALSE)
    {
      if($channel==FALSE){
        $req = $variable;
      } else {
        $req = $variable.' '.$channel;
      }
      
      $res=$this->evaluate('GET VARIABLE FULL '.$req);
      
      if($getvalue==FALSE)
        return($res);
      
      return($res['data']);
      
    }

    /**
    * Hangup the specified channel. If no channel name is given, hang up the current channel.
    *
    * With power comes responsibility. Hanging up channels other than your own isn't something
    * that is done routinely. If you are not sure why you are doing so, then don't.
    *
    * @link http://www.voip-info.org/wiki-hangup
    * @example examples/dtmf.php Get DTMF tones from the user and say the digits
    * @example examples/input.php Get text input from the user and say it back
    * @example examples/ping.php Ping an IP address
    *
    * @param string $channel
    * @return array, see evaluate for return information. ['result'] is 1 on success, -1 on failure.
    */
    function hangup($channel=null)
    {
        if ($channel==null) {
            return $this->evaluate("HANGUP");
        } else {
            return $this->evaluate("HANGUP $channel");
        }
    }

    /**
    * Does nothing.
    *
    * @link http://www.voip-info.org/wiki-noop
    * @return array, see evaluate for return information.
    */
    function noop($string="")
    {
        return $this->evaluate("NOOP \"$string\"");
    }

    /**
    * Receive a character of text from a connected channel. Waits up to $timeout milliseconds for
    * a character to arrive, or infinitely if $timeout is zero.
    *
    * @link http://www.voip-info.org/wiki-receive+char
    * @param integer $timeout milliseconds
    * @return array, see evaluate for return information. ['result'] is 0 on timeout or not supported, -1 on failure. Otherwise 
    * it is the decimal value of the DTMF tone. Use chr() to convert to ASCII.
    */
    function receive_char($timeout=-1)
    {
        return $this->evaluate("RECEIVE CHAR $timeout");
    }

    /**
    * Record sound to a file until an acceptable DTMF digit is received or a specified amount of
    * time has passed. Optionally the file BEEP is played before recording begins.
    *
    * @link http://www.voip-info.org/wiki-record+file
    * @param string $file to record, without extension, often created in /var/lib/asterisk/sounds
    * @param string $format of the file. GSM and WAV are commonly used formats. MP3 is read-only and thus cannot be used.
    * @param string $escape_digits
    * @param integer $timeout is the maximum record time in milliseconds, or -1 for no timeout.
    * @param integer $offset to seek to without exceeding the end of the file.
    * @param boolean $beep
    * @param integer $silence number of seconds of silence allowed before the function returns despite the 
    * lack of dtmf digits or reaching timeout.
    * @return array, see evaluate for return information. ['result'] is -1 on error, 0 on hangup, otherwise a decimal value of the 
    * DTMF tone. Use chr() to convert to ASCII.
    */
    function record_file($file, $format, $escape_digits='', $timeout=-1, $offset=NULL, $beep=false, $silence=NULL)
    {
        $cmd = trim("RECORD FILE $file $format \"$escape_digits\" $timeout $offset");
        if($beep) $cmd .= ' BEEP';
        if(!is_null($silence)) $cmd .= " s=$silence";
        return $this->evaluate($cmd);
    }

    /**
    * Say the given digit string, returning early if any of the given DTMF escape digits are received on the channel.
    *
    * @link http://www.voip-info.org/wiki-say+digits
    * @param integer $digits
    * @param string $escape_digits
    * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no 
    * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
    */
    function say_digits($digits, $escape_digits='')
    {
        return $this->evaluate("SAY DIGITS $digits \"$escape_digits\"");
    }

    /**
    * Say the given number, returning early if any of the given DTMF escape digits are received on the channel.
    *
    * @link http://www.voip-info.org/wiki-say+number
    * @param integer $number
    * @param string $escape_digits
    * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no 
    * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
    */
    function say_number($number, $escape_digits='')
    {
        return $this->evaluate("SAY NUMBER $number \"$escape_digits\"");
    }

    /**
    * Say the given character string, returning early if any of the given DTMF escape digits are received on the channel.
    *
    * @link http://www.voip-info.org/wiki-say+phonetic
    * @param string $text
    * @param string $escape_digits
    * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no 
    * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
    */
    function say_phonetic($text, $escape_digits='')
    {
        return $this->evaluate("SAY PHONETIC $text \"$escape_digits\"");
    }

    /**
    * Say a given time, returning early if any of the given DTMF escape digits are received on the channel.
    *
    * @link http://www.voip-info.org/wiki-say+time
    * @param integer $time number of seconds elapsed since 00:00:00 on January 1, 1970, Coordinated Universal Time (UTC).
    * @param string $escape_digits
    * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no 
    * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
    */
    function say_time($time=NULL, $escape_digits='')
    {
        if(is_null($time)) $time = time();
        return $this->evaluate("SAY TIME $time \"$escape_digits\"");
    }

    /**
    * Send the specified image on a channel.
    *
    * Most channels do not support the transmission of images.
    *
    * @link http://www.voip-info.org/wiki-send+image
    * @param string $image without extension, often in /var/lib/asterisk/images
    * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if the image is sent or 
    * channel does not support image transmission.
    */
    function send_image($image)
    {
        return $this->evaluate("SEND IMAGE $image");
    }

    /**
    * Send the given text to the connected channel.
    *
    * Most channels do not support transmission of text.
    *
    * @link http://www.voip-info.org/wiki-send+text
    * @param $text
    * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if the text is sent or 
    * channel does not support text transmission.
    */
    function send_text($text)
    {
        return $this->evaluate("SEND TEXT \"$text\"");
    }

    /**
    * Cause the channel to automatically hangup at $time seconds in the future.
    * If $time is 0 then the autohangup feature is disabled on this channel.
    *
    * If the channel is hungup prior to $time seconds, this setting has no effect.
    *
    * @link http://www.voip-info.org/wiki-set+autohangup
    * @param integer $time until automatic hangup
    * @return array, see evaluate for return information.
    */
    function set_autohangup($time=0)
    {
        return $this->evaluate("SET AUTOHANGUP $time");
    }

    /**
    * Changes the caller ID of the current channel.
    *
    * @link http://www.voip-info.org/wiki-set+callerid
    * @param string $cid example: "John Smith"<1234567>
    * This command will let you take liberties with the <caller ID specification> but the format shown in the example above works 
    * well: the name enclosed in double quotes followed immediately by the number inside angle brackets. If there is no name then
    * you can omit it. If the name contains no spaces you can omit the double quotes around it. The number must follow the name
    * immediately; don't put a space between them. The angle brackets around the number are necessary; if you omit them the
    * number will be considered to be part of the name.
    * @return array, see evaluate for return information.
    */
    //function set_callerid($cid)
    //{
    //    return $this->evaluate("SET CALLERID $cid");
    //}

    /**
    * Sets the context for continuation upon exiting the application.
    *
    * Setting the context does NOT automatically reset the extension and the priority; if you want to start at the top of the new 
    * context you should set extension and priority yourself. 
    *
    * If you specify a non-existent context you receive no error indication (['result'] is still 0) but you do get a 
    * warning message on the Asterisk console.
    *
    * @link http://www.voip-info.org/wiki-set+context
    * @param string $context 
    * @return array, see evaluate for return information.
    */
    function set_context($context)
    {
        return $this->evaluate("SET CONTEXT $context");
    }

    /**
    * Set the extension to be used for continuation upon exiting the application.
    *
    * Setting the extension does NOT automatically reset the priority. If you want to start with the first priority of the 
    * extension you should set the priority yourself. 
    *
    * If you specify a non-existent extension you receive no error indication (['result'] is still 0) but you do 
    * get a warning message on the Asterisk console.
    *
    * @link http://www.voip-info.org/wiki-set+extension
    * @param string $extension
    * @return array, see evaluate for return information.
    */
    function set_extension($extension)
    {
        return $this->evaluate("SET EXTENSION $extension");
    }

    /**
    * Enable/Disable Music on hold generator.
    *
    * @link http://www.voip-info.org/wiki-set+music
    * @param boolean $enabled
    * @param string $class
    * @return array, see evaluate for return information.
    */
    function set_music($enabled=true, $class='')
    {
        $enabled = ($enabled) ? 'ON' : 'OFF';
        return $this->evaluate("SET MUSIC $enabled $class");
    }

    /**
    * Set the priority to be used for continuation upon exiting the application.
    *
    * If you specify a non-existent priority you receive no error indication (['result'] is still 0)
    * and no warning is issued on the Asterisk console.
    *
    * @link http://www.voip-info.org/wiki-set+priority
    * @param integer $priority
    * @return array, see evaluate for return information.
    */
    function set_priority($priority)
    {
        return $this->evaluate("SET PRIORITY $priority");
    }

    /**
    * Sets a variable to the specified value. The variables so created can later be used by later using ${<variablename>}
    * in the dialplan.
    *
    * These variables live in the channel Asterisk creates when you pickup a phone and as such they are both local and temporary. 
    * Variables created in one channel can not be accessed by another channel. When you hang up the phone, the channel is deleted 
    * and any variables in that channel are deleted as well.
    *
    * @link http://www.voip-info.org/wiki-set+variable
    * @param string $variable is case sensitive
    * @param string $value
    * @return array, see evaluate for return information.
    */
    function set_variable($variable, $value)
    {
        $value = str_replace("\n", '\n', addslashes($value));
        return $this->evaluate("SET VARIABLE $variable \"$value\"");
    }

    /**
    * Play the given audio file, allowing playback to be interrupted by a DTMF digit. This command is similar to the GET DATA 
    * command but this command returns after the first DTMF digit has been pressed while GET DATA can accumulated any number of 
    * digits before returning.
    *
    * @example examples/ping.php Ping an IP address
    *
    * @link http://www.voip-info.org/wiki-stream+file
    * @param string $filename without extension, often in /var/lib/asterisk/sounds
    * @param string $escape_digits
    * @param integer $offset
    * @return array, see evaluate for return information. ['result'] is -1 on hangup or error, 0 if playback completes with no 
    * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
    */
    function stream_file($filename, $escape_digits='', $offset=0)
    {
        return $this->evaluate("STREAM FILE $filename \"$escape_digits\" $offset");
    }

    /**
    * Enable or disable TDD transmission/reception on the current channel.
    *
    * @link http://www.voip-info.org/wiki-tdd+mode
    * @param string $setting can be on, off or mate
    * @return array, see evaluate for return information. ['result'] is 1 on sucess, 0 if the channel is not TDD capable.
    */
    function tdd_mode($setting)
    {
        return $this->evaluate("TDD MODE $setting");
    }

    /**
    * Sends $message to the Asterisk console via the 'verbose' message system.
    *
    * If the Asterisk verbosity level is $level or greater, send $message to the console.
    *
    * The Asterisk verbosity system works as follows. The Asterisk user gets to set the desired verbosity at startup time or later 
    * using the console 'set verbose' command. Messages are displayed on the console if their verbose level is less than or equal 
    * to desired verbosity set by the user. More important messages should have a low verbose level; less important messages 
    * should have a high verbose level.
    *
    * @link http://www.voip-info.org/wiki-verbose
    * @param string $message
    * @param integer $level from 1 to 4
    * @return array, see evaluate for return information.
    */
    function verbose($message, $level=1)
    {
        foreach(explode("\n", str_replace("\r\n", "\n", print_r($message, true))) as $msg)
        {
          @syslog(LOG_WARNING, $msg);
          $ret = $this->evaluate("VERBOSE \"$msg\" $level");
        }
        return $ret;
    }

    /**
    * Waits up to $timeout milliseconds for channel to receive a DTMF digit.
    *
    * @link http://www.voip-info.org/wiki-wait+for+digit
    * @param integer $timeout in millisecons. Use -1 for the timeout value if you want the call to wait indefinitely.
    * @return array, see evaluate for return information. ['result'] is 0 if wait completes with no 
    * digit received, otherwise a decimal value of the DTMF tone.  Use chr() to convert to ASCII.
    */
    function wait_for_digit($timeout=-1)
    {
        return $this->evaluate("WAIT FOR DIGIT $timeout");
    }


    // AGI to run application

    /**
    * Set absolute maximum time of call.
    *
    * Note that the timeout is set from the current time forward, not counting the number of seconds the call has already been up. 
    * Each time you call AbsoluteTimeout(), all previous absolute timeouts are cancelled. 
    * Will return the call to the T extension so that you can playback an explanatory note to the calling party (the called party 
    * will not hear that)
    *
    * @link http://www.voip-info.org/wiki-Asterisk+-+documentation+of+application+commands
    * @link http://www.dynx.net/ASTERISK/AGI/ccard/agi-ccard.agi
    * @param $seconds allowed, 0 disables timeout
    * @return array, see evaluate for return information.
    */
    function exec_absolutetimeout($seconds=0)
    {
        return $this->agi_exec('AbsoluteTimeout', $seconds);
    }

    /**
    * Executes an AGI compliant application.
    *
    * @param string $command
    * @return array, see evaluate for return information. ['result'] is -1 on hangup or if application requested hangup, or 0 on non-hangup exit.
    * @param string $args
    */
    function exec_agi($command, $args)
    {
        return $this->agi_exec("AGI $command", $args);
    }

    /**
    * Set Language.
    *
    * @param string $language code
    * @return array, see evaluate for return information.
    */
    function exec_setlanguage($language='en')
    {
        return $this->agi_exec('Set', 'CHANNEL(language)='. $language);
    }

    /**
    * Do ENUM Lookup.
    *
    * Note: to retrieve the result, use
    *   get_variable('ENUM');
    *
    * @param $exten
    * @return array, see evaluate for return information.
    */
    function exec_enumlookup($exten)
    {
        return $this->agi_exec('EnumLookup', $exten);
    }

}


?>
