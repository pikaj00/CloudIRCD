<?php

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

?>
