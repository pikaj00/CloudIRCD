<?php

function debug ($sub,$pri,$str) { fprintf(STDERR,getmypid().":$sub:$pri [%s]\n",$str); }

include 'libudpmsg4.php';

include 'poll.php';

include 'irc_packet.php';

class cloudc2sd {
 var $hub,$client;
 var $hub_buffer,$client_buffer;
 var $config=array();
 var $poll;
 var $udpmsg4_client;
 var $hostname;
 var $map_function=NULL;
 var $unmap_function=NULL;
 var $last_ping=NULL;
 var $last_pong=NULL;
 var $last_relay_alive_time;
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
  if (@$this->config['throttle']) {
   static $time=NULL;
   while (($newtime=time())===$time) usleep(200000); $time=$newtime;
  }
  if (self::write_fd($this->client[1],$data)!=strlen($data)) return FALSE;
debug('irc',1,"sent: $data");
  return TRUE;
 }
 static function construct ($hub_r,$hub_w,$client_r,$client_w,$config=NULL) {
  $new = new cloudc2sd;
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
   $new->config['hostname']='cloudc2sd';
  if (!isset($new->config['ircdhost']))
   $new->config['ircdhost']=getenv('ircdip');
  if (isset($new->config['nick_map_function']))
   $new->map_function=$new->config['nick_map_function'];
  if (isset($new->config['nick_unmap_function']))
   $new->unmap_function=$new->config['nick_unmap_function'];
  if (!isset($new->config['priority'])) $new->config['priority']=0;
  if (!isset($new->config['timeout'])) $new->config['timeout']=60;
  if (!isset($new->config['admin'])) $new->config['admin']='/NNNC/somerandomnick';
  if (!isset($new->config['nicks'])) $new->config['nicks']=array($new->config['nick'],$new->config['nick'].'bak1',$new->config['nick'].'bak2');
  $new->last_relay_alive_time=time();
  $new->hostname=$new->config['hostname'];
  $new->udpmsg4_client = new udpmsg4_client($new->config['udpmsg4_client']);
  if (!$new->irc_intro()) return FALSE;
  $new->poll = new poll;
  $new->poll->add_readfd($hub_r);
  $new->poll->add_readfd($client_r);
//  if (!isset($new->config['channels'])) $new->config['channels']=array();
  if (!isset($new->config['kickers'])) $new->config['kickers']=array();
  return $new;
 }
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
    else return self::irc_parse($buffer,$fd,$trymore);
   else return $p;
  }
