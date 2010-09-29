<?php
include(dirname(__FILE__) . '/fcsh.inc.php');

fcsh_error_handler_setup();

function fcsh_read_until_prompt(fcshProcess $fcsh)
{
  $stream = $fcsh->getStdOut();
  $contents = '';
  while(true)
  {
    $read = array($stream);
    $write = null;
    $except = null;
    $timeout = 5;
    if(stream_select($read, $write, $except, $timeout) < 1)
    {
      usleep(100);
      continue;
    }
    $contents .= fread($stream, 10*1024*1024);
    if(strpos($contents, "(fcsh) ") !== false)
      return $contents;
  }
}

function fcsh_read_errors(fcshProcess $fcsh)
{
  $contents = '';
  $errh = fopen($fcsh->getErrorFile(), 'r');
  if(!$errh)
    throw new Exception("Could not open error file '{$fcsh->getErrorFile()}' for watching");
  fseek($errh, $fcsh->getErrorPos());
  while(!feof($errh))
    $contents .= fread($errh, 8192);
  $fcsh->setErrorPos(ftell($errh));
  return $contents;
}

function fcsh_write_cmd(fcshProcess $fcsh, $cmd)
{
  $stream = $fcsh->getStdIn();
  fwrite($stream, $cmd);
  fflush($stream);
}

function fcsh_exec(fcshProcess $fcsh, $cmd)
{
  fcsh_write_cmd($fcsh, $cmd);
  $result = fcsh_read_until_prompt($fcsh);
  $errors = fcsh_read_errors($fcsh);
  return array($result, $errors);
}

class fcshProcess
{
  private $fcsh;
  private $pipes = array();
  private $error_file = array();
  private $error_pos;

  function __construct($fcsh, array $pipes, $error_file)
  {
    if(!is_resource($fcsh)) 
      throw new Exception("Bad fcsh process");

    $this->fcsh = $fcsh;
    $this->pipes = $pipes;
    $this->error_file = $error_file;
    $this->error_pos = 0;
  }

  function getProc()
  {
    return $this->fcsh;
  }

  function getStdIn()
  {
    return $this->pipes[0];
  }

  function getStdOut()
  {
    return $this->pipes[1];
  }

  function getErrorFile()
  {
    return $this->error_file;
  }

  function getErrorPos()
  {
    return $this->error_pos;
  }

  function setErrorPos($pos)
  {
    $this->error_pos = $pos;
  }
}

function fcsh_new()
{
  $error_file = fcsh_make_tmp_file_name("fcshd.err");
  $descriptorspec = array(
    0 => array("pipe", "r"),  // stdin
    1 => array("pipe", "w"),  // stdout
    2 => array("file", $error_file, "a") // stderr is a file to write to
    //2 => array("pipe", "a") // stderr, don't know how to use properly errors as a pipe :(
  );

  if(is_file($error_file))
    unlink($error_file);

  $cwd = getcwd();
  $fcsh = proc_open('fcsh', $descriptorspec, $pipes, $cwd);
  if(!is_resource($fcsh)) 
    throw new Exception("Could not open fcsh process");

  stream_set_blocking($pipes[1], false);
  //stream_set_blocking($pipes[2], false);

  $fcsh = new fcshProcess($fcsh, $pipes, $error_file);
  //initial read output
  fcsh_read_until_prompt($fcsh);
  return $fcsh;
}

function fcsh_close(fcshProcess $proc)
{
  fclose($proc->getStdIn());
  fclose($proc->getStdOut());
  return proc_close($proc->getProc());
}

class fcshServer
{
  protected $port;
  protected $host;
  protected $sock;
  protected $single_client = false;
  protected $clients = array();
  protected $client_handlers = array();
  protected $client_handler;

  function __construct($host, $port, $single_client = false)
  {
    $this->host = $host;
    $this->port = $port;
    $this->single_client = $single_client;
  }
  
  function __destructor()
  {
    if(is_resource($this->sock))
      socket_close($this->sock);
  }
  
  function setClientHandler($handler)
  {
    if(!is_object($handler))
      $this->client_handler = new $handler;
    else
      $this->client_handler = $handler;      
  }

  function start()
  {
    if(!fcsh_is_port_free($this->port))
      throw new Exception("Port '{$this->port}' seems to be busy");

    // create a streaming socket, of type TCP/IP
    $this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

    // set the option to reuse the port
    if(!socket_set_option($this->sock, SOL_SOCKET, SO_REUSEADDR, 1))
      throw new Exception("Could not set option for socket:" . socket_strerror(socket_last_error()));

    // "bind" the socket to the address to "localhost", on port $port
    // so this means that all connections on this port are now our resposibility to send/recv data, disconnect, etc..
    if(!socket_bind($this->sock, $this->host, $this->port))
      throw new Exception("Could not bind to port '{$this->port}' on host '{this->$host}':" . socket_strerror(socket_last_error()));

    // start listen for connections
    if(!socket_listen($this->sock))
      throw new Exception("Could not start listening on port '{$this->port}':" . socket_strerror(socket_last_error()));

    // create a list of all the clients that will be connected to us..
    // add the listening socket to this list
    $this->clients = array($this->sock);

    echo "FCSHD server listening on port '{$this->port}' of host '{$this->host}'\n";

    while($this->select())
      usleep(1000);
  }

