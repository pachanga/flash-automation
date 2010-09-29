<?php

function fcsh_error_handler_enable($flag)
{
  global $FCSH_ERROR_HANDLER;
  $FCSH_ERROR_HANDLER = $flag;
}

function fcsh_error_handler($errno, $errstr, $errfile, $errline)
{
  global $FCSH_ERROR_HANDLER;

  if(!$FCSH_ERROR_HANDLER)
    return;

  if($errno == E_STRICT)
    return;

  $err = "Error happened: $errno, $errstr, $errfile, $errline\n";
  throw new Exception($err);
}

function fcsh_error_handler_setup()
{
  fcsh_error_handler_enable(true);
  set_error_handler("fcsh_error_handler");
}

/**
* -e
* -e <value>
* --long-param
* --long-param=<value>
* --long-param <value>
* <value>
*/
function fcsh_parse_argv($params, $noopt = array()) 
{
  $result = array();
  reset($params);
  while (list($tmp, $p) = each($params)) 
  {
    if($p{0} == '-') 
    {
      $pname = substr($p, 1);
      $value = true;
      if($pname{0} == '-') 
      {
        // long-opt (--<param>)
        $pname = substr($pname, 1);
        if(strpos($p, '=') !== false) 
        {
          // value specified inline (--<param>=<value>)
          list($pname, $value) = explode('=', substr($p, 2), 2);
        }
      }
      // check if next parameter is a descriptor or a value
      $nextparm = current($params);
      if(!in_array($pname, $noopt) && $value === true && $nextparm !== false && $nextparm{0} != '-') 
        list($tmp, $value) = each($params);
      $result[$pname] = $value;
    } 
    else
      // param doesn't belong to any option
      $result[] = $p;
  }
  return $result;
}

function fcsh_is_win()
{
  return !(DIRECTORY_SEPARATOR == '/');
}

function fcsh_is_port_busy($port)
{
  if(fcsh_is_win())
  {
    exec('netstat -a', $out, $ret);

    foreach($out as $line)
    {
      $line = trim($line);
      if(!$line)
        continue;
      if(preg_match('~^TCP\s+\S+:(\d+).*LISTENING~', $line, $m))
      {
        if($m[1] == $port)
          return true;
      }
    }
    return false;
  }
  else
  {
    exec('netstat -lnt', $out, $ret);

    foreach($out as $line)
    {
      $line = trim($line);
      if(!$line)
        continue;
      $items = preg_split("/\s+/", $line);
      if($items[0] == "tcp" && preg_match("~\S+:(\d+)~", $items[3], $m) && $items[5] = "LISTEN")
      {
        if($m[1] == $port)
          return true;
      }
    }
    return false;
  }
}

function fcsh_is_port_free($port)
{
  return !fcsh_is_port_busy($port);
}

function fcsh_autoguess_host()
{
  if(fcsh_is_win())
  {
    exec('ipconfig', $out, $ret);
    foreach($out as $line)
    {
      if(preg_match('~\s+IP-.*:\s+(\d+\.\d+\.\d+\.\d+)~', $line, $m))
        return $m[1];
    }
  }
  return "127.0.0.1";
}

function fcsh_new_socket($host, $port)
{
  if(!is_string($host))
    throw new Exception("Bad host '$host'");

  if(!is_numeric($port))
    throw new Exception("Bad port '$port'");

  $sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
  if($sock === false)
    throw new Exception("Could not create a socket\n");

  socket_set_block($sock);

  if(!@socket_connect($sock, $host, $port))
    throw new Exception("Could not connect to host '{$host}' at port '{$port}'\n");

  return $sock;
} 

function fcsh_socket_send($socket, $bytes)
{
  if(!is_resource($socket))
    throw new Exception("Passed socket is not a valid resource");
  $len = strlen($bytes);
  $offset = 0;
  while($offset < $len) 
  {
    $sent = socket_write($socket, substr($bytes, $offset), $len - $offset);
    if($sent === false) 
      throw new Exception('Could not write packet into socket. Socket last error: ' . socket_strerror(socket_last_error($socket)));
    $offset += $sent;
  } 
}

function fcsh_socket_recv($socket, $size)
{
  if(!is_resource($socket))
    throw new Exception("Passed socket is not a valid resource");
  $bytes = '';
  while($size) 
  {
    $read = socket_read($socket, $size);
    if($read === false)
      throw new Exception('Failed read from socket! Socket last error: '.socket_strerror(socket_last_error($socket)));
    else if($read === "") 
      throw new Exception('Failed read from socket! No more data to read.');
    $bytes .= $read;
    $size -= strlen($read);
  }
  return $bytes;
}

function fcsh_socket_recv_response($socket)
{
  if(!is_resource($socket))
    throw new Exception("Passed socket is not a valid resource");

  $size = fcsh_unpack_uint32(fcsh_socket_recv($socket, 4));
  $bytes = fcsh_socket_recv($socket, $size);
  $error_code = fcsh_unpack_uint32(substr($bytes, 0, 4));
  $rest = substr($bytes, 4);

  return array($error_code, $rest);
}

function fcsh_unpack_uint32($str)
{
  $arr = unpack('Nv', $str);
  return ($arr['v'] < 0 ? sprintf('%u', $arr['v'])*1.0 : $arr['v']);
}

function fcsh_pack_uint32($n)
{
  return pack('N', $n);
}

function fcsh_normalize_path($path, $unix=null)
{
  if(is_null($unix))
    $unix = !fcsh_is_win();

  //realpath for some reason processes * character :(
  if(strpos($path, '*') === false && ($real = realpath($path)) !== false)
    $path = $real;

  $slash = ($unix ? "/" : "\\");
  $qslash = preg_quote($slash);
  $path = preg_replace("~(\\\\|/)+~", $slash, $path);
  $path = preg_replace("~$qslash\.($qslash|\$)~", $slash, $path);
  return $path;
}

function fcsh_make_tmp_file_name($file_name)
{
  $meta = stream_get_meta_data(tmpfile());
  if(!isset($meta['uri']))
    throw new Exception("Could not get temp directory name");
  $tmp_dir = dirname($meta['uri']);
  $tmp_file = fcsh_normalize_path("$tmp_dir/$file_name");
  return $tmp_file;
}

function fcsh_start_daemon($cmd, $title = null)
{
  if(fcsh_is_win())
  {
    $cmd = "start " . ($title ? " \"$title\" " : "") . " /MIN $cmd";
    echo "$cmd\n";
    $ret = pclose(popen($cmd, "r"));
    if($ret != 0)
      throw new Exception("Could not startup '$cmd'");
  }
  else
  {
	  $cmd = "screen -d -m " . ($title ? "-S \"$title\" " : "" ) . $cmd;
    echo "$cmd\n";
    system($cmd, $ret);
    if($ret != 0)
      throw new Exception("Could not startup '$cmd'");
  }
}

function fcsh_start_fcshd($host, $port)
{
  if(!fcsh_is_port_busy($port))
  {
    $cwd = getcwd();
    chdir(dirname(__FILE__));
    if(fcsh_is_win())
      fcsh_start_daemon("php fcshd.php --host=$host --port=$port", "fcshd");
    else
      fcsh_start_daemon(realpath("fcshd")  . " --host=$host --port=$port", "fcshd");
    chdir($cwd);
    //giving it some time for a start
    sleep(1);
  }
}