die("This is reached if strlen(\$buffer)===0 that is EOF.\n");
  return $p;
  return self::buffered_parse($buffer,array('irc_packet','parse'),$fd,$trymore);
 }
 function udpmsg4_parse (&$buffer,$fd=NULL,$trymore=0) {
  $p=$this->udpmsg4_client->parse_framed($buffer);
  if ($p===FALSE) return FALSE;
  if ($p===NULL);
  else return $this->udpmsg4_client->read_compat($p);
  if ($fd===NULL) return NULL;
  $data=fread($fd,4096);
  if ($data===FALSE) return FALSE;
  $buffer.=$data;
  $len=0;
  for ($olen=strlen($buffer); $len<$olen; $len=strlen($buffer)) {
   $p=$this->udpmsg4_client->parse_framed($buffer);
   if ($p===FALSE) return FALSE;
   if ($p===NULL)
    if (!$trymore) return NULL;
    else return $this->udpmsg4_parse($buffer,$func,$fd,$trymore);
   else return $this->udpmsg4_client->read_compat($p);
  }
die("This is reached if strlen(\$buffer)===0 that is EOF.\n");
  return $p;
  return self::buffered_parse($buffer,array($this->udpmsg4_client,'parse_framed'),$fd,$trymore);
 }
 function nick ($nick=NULL) {
  if ($nick===NULL) $nick=$this->config['nick'];
  return '/'.$this->config['ircnet'].'/'.$nick;
 }
 function irc_join ($channel) {
  $this->write_client("JOIN ".$this->channel2ircchannel($channel)."\r\n");
  return TRUE;
 }
 function irc_intro () {
  if (isset($this->config['connectpass']))
   $this->write_client("PASS ".$this->config['connectpass']."\r\n");
  $nicks=$this->config['nicks'];
  $this->write_client("USER u u ".(isset($this->config['ircdhost'])?$this->config['ircdhost']:'u')." :u\r\n");
  $this->write_client("NICK ".$nicks[0]."\r\n");
  for ($done=0; !$done;) {
   if (($p=self::irc_parse($this->client_buffer,$this->client[0],1))===FALSE) return FALSE;
   if ($p===NULL) die("This should not happen.");
   if ($p->cmd==='NICK') $this->ircnick=$p->args[0];
   else if ($p->cmd==='432') die("IRC ERROR: bad nick");
   else if ($p->cmd==='433') {
    array_shift($nicks);
    if (!count($nicks)) die("IRC ERROR: nicks used");
    $this->write_client("NICK ".$nicks[0]."\r\n");
   } else if ($p->cmd==='001') {
    $this->config['nick']=$nicks[0];
    $this->config['starttime']=time();
    if (strlen(@$this->config['pass']))
     switch(@$this->config['authtype']) {
      case 'nickserv1':
       $this->write_client("PRIVMSG NickServ :IDENTIFY ".$this->config['nick']." ".$this->config['pass']."\r\n");
       break;
      case 'nickserv2':
       $this->write_client("PRIVMSG NickServ :IDENTIFY ".$this->config['pass']."\r\n");
       break;
     }
    foreach ($this->config['channels'] as $name=>$channel) {
     $this->irc_join($name);
     if ($this->config['nick']===$this->config['nicks'][0]) {
      $this->write_client_irc_from_client('NOTICE',array($this->channel2ircchannel($name),'cloudc2sd admin '.$this->config['admin'].' if problems /msg '.$this->config['nick'].' !complain you suck :-)'));
     }
    }
    $done=1;
   } else if ($p->cmd==='PING')
    if ($this->write_client_irc_from_client('PONG',array($p->args[0]))===FALSE) return FALSE;
  }
  return TRUE;
 }
 function write_client_irc ($prefix,$cmd,$args) {
  return $this->write_client(irc_packet::build($prefix,$cmd,$args)."\n");
 }
 function write_client_irc_from_client ($cmd,$args) {
  return $this->write_client_irc(NULL,$cmd,$args);
 }
 function fullnick ($nick) {
  $user=$this->get_user($nick);
  return $this->map_nick($nick).'!'.$user['user'].'@'.$user['host'];
 }
 function fullnick2nick ($nick) {
  return preg_replace('/!.*$/','',$nick);
 }
 function channel2ircchannel ($channel) {
  if ($channel[0]==='/')
   if (!$this->nick_is_ours($channel)) return $channel;
   else return $this->nick2shortnick($channel);
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
  if (preg_match('/^#/',$channel)) {
   $to=preg_replace('/^#/','chat/',$channel);
   foreach ($this->config['channels'] as $name => $channel)
    if (!strcasecmp($to,$name)) $to=$name;
   return $to;
  }
  return '/'.$this->config['ircnet'].'/'.$channel;
 }
 function map_nick ($nick) {
  if ($this->map_function===NULL) return $nick;
  $f=$this->map_function;
  return $f($nick);
 }
 function unmap_nick ($nick) {
  if ($this->unmap_function===NULL) return $nick;
  $f=$this->unmap_function;
  return $f($nick);
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
 function nick_is_ours ($nick) {
  return $this->nick2net($nick)===$this->config['ircnet'];
 }
 function irc_do ($p) {
  if (($p===FALSE)||($p===NULL)) return $p;
  if (!is_a($p,'irc_packet')) $p=irc_packet::parse($p);
  if (($p===FALSE)||($p===NULL)) return $p;
  switch ($p->cmd) {
   case 'PRIVMSG':
    if (!isset($p->args[0])||!isset($p->args[1])) return FALSE;
    $to=$this->ircchannel2channel($p->args[0]);
    $fromnick=$this->fullnick2nick($p->prefix);
    $from=$this->ircchannel2channel($fromnick);
    if ($to[0]==='/') {
//     return $this->write_client_irc_from_client('PRIVMSG',array($fromnick,"PMs not supported still"));
     if (preg_match('/^!complain (.*)$/',$p->args[1],$m))
      $p->args[1]=$this->config['admin'].': '.$m[1];
     $parts = explode(': ',$p->args[1],2);
     if (!isset($parts[1]) || !preg_match('#^[ @.]*(.*)$#',$parts[0],$m) || preg_match('/\s/',$parts[0]))
      return $this->write_client_irc_from_client('NOTICE',array($fromnick,"You can chat to cloud users through me.  The format is '/path/to/destination: message' :-)"));
     $p=$this->udpmsg4_client->send_message($this->unmap_nick($m[1]),$parts[1],$this->unmap_nick($from));
     if (($p!==FALSE) && !$this->write_hub($p->framed()))
      return $this->write_client_irc_from_client('PRIVMSG',array($fromnick,"Your PM to $parts[0] failed.  Usually this is because that you typed the destination wrong or because that the destination not supports end-to-end encrypted PMs."));
     return TRUE;
    }
    $p2=$this->udpmsg4_client->send_message($this->unmap_nick($to),$p->args[1],$this->unmap_nick($from));
    $r=$this->write_hub($p2->framed());
    if (!$r) return $r;
    if (preg_match('/^'.$this->config['nick'].': kick (.*)/',$p->args[1],$m)) {
     if (isset($this->config['kickers'][$this->unmap_nick($to)]) && in_array($fromnick,$this->config['kickers'][$this->unmap_nick($to)])) {
      $this->config['kicked'][$this->unmap_nick($to)][$this->unmap_nick($m[1])]=array('time'=>time(),'kicker'=>$p->prefix);
      return $this->write_client_irc_from_client('PRIVMSG',array($this->channel2ircchannel($to),"kicked"));
     } else {
      return $this->write_client_irc_from_client('PRIVMSG',array($this->channel2ircchannel($to),"not a kicker"));
     }
    } else if (preg_match('/^'.$this->config['nick'].': kicks( -a)?$/',$p->args[1],$m)) {
     if (strlen(@$m[1]))
      foreach ($this->config['kicked'] as $chan=>$kicks) {
       if (!$this->write_client_irc_from_client('PRIVMSG',array($this->channel2ircchannel($to),"kicks in $chan"))) return FALSE;
       foreach ($kicks as $nick=>$infos)
        if (!$this->write_client_irc_from_client('PRIVMSG',array($this->channel2ircchannel($to),"$nick $infos[kicker] $infos[time]"))) return FALSE;
      }
     else
      foreach ($this->config['kicked'][$this->unmap_nick($to)] as $nick=>$infos)
       if (!$this->write_client_irc_from_client('PRIVMSG',array($this->channel2ircchannel($to),"$nick $infos[kicker] $infos[time]"))) return FALSE;
     return TRUE;
    }
    return $r;
   case 'JOIN':
    $from=$this->ircchannel2channel($this->fullnick2nick($p->prefix));
    foreach (explode(',',$p->args[0]) as $channel) {
     $p=$this->udpmsg4_client->send_join($this->ircchannel2channel($channel),$this->unmap_nick($from));
     if (!$this->write_hub($p->framed())) return FALSE;
    }
    return TRUE;
   case 'PART':
    $from=$this->ircchannel2channel($this->fullnick2nick($p->prefix));
    foreach (explode(',',$p->args[0]) as $channel) {
     $p=$this->udpmsg4_client->send_part($this->ircchannel2channel($channel),@$p->args[1],$this->unmap_nick($from));
     if (!$this->write_hub($p->framed())) return FALSE;
    }
    return TRUE;
   case 'KICK':
    $kicker=$this->ircchannel2channel($this->fullnick2nick($p->prefix));
    $kicked=$this->ircchannel2channel($this->fullnick2nick($p->args[1]));
    foreach (explode(',',$p->args[0]) as $channel) {
     $p2=$this->udpmsg4_client->send_part($this->ircchannel2channel($channel),'kicked by '.$this->unmap_nick($kicker).': '.@$p->args[2],$this->unmap_nick($kicked));
     if (!$this->write_hub($p2->framed())) return FALSE;
    }
    if ($p->args[1]===$this->config['nick']) die("kicked");
    return TRUE;
   case 'QUIT':
    $from=$this->ircchannel2channel($this->fullnick2nick($p->prefix));
    $p=$this->udpmsg4_client->send_quit(@$p->args[0],$this->unmap_nick($from));
    return $this->write_hub($p->framed());
   case 'ERROR':
    $p=$this->udpmsg4_client->send_quit('ERROR '.preg_replace('/(?:\(|\[)[^ ]*?(?:\(|\])/','',@$p->args[0]),$this->nick());
    $this->write_hub($p->framed());
    exit(1);
/*
   case 'NICK':
    $from=$this->ircchannel2channel($this->fullnick2nick($p->prefix));
    $to=$this->ircchannel2channel($this->fullnick2nick($p->args[0]));
    $p=$this->udpmsg4_client->send_nick($this->unmap_nick($to),$this->unmap_nick($from));
    return $this->write_hub($p->framed());
*/
   case 'PING':
    return $this->write_client_irc_from_client('PONG',array($p->args[0]));
   case 'PONG':
    $this->last_pong=time();
    if ($this->config['nick']!==$this->config['nicks'][0]) {
     if ($this->last_relay_alive_time+10<$this->last_pong) {
      if (preg_match('/^nickserv/',@$this->config['authtype'])) {
       $this->write_client("PRIVMSG NickServ :GHOST ".$this->config['nicks'][0]." ".$this->config['pass']."\r\n");
       sleep(1);
       $this->write_client("NICK :".$this->config['nicks'][0]."\r\n");
       $this->config['nick']=$this->config['nicks'][0];
      } else die("try again");
     }
    }
    else $this->last_relay_alive_time=$this->last_pong;
    return TRUE;
   case '433':
    if ($this->config['nick']===$p->args[1]) $this->config['nick']=$p->args[0];
    return TRUE;
   default:
debug('irc',1,'received: '.$p);
    return FALSE;
   case 'KICK':
    if ($p->args[2]==='u') {
     $p2=$this->udpmsg4_client->send_quit($p,$this->nick());
     $this->write_hub($p2->framed());
     $this->write_client_irc_from_client('QUIT',array($p));
     sleep(1);
     exit(2);
    }
    return TRUE;
   case '404':
   case '405':
   case '474':
    if (!isset($this->config['channels'][$this->channel2ircchannel($p->args[1])])) return TRUE;
    $p2=$this->udpmsg4_client->send_quit($p,$this->nick());
    $this->write_hub($p2->framed());
    $this->write_client_irc_from_client('QUIT',array($p));
    sleep(1);
    exit(2);
  }
 }
 function udpmsg4_do ($p) {
  if (($p===FALSE)||($p===NULL)) return $p;
  if (!is_a($p,'udpmsg4_packet')) $p=udpmsg4_packet::parse($p);
  if (($p===FALSE)||($p===NULL)) return $p;
  if (!isset($p['CMD'])) return TRUE;
  if ($this->config['nick']!==$this->config['nicks'][0]) {
   switch($p['CMD']) {
    case 'X-srn.ano-relaystatus':
     if ($this->ircchannel2channel($this->config['nicks'][0])===$p['SRC'])
      $this->last_relay_alive_time=time();
   }
   return TRUE;
  }
  switch($p['CMD']) {
   case 'JOIN':
    if (isset($this->config['kicked'][$p['DST']][$p['SRC']])) {
     unset($this->config['kicked'][$p['DST']][$p['SRC']]);
     return $this->write_client_irc_from_client('PRIVMSG',array($this->channel2ircchannel($p['DST']),'unkicked '.$p['SRC']));
    }
   case 'ALIVE':
   case 'PART':
   case 'QUIT':
    return TRUE;
   case 'MSG':
    $icare=0;
    if ($this->is_channel($p['DST']))
     if (isset($this->config['channels'][$p['DST']])) $icare=1; else;
    else $icare=1;
    if ($icare && !isset($this->config['kicked'][$p['DST']][$p['SRC']])) {
     $ircchan=$this->channel2ircchannel($p['DST']);
     if (($this->map_function!==NULL) && !$this->is_channel($p['DST']))
      $ircchan=$this->map_nick($ircchan);
     if (@$this->config['colors']!=NULL) {
      $cnick=isset($this->config['colors']['nick'])?$this->config['colors']['nick']:chr(3).'05';
      $carrow=isset($this->config['colors']['arrow'])?$this->config['colors']['arrow']:chr(3).'08';
      $cmsg=isset($this->config['colors']['message'])?$this->config['colors']['message']:chr(15);
      $msg=$cnick.$p['SRC'].$carrow.'> '.$cmsg.$p['MSG'];
     } else {
      $msg=$p['SRC'].'> '.$p['MSG'];
     }
     if (isset($this->config['badregexes'])) foreach ($this->config['badregexes'] as $regex) $msg=preg_replace($regex.'e','"[ ".implode(" ",str_split("$0"))." ]"',$msg);
     return $this->write_client_irc_from_client('PRIVMSG',array($ircchan,$msg));
    }
    return TRUE;
   case 'ENC': return TRUE;
   default:
debug('udpmsg4',1,"received CMD=".$p['CMD']);
    return FALSE;
  }
 }
 function send_status () {
  $p=array('CMD'=>'X-srn.ano-relaystatus','SRC'=>$this->nick(),'lag'=>$this->last_ping-$this->last_pong,'priority'=>$this->config['priority']);
  $p=$this->udpmsg4_client->create_frame($p);
  return $p;
 }
 function loop () {
  $this->poll->add_alarm(time()+60,'pingtimer');
  $this->last_ping=$this->last_pong=time();
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
   default:
    $time=time();
    $this->poll->add_alarm($time+5,'pingtimer');
    $this->write_client_irc_from_client('PING',array('YouThere?'));
    $this->write_hub($this->send_status()->framed());
    if ($this->last_ping>$this->last_pong+$this->config['timeout']) {
     $this->write_hub($this->udpmsg4_client->send_quit('too much lag ('.$this->last_ping.'-'.$this->last_pong.'>'.$this->config['timeout'].')',$this->nick())->framed());
     die("server died");
    }
    $this->last_ping=$time;
    break;
  }
echo "reached to end\n";
  return NULL;
 }
 function complain ($p) {}
}

include 'cloudc2sd.config.php';

$relay=cloudc2sd::construct(fopen('php://fd/6','r'),fopen('php://fd/7','w'),fopen('php://fd/0','r'),fopen('php://fd/1','w'),$config);

$relay->loop();

?>
