<?php

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

?>
