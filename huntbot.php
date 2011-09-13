<?php

function debug ($sub,$pri,$str) { fprintf(STDERR,getmypid().":$sub:$pri [%s]\n",$str); }

include 'libudpmsg4.php';

include 'poll.php';

class huntbot {
 var $hub;
 var $hub_buffer;
 var $config=array();
 var $poll;
 var $udpmsg4_client;
 static function write_fd ($fd,$data,$tlen=0) {
  if (($len=fwrite($fd,$data))<=0) return $tlen;
  if ($len===strlen($data)) return $tlen+$len;
  return self::write_fd($fd,substr($data,$len),$tlen+$len);
 }
 function write_hub ($data) {
  if (self::write_fd($this->hub[1],$data)!=strlen($data)) return FALSE;
  return TRUE;
 }
 function send_message ($dst,$msg) {
  $tp=$this->udpmsg4_client->send_message($dst,$msg);
  if ($tp===FALSE) die("send '$msg' fail\n");
  return $this->write_hub($tp->framed());
 }
 static function construct ($hub_r,$hub_w,$config=NULL) {
  $new = new huntbot;
  $new->hub=array($hub_r,$hub_w);
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
  if (!isset($new->config['udpmsg4_client']['user']))
   if (!isset($new->config['user'])) return FALSE;
   else $new->config['udpmsg4_client']['user']=$new->config['user'];
  $new->udpmsg4_client = new udpmsg4_client($new->config['udpmsg4_client']);
  $new->poll = new poll;
  $new->poll->add_readfd($hub_r);
  $new->config['hungry']=array();
  return $new;
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
 }
 function nick () {
  return '/'.$this->config['ircnet'].'/'.$this->config['user'];
 }
 function is_channel ($channel) {
  if ($channel[0]==='/') return FALSE;
  if (preg_match(',^chat/,',$channel)) return TRUE;
  return FALSE;
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
 function find_next_hungry () {
  foreach ($this->config['hungry'] as $nick => $value)
   if ($value) return $nick;
  return NULL;
 }
 function udpmsg4_do ($p) {
  if (($p===FALSE)||($p===NULL)) return $p;
  if (!is_a($p,'udpmsg4_packet')) $p=udpmsg4_packet::parse($p);
  if (($p===FALSE)||($p===NULL)) return $p;
  if (!isset($p['CMD'])) return TRUE;
  switch($p['CMD']) {
   case 'ALIVE':
    return TRUE;
   case 'JOIN':
    return TRUE;
   case 'PART':
    return TRUE;
   case 'QUIT':
    return TRUE;
   case 'MSG':
    switch($p['DST']) {
     case 'chat/talk': case 'chat/anonet':
      if ($p['MSG']==="I'm hungry.") {
       $this->config['hungry'][$p['SRC']]=1;
       return $this->send_message($p['DST'],"srnbot: I'm hungry.");
      } else if ($this->nick2shortnick($p['SRC'])==='sevilBot') {
       if (preg_match('/bagged a ([0-9.KkGg]+) (.*)\.$/',$p['MSG'],$m)) {
        if (($hungry=$this->find_next_hungry())===NULL)
         return $this->send_message($p['DST'],'Nobody else is hungry so I can eat the '.$m[2].'.');
        unset($this->config['hungry'][$hungry]);
        return $this->send_message($p['DST'],$hungry.': Now you can enjoy to eat your '.$m[1].' '.$m[2].'.');
       } else if (preg_match('/missed/',$p['MSG'])) {
        if (($hungry=$this->find_next_hungry())===NULL)
         return $this->send_message($p['DST'],'It does not matter.');
        return $this->send_message($p['DST'],'srnbot: You suck.  Now I need to clean my gun for that '.$hungry.' will eat.') && $this->send_message($p['DST'],'!hunt');
       } else if (preg_match('/hogging all the best pitches (.*?), /',$p['MSG'],$m)) {
        if (($hungry=$this->find_next_hungry())===NULL)
         return $this->send_message($p['DST'],'It does not matter.');
        if ($m[1]===$this->nick())
         return $this->send_message($p['DST'],'!hunt');
        return $this->send_message($p['DST'],'LOL, k, '.$m[1].' should rest.') && $this->send_message($p['DST'],$hungry.': No worry, I will try to hunt for you.') && $this->send_message($p['DST'],'!hunt');
       } else return TRUE;
      }
    }
    return TRUE;
   case 'ENC': return TRUE;
   default:
debug('udpmsg4',1,"received CMD=".$p['CMD']);
    return TRUE;
  }
 }
 function loop () {
  foreach ($this->poll as $key => $value) switch ($key) {
   case 'r':
    if ($value===$this->hub[0]) {
     for ($p=$this->udpmsg4_parse($this->hub_buffer,$this->hub[0]); $p!==NULL; $p=$this->udpmsg4_parse($this->hub_buffer))
      if ($p===FALSE) return FALSE;
      else if ($this->udpmsg4_do($p)===FALSE) die("udpmsg4_do fail\n");
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

include 'huntbot.config.php';

$bot=huntbot::construct(fopen('php://fd/6','r'),fopen('php://fd/7','w'),$config);

$bot->loop();

?>
