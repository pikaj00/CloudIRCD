<?php

function debug ($sub,$pri,$str) { fprintf(STDERR,getmypid().":$sub:$pri [%s]\n",$str); }

include 'libudpmsg4.php';

include 'poll.php';

include 'irc_packet.php';

include 'array_object.php';

class cloudircd {
 var $hub,$client;
 var $hub_buffer,$client_buffer;
 var $config=array();
 var $poll;
 var $udpmsg4_client;
 var $hostname;
 var $map_function=NULL;
 var $unmap_function=NULL;
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
debug('irc',1,"sent: $data");
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
  if (isset($new->config['nick_map_function']))
   $new->map_function=$new->config['nick_map_function'];
  if (isset($new->config['nick_unmap_function']))
   $new->unmap_function=$new->config['nick_unmap_function'];
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
 function irc_set_nick ($nick,$ircnick=FALSE) {
  if (isset($this->config['user'])) $onick=$this->chan2ircwire($this->nick());
  else $onick=$nick;
  if ($ircnick) $nick=$this->ircwire2chan($nick);
  $nick=preg_replace(',^/'.$this->config['ircnet'].'/,','',$nick);
  if (isset($this->config['oldircnet']))
   $nick=preg_replace(',^/'.$this->config['oldircnet'].'/,','',$nick);
  if (!preg_match('/^[A-Za-z0-9_.-]+$/',$nick)) return FALSE;
  $this->irc_send_nickchange($this->chan2ircwire('/'.$this->config['ircnet'].'/'.$nick),$onick);
  $this->udpmsg4_client->set_user($this->config['user']=$nick);
  return TRUE;
 }
 function irc_set_ircnick ($nick) {
  return $this->irc_set_nick($nick,TRUE);
 }
 function irc_send_nickchange ($nick,$onick=NULL) {
  if (!isset($onick))
   if (isset($this->config['user']))
    $onick=$this->chan2ircwire('/'.$this->config['ircnet'].'/'.$this->config['user']);
  if (isset($onick) && ($onick!==$nick))
   $this->write_client(":$onick NICK $nick\n");
 }
 function irc_intro () {
  for ($done=0; !$done;) {
   if (($p=self::irc_parse($this->client_buffer,$this->client[0],1))===FALSE) return FALSE;
   if ($p===NULL) die("This should not happen.");
   if (strtoupper($p->cmd)==='PASS') {
    list ($seckey,$pubkey) = explode('/',$p->args[0]);
    if (!strlen($pubkey)) return FALSE;
    $this->udpmsg4_client->set_keypair($pubkey,$seckey);
    continue;
   }
   if (strtoupper($p->cmd)==='USER') continue;
   if (strtoupper($p->cmd)==='NICK')
    if ($this->irc_set_ircnick($p->args[0])!==FALSE) $done=1;
    else $this->write_client_irc_from_server('432',array($this->chan2ircwire('/'.$this->config['ircnet'].'/randomnick'),$p->args[0],'Erroneus nickname'));
   else if (strtoupper($p->cmd)==='MODE')
    $this->write_client_irc_from_server('451',array('MODE','You have not registered'));
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
  array_unshift($fargs,$this->chan2ircwire($this->nick()));
  return $this->write_client_irc_from_server($cmd,$fargs);
 }
 function write_client_numeric_notenoughparams ($command) {
  return $this->write_client_numeric('461',array($command,'Not enough parameters.'));
 }
 function fullnick ($nick) {
  $user=$this->get_user($nick);
  return $this->chan2ircwire($nick).'!'.$user['user'].'@'.$user['host'];
 }
 function write_client_irc_join ($nick,$channel) {
  $ircchannel=$this->channel2ircchannel($channel);
  $user=$this->get_user($nick);
  $shortnick=$this->nick2shortnick($nick);
  $fullnick=$nick.'!'.$user['user'].'@'.$user['host'];
  return $this->write_client_irc($this->fullnick($nick),'JOIN',array($ircchannel));
 }
 function write_client_irc_part ($nick,$channel,$reason=NULL) {
  $ircchannel=$this->channel2ircchannel($channel);
  $user=$this->get_user($nick);
  $shortnick=preg_replace(',^/.*/,','',$nick);
  $fullnick=$nick.'!'.$user['user'].'@'.$user['host'];
  return $this->write_client_irc($this->fullnick($nick),'PART',array($ircchannel,$reason));
 }
 function write_client_irc_quit ($nick,$reason=NULL) {
  $user=$this->get_user($nick);
  $fullnick=$nick.'!'.$user['user'].'@'.$user['host'];
  return $this->write_client_irc($this->fullnick($nick),'QUIT',array($reason));
 }
 function write_client_irc_topic ($nick,$channel,$topic) {
  $user=$this->get_user($nick);
  $fullnick=$nick.'!'.$user['user'].'@'.$user['host'];
  return $this->write_client_irc($this->fullnick($nick),'TOPIC',array($this->channel2ircchannel($channel),$topic));
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
  if (!strlen($channel)) return FALSE;
  if ($channel[0]==='/') return $channel;
  if (!preg_match(',^chat/,',$channel)) return FALSE;
  return preg_replace(',^chat/,','#',$channel);
 }
 function is_channel ($channel) {
  if (!strlen($channel)) return TRUE;
  if ($channel[0]==='/') return FALSE;
  if (preg_match(',^chat/,',$channel)) return TRUE;
  return FALSE;
 }
 function ircchannel2channel ($channel) {
  if (!strlen($channel)) return FALSE;
  if ($channel[0]==='/') return $channel;
  return preg_replace('/^#/','chat/',$channel);
 }
 function map_nick ($nick) {
  if ($this->map_function===NULL) return $nick;
  $f=$this->map_function;
  return $f($nick,$this);
 }
 function unmap_nick ($nick) {
  if ($this->unmap_function===NULL) return $nick;
  $f=$this->unmap_function;
  return $f($nick,$this);
 }
 function ircwire2chan ($ircwire) {
  $chan=$this->unmap_nick($ircwire);
  if ($chan===FALSE) $chan=$ircwire;
  return $this->ircchannel2channel($chan);
 }
 function chan2ircwire ($chan) {
  $ic=$this->is_channel($chan);
  $chan=$this->channel2ircchannel($chan);
  if ($chan===FALSE) return FALSE;
  return $ic?$chan:$this->map_nick($chan);
 }
 function map_nicks ($nicks) {
  if ($this->map_function===NULL) return $nicks;
  $r=array();
  foreach ($nicks as $k=>$v) $r[$k]=$this->map_nick($v);
  return $r;
 }
 function unmap_nicks ($nicks) {
  if ($this->unmap_function===NULL) return $nicks;
  $r=array();
  foreach ($nicks as $k=>$v) $r[$k]=$this->unmap_nick($v);
  return $r;
 }
 function users_in_channel ($channel) {
  if (!isset($this->config['channels'])) return array();
  if (!isset($this->config['channels'][$channel])) return array();
  if (!isset($this->config['channels'][$channel]['users'])) return array();
  return $this->config['channels'][$channel]['users'];
 }
 function channel_topic ($channel) {
  if (!isset($this->config['channels'])) return NULL;
  if (!isset($this->config['channels'][$channel])) return NULL;
  if (!isset($this->config['channels'][$channel]['topic'])) return NULL;
  return $this->config['channels'][$channel]['topic'];
 }
 function channel_topic_from ($channel) {
  if (!isset($this->config['channels'])) return NULL;
  if (!isset($this->config['channels'][$channel])) return NULL;
  if (!isset($this->config['channels'][$channel]['topic'])) return NULL;
  return $this->config['channels'][$channel]['topic_from'];
 }
 function channel_topic_timestamp ($channel) {
  if (!isset($this->config['channels'])) return NULL;
  if (!isset($this->config['channels'][$channel])) return NULL;
  if (!isset($this->config['channels'][$channel]['topic'])) return NULL;
  return $this->config['channels'][$channel]['topic_timestamp'];
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
 function quit_user ($nick,&$icare) {
  foreach ($this->config['channels'] as $k=>$channel)
   if ($this->user_is_joined($nick,$k)) {
    $this->part_user_from_channel($nick,$k);
    if ($this->am_joined($k)) $icare=1;
   }
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
  $nicks=array_keys($this->config['channels'][$channel]['users']);
  $this->write_client_numeric('353',array('=',$ircchannel,join(' ',$this->map_nicks($nicks))));
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
  $this->write_client_irc_part($this->chan2ircwire($this->nick()),$channel,$reason);
  return TRUE;
 }
 function set_channel_topic ($channel,$topic,$from=NULL,$timestamp=NULL) {
  $this->config['channels'][$channel]['topic']=$topic;
  $from=isset($from)?$from:$this->nick();
  $this->config['channels'][$channel]['topic_from']=$from;
  $this->config['channels'][$channel]['topic_timestamp']=isset($timestamp)?$timestamp:time();
  return TRUE;
 }
 function irc_do ($p) {
  if (($p===FALSE)||($p===NULL)) return $p;
  if (!is_a($p,'irc_packet')) $p=irc_packet::parse($p);
  if (($p===FALSE)||($p===NULL)) return $p;
  switch ($p->CMD) {
   case 'PRIVMSG':
   case 'NOTICE':
    if (!isset($p->args[0])||!isset($p->args[1])) return FALSE;
    $to=$this->ircwire2chan($p->args[0]);
    if ($p->CMD==='PRIVMSG')
     $tp=$this->udpmsg4_client->send_message($to,$p->args[1]);
    else
     $tp=$this->udpmsg4_client->send_notice($to,$p->args[1]);
    if ($tp===FALSE)
     if ($this->is_channel($to))
      return $this->write_client_irc($p->args[0],'NOTICE',array($this->nick(),'Failed to send '.($p->CMD==='PRIVMSG'?'message':'notice').' to '.$p->args[0]));
     else
      return $this->write_client_irc($p->args[0],'NOTICE',array($this->nick(),'Failed to send PM to '.$p->args[0]));
    return $this->write_hub($tp->framed());
   case 'JOIN':
    foreach (explode(',',$p->args[0]) as $channel)
     if (!$this->join_channel($channel)) return FALSE;
    return TRUE;
   case 'PART':
    foreach (explode(',',$p->args[0]) as $channel)
     if (!$this->part_channel($channel,@$p->args[1])) return FALSE;
    return TRUE;
   case 'QUIT':
    exit(0);
   case 'NICK':
    return $this->irc_set_ircnick($p->args[0]);
   case 'PING':
    return $this->write_client_irc_from_server('PONG',array($this->hostname,@$p->args[0]));
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
      if (!$this->write_client_numeric('352',array($p->args[0],$user['user'],$user['host'],$user['server'],$this->chan2ircwire($user['nick']),$user['hg'].$user['flag'],$user['distance'].' '.$user['realname']))) return FALSE;
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
    if (!$this->write_client_numeric('323',array('End of channel list.'))) return FALSE;
    return TRUE;
   case 'USER':
    return TRUE;
   case 'USERHOST':
    if (!isset($p->args[0])) return $this->write_client_numeric_notenoughparams($p->cmd);
    $user=$this->get_user($this->ircwire2chan($p->args[0]));
    $this->write_client_numeric('302',$this->chan2ircwire($user['nick'])."=+".$user['user'].'@'.$user['host']);
    return TRUE;
   case 'TOPIC':
    if (!isset($p->args[0])) return FALSE;
    $channel=$this->ircwire2chan($p->args[0]);
    if (!isset($p->args[1])) {
     $topic=$this->channel_topic($channel);
     if ($topic===NULL)
      return $this->write_client_numeric('331',array($p->args[0],'No topic is set.'));
     return $this->write_client_numeric('332',array($p->args[0],$topic)) && $this->write_client_numeric('333',array($p->args[0],$this->channel_topic_from($channel),$this->channel_topic_timestamp($channel)));
    }
    $this->set_channel_topic($channel,$p->args[1]);
    $tp=$this->udpmsg4_client->send_topic($channel,$p->args[1]);
    return $this->write_hub($tp->framed()) && $this->write_client_irc_topic($this->chan2ircwire($this->nick()),$channel,$p->args[1]);
   case 'AWAY':
    if (!isset($p->args[0]))
     return $this->write_client_numeric('305',array('You are no longer marked as being away'));
    return $this->write_client_numeric('306',array('You have been marked as being away but not matters'));
   default:
debug('irc',1,'received: '.$p);
    return FALSE;
  }
 }
 function udpmsg4_do ($p) {
/*
:dBZ!user@949DD1.5D28CF.4EA2CF.F6DE5C QUIT :irc5.srn.ano pbx.namek.ano
:dBZ!user@949DD1.5D28CF.4EA2CF.F6DE5C JOIN :#anonet
:/A1/dBZ QUIT :irc.r101.ano irc5.srn.ano
:/A1/dBZ!dBZ@dBZ JOIN :#anonet
*/
  if (($p===FALSE)||($p===NULL)) return $p;
  if (!is_a($p,'udpmsg4_packet')) $p=udpmsg4_packet::parse($p);
  if (($p===FALSE)||($p===NULL)) return $p;
  if (!isset($p['CMD'])) return TRUE;
  switch($p['CMD']) {
   case 'ALIVE':
    if ($this->user_is_joined($p['SRC'],$p['DST']))
     return TRUE;
   case 'JOIN':
    if ($this->user_is_joined($p['SRC'],$p['DST'])) {
     $icare=0;
     $this->quit_user($p['SRC'],$icare);
     if ($icare) $this->write_client_irc_quit($p['SRC'],'received JOIN to '.$p['DST']);
    }
    if (!$this->user_is_joined($p['SRC'],$p['DST']))
     $this->join_user_to_channel($p['SRC'],$p['DST']);
    $icare=0;
    if ($this->am_joined($p['DST'])) $icare=1;
    if ($this->nick()===$p['SRC']) $icare=0;
    if ($icare) $this->write_client_irc_join($p['SRC'],$p['DST']);
    return TRUE;
   case 'PART':
    if ($p['BC']) return TRUE;
    if ($this->nick()===$p['SRC']) return TRUE;
    $icare=0;
    if ($this->user_is_joined($p['SRC'],$p['DST'])) {
     $this->part_user_from_channel($p['SRC'],$p['DST']);
     if ($this->am_joined($p['DST'])) $icare=1;
    }
    if ($icare) {
     $ircchannel=$this->channel2ircchannel($p['DST']);
     $this->write_client_irc_part($p['SRC'],$p['DST'],$p['REASON']);
    }
    return TRUE;
   case 'QUIT':
    if ($this->nick()===$p['SRC']) return TRUE;
    $icare=0;
    $this->quit_user($p['SRC'],$icare);
    if ($icare) $this->write_client_irc_quit($p['SRC'],$p['REASON']);
    return TRUE;
   case 'MSG':
    $icare=0;
    if ($this->am_joined($p['DST'])) $icare=1;
    if ($this->is_channel($p['DST']) && !$this->user_is_joined($p['SRC'],$p['DST'])) {
     $this->join_user_to_channel($p['SRC'],$p['DST']);
     if ($icare) $this->write_client_irc_join($p['SRC'],$p['DST']);
    }
    if ($p['DST']===$this->nick()) $icare=1;
    if ($icare) {
     $ircchan=$this->chan2ircwire($p['DST']);
     $this->write_client_irc($this->fullnick($p['SRC']),'PRIVMSG',array($ircchan,$p['MSG']));
    }
    return TRUE;
   case 'NOTICE':
    $icare=0;
    if ($this->am_joined($p['DST'])) $icare=1;
    if ($p['DST']===$this->nick()) $icare=1;
    if ($icare) {
     $ircchan=$this->chan2ircwire($p['DST']);
     $this->write_client_irc($this->fullnick($p['SRC']),'NOTICE',array($ircchan,$p['MSG']));
    }
    return TRUE;
   case 'ENC': return TRUE;
   case 'TOPIC':
    $this->set_channel_topic($p['DST'],$p['TOPIC'],$p['SRC']);
    $icare=0;
    if ($this->am_joined($p['DST'])) $icare=1;
    if ($this->is_channel($p['DST']) && !$this->user_is_joined($p['SRC'],$p['DST'])) {
     $this->join_user_to_channel($p['SRC'],$p['DST']);
     if ($icare) $this->write_client_irc_join($p['SRC'],$p['DST']);
    }
    if ($p['SRC']===$this->nick()) $icare=1;
    if ($icare)
     $this->write_client_irc_topic($p['SRC'],$p['DST'],$p['TOPIC']);
    return TRUE;
   default:
debug('udpmsg4',1,"received CMD=".$p['CMD']);
    return TRUE;
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
      if ($p===FALSE) $this->write_client_irc_from_server('NOTICE',array($this->nick(),'Received bad packet from cloud'));
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
