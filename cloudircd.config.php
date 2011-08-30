<?php

$config=array(
 'udpmsg4_client'=>array(
  'pubkey'=>rtrim(file_get_contents('pubkey')),
  'seckey'=>rtrim(file_get_contents('seckey')),
 ),
 'ircnet'=>$_ENV['ircnet'],
 'hostname'=>$_ENV['hostname'],
 'motd'=>rtrim(file_get_contents('motd.txt')),
// 'nick_map_function'=>create_function('$nick','return str_rot13($nick);'),
// 'nick_unmap_function'=>create_function('$nick','return str_rot13($nick);'),
);

?>
