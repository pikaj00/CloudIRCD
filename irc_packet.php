<?php

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
debug('irc',1,"line=$line");
  $list=explode(' :',$line,2);
  $parts=explode(' ',$list[0]);
  if (isset($list[1])) array_push($parts,$list[1]);
  if ($line[0]===':') $prefix=substr(array_shift($parts),1); else $prefix=NULL;
  $cmd=array_shift($parts);
  return new irc_packet(array('prefix'=>$prefix,'cmd'=>$cmd,'args'=>$parts));
 }
 static function build ($prefix,$cmd,$args=NULL) {
  return new self(array('prefix'=>$prefix,'cmd'=>$cmd,'args'=>$args));
 }
 function __construct ($array) {
  $this->prefix=strlen($array['prefix'])?str_replace(array(' ',"\n","\r"),'',$array['prefix']):$array['prefix'];
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

?>
