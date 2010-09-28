<?php
include(dirname(__FILE__) . '/fcsh.inc.php');

fcsh_error_handler_setup();

function usage($extra = '')
{
	$txt = <<<EOD
Usage:
  fcshc.php [--host=host] [--port=port] [--noauto=1] -- <fcsh cmd>
  --host     - fcshd host
  --port     - fcshd port
  --noauto   - don't try to spawn the fcshd daemon automatically
  <fcsh cmd> - command passed to fcsh(see its documentation),
               NOTE: additional mxmlcsmart command is supported, 
                     whicn can be used instead of mxmlc/compile commands 
                     for incremental builds 

EOD;

  if($extra)
    echo "$extra\n";
  echo $txt;
}


$HOST = fcsh_autoguess_host();
$PORT = 8067;
$AUTODAEMON = true;

array_shift($argv);
$idx = array_search('--', $argv, true/*strict*/);
if($idx === false)
{
  usage("No command for fcsh");
  exit(1);
}
$fcsh_args = array_splice($argv, $idx);
array_shift($fcsh_args);

foreach(fcsh_parse_argv($argv) as $key => $value)
{
  switch($key)
  {
    case 'host':
      $HOST = $value;
      break;
    case 'port':
      $PORT = $value;
      break;
    case 'noauto':
      $AUTODAEMON = false;
      break;
  }
}

if($AUTODAEMON)
  fcsh_start_fcshd($HOST, $PORT);

$socket = fcsh_new_socket($HOST, $PORT);

$fcsh_cmd = implode(" ", $fcsh_args);
fcsh_socket_send($socket, "$fcsh_cmd\n");

list($error, $resp) = fcsh_socket_recv($socket);

if($error != 0)
  throw new Exception("fcsh command execution error($error): $resp");

echo $resp;
