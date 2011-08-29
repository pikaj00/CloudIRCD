<?php

function debug ($str) { fprintf(STDERR,"%s\n",$str); }

include 'libudpmsg4.php';

class poll implements Iterator {
 var $readfds=array();
 var $writefds=array();
 var $alarms=array();
 var $next_alarm_time=NULL;
 var $current;
 var $returns=array();
 var $current_time=NULL;
 function __construct () {}
 function add_readfd ($fd) { $this->readfds[]=$fd; }
 function add_writefd ($fd) { $this->writefds[]=$fd; }
 function add_alarm ($time,$return_this) {
  $this->alarms[]=array($time,$return_this);
  if (($this->next_alarm_time===NULL)||($time<$this->next_alarm_time))
   $this->next_alarm_time=$time;
 }
 function poll () {
  $readfds=$this->readfds;
  $writefds=$this->writefds;
  if (!count($readfds)&&!count($writefds)) return NULL;
  $null=array();
  $this->current_time=microtime(true);
  if ($this->next_alarm_time>$this->current_time) {
   $timediff=$this->next_alarm_time-$this->current_time;
   $timediff_sec=floor($timediff);
   $timediff_usec=$timediff*1000000%1000000;
  } else {
   $timediff_sec=NULL;
   $timediff_usec=NULL;
  }
  $num=stream_select($readfds,$writefds,$null,$timediff_sec,$timediff_usec);
  if ($num===FALSE) return FALSE;
  foreach ($readfds as $readfd) $this->returns[]=array('r',$readfd);
  foreach ($writefds as $writefd) $this->returns[]=array('w',$writefd);
  if (count($this->returns)) return $this->current=array_shift($this->returns);
 }
 function find_next_alarm () {
  $next=NULL;
  foreach ($this->alarms as $alarm)
   if (($next===NULL)||($alarm[0]<$next)) $next=$alarm[0];
  $this->next_alarm_time=$next;
 }
 function next_alarm () {
  if (($this->next_alarm_time!==NULL)&&($this->current_time>$this->next_alarm_time)) {
   foreach ($this->alarms as $key => $alarm)
    if ($alarm[0]===$this->next_alarm_time) {
     $ret=$alarm;
     unset($this->alarms[$key]);
     $this->find_next_alarm();
     return $ret;
    }
  }
  return NULL;
 }
 function next_return () {
  if (($alarm=$this->next_alarm())!==NULL) return $alarm;
  $this->current_time=microtime(true);
  if (($alarm=$this->next_alarm())!==NULL) return $alarm;
  if (count($this->returns)) return $this->current=array_shift($this->returns);
  return $this->poll();
 }
 function rewind () { $this->next(); }
 function next () {
  do { $this->current=$this->next_return(); } while ($this->current===TRUE);
 }
 function valid () { if (!is_array($this->current)) return FALSE; return TRUE; }
 function current () { return $this->current[1]; }
 function key () { return $this->current[0]; }
}

class irc_packet {
 var $prefix;
 var $cmd;
 var $args;
 static function parse (&$data) {
  if (($pos=strpos($data,"\n"))===FALSE) return NULL;
  $line=substr($data,0,$pos);
  $data=substr($data,$pos+1);
  $line=rtrim($line);
  $ret=self::construct($line);
  return $ret;
 }
 static function construct ($line) {
//debug("line=$line;");
  $list=explode(' :',$line);
  $parts=explode(' ',$list[0]);
  if (isset($list[1])) array_push($parts,$list[1]);
  if ($line[0]===':') $prefix=array_shift($parts); else $prefix=NULL;
  $cmd=array_shift($parts);
  return new irc_packet(array('prefix'=>$prefix,'cmd'=>$cmd,'args'=>$parts));
 }
 static function build ($prefix,$cmd,$args=NULL) {
  return new self(array('prefix'=>$prefix,'cmd'=>$cmd,'args'=>$args));
 }
 function __construct ($array) {
  $this->prefix=str_replace(array(' ',"\n","\r"),'',$array['prefix']);
  $this->cmd=str_replace(array(' ',"\n","\r"),'',$array['cmd']);
  $this->args=is_array($array['args'])?$array['args']:array();
  foreach ($this->args as $k=>$arg)
   $this->args[$k]=str_replace(array("\n","\r"),'',$arg);
 }
 function __toString () {
  $parts=array();
  if (isset($this->prefix)) $parts[]=':'.$this->prefix;
  $parts[]=$this->cmd;
  $last=array_pop($this->args);
  $parts=array_merge($parts,$this->args);
  $r=join(' ',$parts);
  if (isset($last)) $r.=' :'.$last;
  return $r;
 }
}

