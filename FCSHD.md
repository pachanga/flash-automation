# Intro #

**fcshd** is a cross-platform(`*`nix, Windows) convenience wrapper around [fcsh](http://livedocs.adobe.com/flex/3/html/compilers_32.html#190522) shell. It simply passes all arguments to fcsh and returns the execution results.

**fcshd** is written in [PHP](http://php.net) and consists of a simple server and a client.

fcshd supports all fcsh commands and also adds its own **mxmlcsmart** command which can be used instead of **mxmlc/compile** commands. In fcsh one has to initially create the compile target by invoking the **mxmlc** command, remember the compile target id and later use this id with **compile** command for incremental builds. The **mxmlcsmart** command does all of this hassle for you, just use it for painfree incremental builds.

# How it works #

  * frontend utility **fcshc** accepts client requests, passes them to the **fcshd** server. It also automatically starts the fcshd if it's not running.
  * **fcshd** server listens on some port for client requests. It spawns the fcsh process and keeps it's opened communicating with it via pipes.

# Usage #

```
Usage:
  fcshc [--host=host] [--port=port] [--noauto=1] -- <fcsh cmd>
  --host     - fcshd host(127.0.0.1 by default)
  --port     - fcshd port(8067 by default)
  --noauto   - do not try to spawn the fcshd daemon automatically
  <fcsh cmd> - command passed to fcsh(see its documentation),
               NOTE: additional mxmlcsmart command is supported,
                     whicn can be used instead of mxmlc/compile commands
                     for incremental builds
```

Let's execute some simple fcsh command in a batch mode, say, _help_. Try the following in the shell(note, this should work without any changes both for `*`nix and Windows):

```
> fcshc -- help
start  "fcshd"  /MIN php fcshd.php --host=127.0.0.1 --port=8067
List of fcsh commands:
mxmlc arg1 arg2 ...      full compilation and optimization; return a target id
compc arg1 arg2 ...      full SWC compilation
compile id               incremental compilation
clear [id]               clear target(s)
info [id]                display compile target info
quit                     quit
(fcsh)
```

Besides this result, on Windows you should see a separate window titled fcshd spawn and running minimized.

http://efiquest.org/wp-content/uploads/2010/09/fcshd.JPG

This an fcshd server window where you can see all internal fcsh communications. On the second and consequent runs of fcshc the server won't be started again. Almost the same thing happens for `*`nix - the only difference is fcshd running in a hidden screen session(still you can attach to this session using the following command: _screen -r -S fcshd_).

# Drawbacks #

  * requires PHP with sockets extension enabled(on `*`nix sockets are usually available by default, on Windows you have to enable it explicitly in php.ini)
  * written in PHP, while probably Python could be more appropriate for this task. I could write it in Python, it would just take me much longer to implement it and I needed a working solution ASAP)
  * there are probably bugs I’m not aware of yet