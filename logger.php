<?php
define("STATE_CONNECTED",0);
define("STATE_REGISTERED",1);
define("STATE_JOINED",2);
define("STATE_LISTED;",3);
//define("STATE_",4);
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

function signalHandler($signo)
{
    global $continuer;
    $continuer = false;
    printf("Warning: interrupt received, killing server…%s", PHP_EOL);
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
        newCmd($s);
    }
    $read = array($socket);
}
cmd($socket, "QUIT");

fclose($socket);

function cmd($s, $c){
    echo ">> $c\n";
    fputs($s, "$c\r\n");
}

function traiter($s, $c){
    global $state;
    echo "<< $c";
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
        default:
            break;
        }
    }
}

function newCmd($s){
    global $state;
    if($state == STATE_REGISTERED){
        cmd($s, "LIST");
        $state = STATE_LISTED;
    }
}