class array_object implements ArrayAccess {
 var $array;
 function __construct ($array) {
  $this->array=$array;
 }
 function offsetExists ($offset) { return array_key_exists($offset,$this->array); }
 function offsetGet ($offset) { return $this->array[$offset]; }
 function offsetSet ($offset,$value) { $this->array[$offset]=$value; }
 function offsetUnset ($offset) { unset($this->array[$offset]); }
}

class cloudircd {
 var $hub,$client;
 var $hub_buffer,$client_buffer;
 var $config=array();
 var $poll;
 var $udpmsg4_client;
 var $hostname;
 static function write_fd ($fd,$data,$tlen=0) {
  if (($len=fwrite($fd,$data))<=0) return $tlen;
  if ($len===strlen($data)) return $tlen+$len;
  return self::write_fd($fd,substr($data,$len),$tlen+$len);
 }
 function write_hub ($data) {
  if (self::write_fd($this->hub[1],$data)!=strlen($data)) return FALSE;
  return TRUE;
 }
 function write_client ($data) {
  if (self::write_fd($this->client[1],$data)!=strlen($data)) return FALSE;
  return TRUE;
 }
 static function construct ($hub_r,$hub_w,$client_r,$client_w,$config=NULL) {
  $new = new cloudircd;
  $new->hub=array($hub_r,$hub_w);
  $new->client=array($client_r,$client_w);
  if ($config!==NULL) $new->config=$config; else $new->config=array();
  if (!isset($new->config['udpmsg4_client']))
   $new->config['udpmsg4_client']=array();
  if (!isset($new->config['udpmsg4_client']['seckey'])) return FALSE;
  if (!isset($new->config['udpmsg4_client']['pubkey'])) return FALSE;
  if (!isset($new->config['udpmsg4_client']['netname']))
   if (!isset($new->config['ircnet'])) return FALSE;
   else $new->config['udpmsg4_client']['netname']=$new->config['ircnet'];
  if (!isset($new->config['ircnet']))
   $new->config['ircnet']=$new->config['udpmsg4_client']['netname'];
  if (!isset($new->config['hostname']))
   $new->config['hostname']='cloudircd';
  $new->hostname=$new->config['hostname'];
  $new->udpmsg4_client = new udpmsg4_client($new->config['udpmsg4_client']);
  if (!$new->irc_intro()) return FALSE;
  $new->poll = new poll;
  $new->poll->add_readfd($hub_r);
  $new->poll->add_readfd($client_r);
  if (!isset($new->config['channels'])) $new->config['channels']=array();
  return $new;
 }
/*
 static function buffered_parse (&$buffer,$func,$fd=NULL,$trymore=0) {
  $p=call_user_func($func,&$buffer);
  if ($p===FALSE) return FALSE;
  else if ($p===NULL);
  else return $p;
  if ($fd===NULL) return NULL;
  $data=fread($fd,4096);
  if ($data===FALSE) return FALSE;
  $buffer.=$data;
  $len=0;
  for ($olen=strlen($buffer); $len<$olen; $len=strlen($buffer)) {
   $p=call_user_func($func,$buffer);
   if ($p===FALSE) return FALSE;
   else if ($p===NULL)
    if (!$trymore) return NULL;
    else return self::buffered_parse($buffer,$func,$fd,$trymore);
   else return $p;
  }
die("This is reached if strlen(\$buffer)===0 that is EOF.\n");
  return $p;
 }
*/
 static function irc_parse (&$buffer,$fd=NULL,$trymore=0) {
  $p=irc_packet::parse($buffer);
  if ($p===FALSE) return FALSE;
  else if ($p===NULL);
  else return $p;
  if ($fd===NULL) return NULL;
  $data=fread($fd,4096);
  if ($data===FALSE) return FALSE;
  $buffer.=$data;
  $len=0;
  for ($olen=strlen($buffer); $len<$olen; $len=strlen($buffer)) {
   $p=irc_packet::parse($buffer);
   if ($p===FALSE) return FALSE;
   else if ($p===NULL)
    if (!$trymore) return NULL;
    else return self::irc_parse($buffer,$func,$fd,$trymore);
   else return $p;
  }
die("This is reached if strlen(\$buffer)===0 that is EOF.\n");
  return $p;
  return self::buffered_parse($buffer,array('irc_packet','parse'),$fd,$trymore);
 }
 function udpmsg4_parse (&$buffer,$fd=NULL,$trymore=0) {
  $p=$this->udpmsg4_client->parse_framed($buffer);
  if ($p===FALSE) return FALSE;
  else if ($p===NULL);
  else return $this->udpmsg4_client->read_compat($p);
  if ($fd===NULL) return NULL;
  $data=fread($fd,4096);
  if ($data===FALSE) return FALSE;
  $buffer.=$data;
  $len=0;
  for ($olen=strlen($buffer); $len<$olen; $len=strlen($buffer)) {
   $p=$this->udpmsg4_client->parse_framed($buffer);
   if ($p===FALSE) return FALSE;
   else if ($p===NULL)
    if (!$trymore) return NULL;
    else return $this->udpmsg4_parse($buffer,$func,$fd,$trymore);
   else return $this->udpmsg4_client->read_compat($p);
  }
die("This is reached if strlen(\$buffer)===0 that is EOF.\n");
  return $p;
  return self::buffered_parse($buffer,array($this->udpmsg4_client,'parse_framed'),$fd,$trymore);
 }
 function nick () {
  return '/'.$this->config['ircnet'].'/'.$this->config['user'];
 }
 function irc_set_nick ($nick) {
  if (isset($this->config['user'])) $onick=$this->nick(); else $onick=$nick;
  $nick=preg_replace(',^/'.$this->config['ircnet'].'/,','',$nick);
  if (!preg_match('/^[A-Za-z0-9_.-]+$/',$nick)) return FALSE;
  $this->irc_send_nickchange('/'.$this->config['ircnet'].'/'.$nick,$onick);
  $this->udpmsg4_client->set_user($this->config['user']=$nick);
 }
 function irc_send_nickchange ($nick,$onick=NULL) {
  if (!isset($onick))
   if (isset($this->config['user']))
    $onick='/'.$this->config['ircnet'].'/'.$this->config['user'];
  if (isset($onick) && ($onick!==$nick))
   $this->write_client(":$onick NICK $nick\n");
 }
 function irc_intro () {
  for ($done=0; !$done;) {
   if (($p=self::irc_parse($this->client_buffer,$this->client[0],1))===FALSE) return FALSE;
   if ($p===NULL) die("This should not happen.");
   if ($p->cmd==='USER') continue;
   if ($p->cmd==='NICK') if ($this->irc_set_nick($p->args[0])!==FALSE) $done=1;
   else $this->complain($p);
  }
  $this->irc_send_numerics();
  return TRUE;
 }
 function write_client_irc ($prefix,$cmd,$args) {
  return $this->write_client(irc_packet::build($prefix,$cmd,$args)."\n");
 }
 function write_client_irc_from_server ($cmd,$args) {
  return $this->write_client_irc($this->hostname,$cmd,$args);
 }
 function write_client_numeric ($cmd,$args) {
  $fargs=(array)$args;
  array_unshift($fargs,$this->nick());
  return $this->write_client_irc_from_server($cmd,$fargs);
 }
 function write_client_irc_join ($nick,$channel) {
  $ircchannel=$this->channel2ircchannel($channel);
  $user=$this->get_user($nick);
  $shortnick=preg_replace(',^/.*/,','',$nick);
  $fullnick=$nick.'!'.$shortnick.'@'.$shortnick;
  return $this->write_client_irc($fullnick,'JOIN',array($ircchannel));
 }
 function write_client_irc_part ($nick,$channel,$reason=NULL) {
  $ircchannel=$this->channel2ircchannel($channel);
  $shortnick=preg_replace(',^/.*/,','',$nick);
  $fullnick=$nick.'!'.$shortnick.'@'.$shortnick;
  return $this->write_client_irc($fullnick,'PART',array($ircchannel,$reason));
 }
 function irc_send_numerics () {
  $this->write_client_numeric('001','Welcome to the Internet Relay Network');
  $this->write_client_numeric('002','Your host is '.$this->hostname.', running version cloudircd');
  $this->write_client_numeric('003','This server was created some time ago');
  $this->write_client_numeric('004',array($this->hostname,'1.0','+','+'));
  $this->write_client_numeric('005',array('NICKLEN=16','CHANNELLEN=16','CHANTYPES=#','NETWORK='.$this->config['ircnet'],'are supported by this server'));
  $this->write_client_numeric('251','There are some users and some invisible on a bunch of servers');
  $this->write_client_numeric('252',array('0','operator(s) online'));
  $this->write_client_numeric('254',array('unknown','channels formed'));
  $this->write_client_numeric('255','I have a bunch of clients and a bunch of servers');
  $this->write_client_numeric('265','Current Local Users: too lazy to calculate');
  $this->write_client_numeric('266','Current Global Users: unknown');
  $this->write_client_numeric('375','- '.$this->hostname.' Message of the Day -');
  $motd=explode("\n",$this->config['motd']);
  foreach ($motd as $line) $this->write_client_numeric('372',"- $line");
  $this->write_client_numeric('376','End of /MOTD command.');
 }
 function complain ($packet) {
  $this->write_client_numeric('421',array($packet->cmd,'Unknown command'));
 }
 function channel2ircchannel ($channel) {
  if ($channel[0]==='/') return $channel;
  if (!preg_match(',^chat/,',$channel)) return FALSE;
  return preg_replace(',^chat/,','#',$channel);
 }
 function is_channel ($channel) {
  if ($channel[0]==='/') return FALSE;
  if (preg_match(',^chat/,',$channel)) return TRUE;
  return FALSE;
 }
 function ircchannel2channel ($channel) {
  if ($channel[0]==='/') return $channel;
  return preg_replace('/^#/','chat/',$channel);
 }
 function users_in_channel ($channel) {
  if (!isset($this->config['channels'])) return array();
  if (!isset($this->config['channels'][$channel])) return array();
  if (!isset($this->config['channels'][$channel]['users'])) return array();
  return $this->config['channels'][$channel]['users'];
 }
 function user_exists ($nick) {
  if (!isset($this->config['users'])) return FALSE;
  if (!isset($this->config['users'][$nick])) return FALSE;
  return TRUE;
 }
 function nick2shortnick ($nick) {
  $shortnick=preg_replace(',^/.*/,','',$nick);
  return $shortnick;
 }
 function nick2net ($nick) {
  $net=preg_replace(',^/,','',$nick);
  $net=preg_replace(',/.*$,','',$net);
  return $net;
 }
 function create_user ($nick,$info=NULL) {
  if ($this->user_exists($nick)) return;
  if ($info===NULL) $info=array(
   'user'=>$this->nick2shortnick($nick),
   'host'=>$this->nick2shortnick($nick),
   'server'=>$this->nick2net($nick),
   'nick'=>$nick,
   'hg'=>'H',
   'flag'=>'',
   'distance'=>0,
   'realname'=>$nick,
  );
  $this->config['users'][$nick] = new array_object($info);
 }
 function get_user ($nick) {
  if (!$this->user_exists($nick)) $this->create_user($nick);
  return $this->config['users'][$nick];
 }
 function channels () {
  return array_keys($this->config['channels']);
 }
 function user_is_joined ($user,$channel) {
  if (!isset($this->config['channels'])) return FALSE;
  if (!isset($this->config['channels'][$channel])) return FALSE;
  if (!isset($this->config['channels'][$channel]['users'])) return FALSE;
  if (isset($this->config['channels'][$channel]['users'][$user])) return TRUE;
 }
 function join_user_to_channel ($nick,$channel) {
  if (!isset($this->config['channels'][$channel]['users']))
   $this->config['channels'][$channel]['users']=array();
  if (!isset($this->config['channels'][$channel]['users'][$nick]))
   $this->config['channels'][$channel]['users'][$nick]=$this->get_user($nick);
  return TRUE;
 }
 function part_user_from_channel ($nick,$channel) {
  if (!isset($this->config['channels'][$channel]['users']))
   $this->config['channels'][$channel]['users']=array();
  if (isset($this->config['channels'][$channel]['users'][$nick]))
   unset($this->config['channels'][$channel]['users'][$nick]);
  return TRUE;
 }
 function am_joined ($channel) {
  if (!isset($this->config['channels'])) return FALSE;
  if (!isset($this->config['channels'][$channel])) return FALSE;
  if (!isset($this->config['channels'][$channel]['joined'])) return FALSE;
  return $this->config['channels'][$channel]['joined'];
 }
 function join_channel ($ircchannel) {
  $channel=$this->ircchannel2channel($ircchannel);
  $this->config['channels'][$channel]['joined']=1;
  if (!isset($this->config['channels'][$channel]['users']))
   $this->config['channels'][$channel]['users']=array();
  if (!isset($this->config['channels'][$channel]['users'][$this->nick()]))
   $this->config['channels'][$channel]['users'][$this->nick()]=$this->get_user($this->nick());
  $this->write_client_irc_join($this->nick(),$channel);
  $nicks=join(' ',array_keys($this->config['channels'][$channel]['users']));
  $this->write_client_numeric('353',array('=',$ircchannel,$nicks));
  $this->write_client_numeric('366',array($ircchannel,'End of /NAMES list.'));
  return TRUE;
 }
 function part_channel ($ircchannel,$reason=NULL) {
  $channel=$this->ircchannel2channel($ircchannel);
  $this->config['channels'][$channel]['joined']=0;
  if (!isset($this->config['channels'][$channel]['users']))
   $this->config['channels'][$channel]['users']=array();
  if (isset($this->config['channels'][$channel]['users'][$this->nick()]))
   unset($this->config['channels'][$channel]['users'][$this->nick()]);
  $this->write_client_irc_part($this->nick(),$channel,$reason);
  return TRUE;
 }
 function irc_do ($p) {
  if (($p===FALSE)||($p===NULL)) return $p;
  if (!is_a($p,'irc_packet')) $p=irc_packet::parse($p);
  if (($p===FALSE)||($p===NULL)) return $p;
  switch ($p->cmd) {
   case 'PRIVMSG':
    if (!isset($p->args[0])||!isset($p->args[1])) return FALSE;
    $to=$this->ircchannel2channel($p->args[0]);
    $p=$this->udpmsg4_client->send_message($to,$p->args[1]);
    return $this->write_hub($p->framed());
    return FALSE;
   case 'JOIN':
    return $this->join_channel($p->args[0]);
   case 'PART':
    return $this->part_channel($p->args[0],@$p->args[1]);
   case 'QUIT':
    exit(0);
   case 'NICK':
    return $this->irc_set_nick($p->args[0]);
   case 'PING':
    return $this->write_client_irc_from_server('PONG',array(@$p->args[0]));
   case 'MODE':
    if (!isset($p->args[0])) return FALSE;
    $target=$this->ircchannel2channel($p->args[0]);
    if ($this->is_channel($target)) {
     if (!isset($p->args[1])) {
      return $this->write_client_numeric('324',array($p->args[0],'+nt')) && $this->write_client_numeric('329',array($p->args[0],time()));
     } else {
      return $this->write_client_numeric('368',array($p->args[0],'End of channel ban list'));
      return FALSE;
     }
    } else {
     return FALSE;
    }
   case 'WHO':
    if (!isset($p->args[0])) return FALSE;
    $target=$this->ircchannel2channel($p->args[0]);
    if ($this->is_channel($target)) {
     foreach ($this->users_in_channel($target) as $nick=>$user)
{
      if (!$this->write_client_numeric('352',array($p->args[0],$user['user'],$user['host'],$user['server'],$user['nick'],$user['hg'].$user['flag'],$user['distance'].' '.$user['realname']))) return FALSE;
}
     return $this->write_client_numeric('315',array($p->args[0],'End of /WHO list.'));
    } else {
     return FALSE;
    }
   case 'LIST':
    if (!$this->write_client_numeric('321',array('Channel','Users Name'))) return FALSE;
    foreach ($this->channels() as $channel) {
     $ircchannel=$this->channel2ircchannel($channel);
     $count=count($this->users_in_channel($channel));
     if (!$this->write_client_numeric('322',array($ircchannel,$count,'[+nt]'))) return FALSE;
    }
    return TRUE;
   default:
//debug($p);
    return FALSE;
  }
 }
 function udpmsg4_do ($p) {
  if (($p===FALSE)||($p===NULL)) return $p;
  if (!is_a($p,'udpmsg4_packet')) $p=udpmsg4_packet::parse($p);
  if (($p===FALSE)||($p===NULL)) return $p;
  if (!isset($p['CMD'])) return TRUE;
  switch($p['CMD']) {
   case 'ALIVE':
    if ($this->user_is_joined($p['SRC'],$p['DST']))
     return TRUE;
   case 'JOIN':
    if (!$this->user_is_joined($p['SRC'],$p['DST']))
     $this->join_user_to_channel($p['SRC'],$p['DST']);
    $icare=0;
    if ($this->am_joined($p['DST'])) $icare=1;
    if ($icare) {
     $ircchannel=$this->channel2ircchannel($p['DST']);
     $this->write_client_irc_join($p['SRC'],$p['DST']);
//     $this->write_client_irc($p['SRC'],'JOIN',array($ircchannel));
    }
    return TRUE;
   case 'PART':
    if ($p['BC']) return TRUE;
    if ($this->user_is_joined($p['SRC'],$p['DST'])) {
     $this->part_user_from_channel($p['SRC'],$p['DST']);
     if ($this->am_joined($p['DST'])) $icare=1; else $icare=0;
    }
    if ($icare) {
     $ircchannel=$this->channel2ircchannel($p['DST']);
     $this->write_client_irc_part($p['SRC'],$p['DST'],$p['REASON']);
    }
    return TRUE;
   case 'QUIT':
    $icare=0;
    foreach ($this->config['channels'] as $k=>$channel)
     if ($this->user_is_joined($p['SRC'],$k)) {
      $this->part_user_from_channel($p['SRC'],$k);
      if ($this->am_joined($k)) $icare=1;
     }
    if ($icare) $this->write_client_irc($p['SRC'],'QUIT',array($p['REASON']));
    return TRUE;
   case 'MSG':
    $icare=0;
    if ($this->am_joined($p['DST'])) $icare=1;
    if ($this->is_channel($p['DST']) && !$this->user_is_joined($p['SRC'],$p['DST'])) {
     $this->join_user_to_channel($p['SRC'],$p['DST']);
     if ($icare) {
      $ircchan=$this->channel2ircchannel($p['DST']);
      $this->write_client_irc_join($p['SRC'],$p['DST']);
     }
    }
    if ($icare) {
     $ircchan=$this->channel2ircchannel($p['DST']);
     $this->write_client_irc($p['SRC'],'PRIVMSG',array($ircchan,$p['MSG']));
    }
    return TRUE;
   case 'ENC': return TRUE;
   default:
//debug("received udpmsg4 packet CMD=[".$p['CMD']."]");
    return FALSE;
  }
 }
 function loop () {
  foreach ($this->poll as $key => $value) switch ($key) {
   case 'r':
    if ($value===$this->client[0]) {
     for ($p=self::irc_parse($this->client_buffer,$this->client[0]); $p!==NULL; $p=self::irc_parse($this->client_buffer))
      if ($p===FALSE) return FALSE;
      else if ($this->irc_do($p)===FALSE) $this->complain($p);
     break;
    } else if ($value===$this->hub[0]) {
     for ($p=$this->udpmsg4_parse($this->hub_buffer,$this->hub[0]); $p!==NULL; $p=$this->udpmsg4_parse($this->hub_buffer))
      if ($p===FALSE) return FALSE;
      else if ($this->udpmsg4_do($p)===FALSE) $this->complain($p);
     break;
    }
    break;
   case 'w': break;
//   default: do timer;
  }
echo "reached to end\n";
  return NULL;
 }
}

include 'cloudircd.config.php';

$ircd=cloudircd::construct(fopen('php://fd/6','r'),fopen('php://fd/7','w'),fopen('php://fd/0','r'),fopen('php://fd/1','w'),$config);

$ircd->loop();

?>