  private function select()
  {
    // create a copy, so $clients doesn't get modified by socket_select()
    $read = $this->clients;

    // get a list of all the clients that have data to be read from
    // if there are no clients with data, go to next iteration
    if(socket_select($read, $write = NULL, $except = NULL, 0) < 1)
      return true;

    // check if there is a client trying to connect
    if(in_array($this->sock, $read)) 
    {
      if($this->single_client)
      {
        echo "Only one client is allowed, removing old clients(if any)\n";
        foreach($this->clients as $key => $sock)
        {
          if($sock == $this->sock)
            continue;          
          socket_close($sock);          
          unset($this->clients[$key]);        
          unset($this->client_handlers[$sock]);          
          unset($read[$key]);
        } 
      }
      
      // accept the client, and add him to the $clients array
      $this->clients[] = $newsock = socket_accept($this->sock);

      $this->onClientConnect($newsock);

      socket_getpeername($newsock, $ip);
      echo "Client '$ip' connected\n";

      // remove the listening socket from the clients-with-data array
      $key = array_search($this->sock, $read);
      unset($read[$key]);
    }

    // loop through all the clients that have data to read from
    foreach($read as $read_sock) 
    {
      // read until 1024 bytes
      // socket_read while show errors when the client is disconnected, so silence the error messages
      fcsh_error_handler_enable(false);
      $data = @socket_read($read_sock, 1024, PHP_BINARY_READ);        
      fcsh_error_handler_enable(true);
      // check if the client is disconnected      
      if($data == "") 
      {
        // remove client for $clients array
        $this->onClientDisconnect($read_sock);
        $idx = array_search($read_sock, $this->clients);
        unset($this->clients[$idx]);
        $ip = null;
        socket_getpeername($read_sock, $ip);
        echo "Client '$ip' disconnected\n";
        // continue to the next client to read from, if any
        continue;
      }

      // check if there is any data
      if(!empty($data)) 
      {       
        // send this to all the clients in the $clients array (except the first one, which is a listening socket)
        foreach($this->clients as $send_sock) 
        {
          // if its the listening sock or the client that we got the message from, go to the next one in the list
          /*if($send_sock == $sock || $send_sock == $read_sock)
            continue;
            */          
          if($send_sock == $this->sock)
            continue;

          //TODO: $data can be read partially thus it should be buffered
          //if($data) $this->buffer[$send_sock] .= $data; //something like this
          $res = $this->onClientData($send_sock, $data);                              
        } // end of broadcast foreach
      }

    } // end of reading foreach
    return true;
  }

  function onClientConnect($socket)
  {    
    if(is_object($this->client_handler))
    {
      $this->client_handlers[$socket] = clone($this->client_handler);
      $this->client_handlers[$socket]->onConnect($socket);
    }
  }

  function onClientDisconnect($socket)
  {
    if(isset($this->client_handlers[$socket]))
      $this->client_handlers[$socket]->onDisconnect($socket);
  }

  function onClientData($socket, $data)
  {
    if(isset($this->client_handlers[$socket]))
      $this->client_handlers[$socket]->onData($socket, $data);
  }
  
  function stop()
  {
    socket_close($this->sock);
  }
} 

class fcshNetHandler
{
  private $fcsh;
  private $on_new_packet;
  private $data;

  function __construct(fcshProcess $fcsh, $on_new_packet)
  {
    $this->fcsh = $fcsh;
    if(!is_callable($on_new_packet))
      throw new Exception("Bad new packet handler");
    $this->on_new_packet = $on_new_packet;

    $this->data = '';
  }

  function onConnect($socket){}

  function onDisconnect($socket){}

  function onData($socket, $data)
  {
    $idx = strpos($data, "\n");
    if($idx !== false)
    {
      $packet = $this->data . substr($data, 0, $idx);
      $this->data = substr($data, $idx);
      call_user_func_array($this->on_new_packet, array($this->fcsh, $packet, $socket));
    }
    else
      $this->data .= $data;
  }
}

$FCSH_COMPILE_TARGETS = array();

function fcsh_process_client_request(fcshProcess $fcsh, $cmd, $socket)
{
  global $FCSH_COMPILE_TARGETS;

  $cmd = trim($cmd);
  $target_hash = null;

  //checking if it's a compile request which can be replaced with an incremental one
  if(strpos($cmd, "mxmlcsmart") === 0)
  {
    $cmd = substr_replace($cmd, "mxmlc", 0, strlen("mxmlcsmart"));
    $target_hash = md5($cmd);

    if(isset($FCSH_COMPILE_TARGETS[$target_hash]))
    {
      $compile_target = $FCSH_COMPILE_TARGETS[$target_hash];
      //rewriting the original cmd
      $cmd = "compile $compile_target";
      $target_hash = null;
    }
  }
  else if(strpos($cmd, "mxmlc") === 0)
    $target_hash = md5($cmd);
  else if(strpos($cmd, "quit") === 0)
  {
    socket_write($socket, "0 quit");
    exit(0);
  }
    
  list($result, $errors) = fcsh_exec($fcsh, "$cmd\n");

  if($target_hash && preg_match("~Assigned (\d+) as the compile target id~", $result, $m))
    $FCSH_COMPILE_TARGETS[$target_hash] = $m[1];

  $error_code = strpos($errors, "Error: ") !== false;

  echo "======= FCSH BEGIN =======\n";
  echo "$cmd\n";
  echo "$result\n";
  echo "$errors\n";
  echo "======= FCSH END =======\n";
  $response = fcsh_pack_uint32($error_code) . "$result\n$errors";
  socket_write($socket, fcsh_pack_uint32(strlen($response)) . $response);
}

$HOST = fcsh_autoguess_host();
$PORT = 8067;

array_shift($argv);
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
  }
}

$fcsh = fcsh_new();
echo "FCSH process opened, errors are appended to '{$fcsh->getErrorFile()}'\n";

$server = new fcshServer($HOST, $PORT);
$handler = new fcshNetHandler($fcsh, 'fcsh_process_client_request');
$server->setClientHandler($handler);
$server->start();

