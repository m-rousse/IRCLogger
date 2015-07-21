<?php
define("STATE_CONNECTED",0);
define("STATE_REGISTERED",1);
define("STATE_JOINED",2);
define("STATE_LISTED",3);
define("STATE_LISTING",4);
//define("STATE_",5);
//define("STATE_",6);
set_time_limit(0);
declare(ticks=1); // PHP internal, make signal handling work
if (!function_exists('pcntl_signal'))
{
    printf("Error, you need to enable the pcntl extension in your php binary, see http://www.php.net/manual/en/pcntl.installation.php for more info%s", PHP_EOL);
    exit(1);
}

$continuer = 1;
$state = 0;
$chans = array();
$nbChans = 0;
$logDir = "./logs/";

function signalHandler($signo)
{
    global $continuer;
    $continuer = false;
    printf("\rWarning: interrupt received, killing server…%s", PHP_EOL);
}
pcntl_signal(SIGINT, 'signalHandler');

$context = stream_context_create(array(
    'ssl' => array(
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    )
));
$socket = stream_socket_client('ssl://192.168.0.5:6697',$errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);

if(!$socket)
    die("Connexion impossible !\n");

fputs($socket, "NICK Bot\r\n");

$read = array($socket);
$write = NULL;
$except = NULL;

$lastCheck = time();
$lastGen = time();

while($continuer){
    if(($num_streams = stream_select($read, $write, $except, 0)) === false){
        die("C'est cloche ça...\n");
    }elseif($num_streams > 0){
        foreach($read as $s){
            $data = fgets($s, 1024);
            traiter($s, $data);
        }
    }else{
        usleep(100);
        newCmd($socket);
    }
    if(time() - $lastCheck > 30){
        cmd($s, "LUSERS");
        cmd($s, "LIST");
        $lastCheck = time();
    }
    if(time() - $lastGen > 300){
        echo "Generation des historiques !\n";
        $folders = scandir($logDir);
        foreach($folders as $f){
            $cmd = "logs2html $logDir/$f";
            if($f != "." && $f != ".." && is_dir($logDir."/".$f))
                system($cmd);
        }
        $lastGen = time();
    }
    $read = array($socket);
}
cmd($socket, "QUIT");

fclose($socket);

function cmd($s, $c){
    //echo ">> $c\n";
    fputs($s, "$c\r\n");
}

function traiter($s, $c){
    global $state, $chans, $nbChans;
    //echo "<< $c";
    $r = preg_match("/^(?:[:](\\S+) )?(\\S+)(?: (?!:)(.+?))?(?: [:](.+))?$/",$c, $m);
    if($r){
        switch($m[2]){
        case "PING":
            $pong = "PONG :{$m[4]}";
            cmd($s,$pong);
            if($state == STATE_CONNECTED){
                cmd($s, "USER Bot_Logger ids-dev ids-dev Bot_Logger");
                $state = STATE_REGISTERED;
            }
            break;
        case "254":
            $t = explode(" ",$m[3]);
            $nbChans = $t[1];
            break;
        case "322":
            if($state == STATE_JOINED){
                $t = explode(" ",$m[3]);
                if(!isset($chans[$t[1]]))
                    cmd($socket, "JOIN {$t[1]}");
                $chans[$t[1]] = $t[1];
            }else{
                $t = explode(" ",$m[3]);
                $chans[$t[1]] = $t[1];
                if(count($chans) == $nbChans){
                    $state = STATE_LISTED;
                }
            }
            break;
        case "PRIVMSG":
            $dst = $m[3];
            $msg = $m[4];
            $exp = $m[1];
            if(substr($dst, 0,1) == "#")
                logMessage($dst, $exp, $msg);
            else
                processCmd($exp, $msg);
            break;
        case "PART":
            $dst = $m[3];
            $exp = $m[1];
            $user = explode("!",$exp);
            $user = $user[0];
            logMessage($dst, $exp, "*** $user a quitté le chan", 1);
            break;
        case "JOIN":
            $dst = $m[3];
            $exp = $m[1];
            $user = explode("!",$exp);
            $user = $user[0];
            logMessage($dst, $exp, "*** $user est entré dans le chan", 1);
            break;
        default:
            break;
        }
    }
}

function newCmd($s){
    global $state, $chans;
    switch($state){
    case STATE_REGISTERED:
        cmd($s, "LUSERS");
        cmd($s, "LIST");
        $state = STATE_LISTING;
        break;
    case STATE_LISTED:
        foreach($chans as $c)
            cmd($s, "JOIN $c");
        $state = STATE_JOINED;
        break;
    default:
        break;
    }
}

function processCmd($usr, $cmd){
    global $continuer;
    echo "Command taken !\n";
    $c = str_replace("\r","",$cmd);
    var_dump($c);
    switch($c){
    case "Quit":
        $continuer = 0;
        break;
    default:
        break;
    }
}

function logMessage($chan, $usr, $msg, $noUsr = 0){
    global $logDir;
    $user = explode("!",$usr);
    $user = $user[0];
    if(empty($chan))
        $ch = $user;
    else
        $ch = substr($chan, 1);

    if(!is_dir("$logDir/$ch"))
        mkdir($logDir."/$ch");
    $filename = "$ch/".date("Ymd").".log";
    if($noUsr)
        $log = date("Y-m-d\TH:i:s")."  $msg";
    else
        $log = date("Y-m-d\TH:i:s")."  <$user> $msg";
    $log = str_replace("\r","\n",$log);
    file_put_contents($logDir."/".$filename,$log, FILE_APPEND);
    echo "Message logged ! ($filename)\n";
}
