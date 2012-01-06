<?php

$config=array(
 'udpmsg4_client'=>array(
  'pubkey'=>rtrim(file_get_contents('pubkey')),
  'seckey'=>rtrim(file_get_contents('seckey')),
 ),
 'ircnet'=>getenv('ircnet'),
 'hostname'=>getenv('hostname'),
 'nick'=>'u',
// 'pass'=>'strongpassword',
 'authtype'=>'nickserv2',
 'throttle'=>TRUE,
 'colors'=>TRUE,
// 'badregexes'=>array(
//  '/abadword/',
//  '/a +bad +regex/',
// ),
// 'kickers'=>array(
//  'chat/anonet'=>array('Anobot','nick'),
// ),
 'channels'=>array(
  'chat/anonet'=>TRUE,
  'chat/talk'=>TRUE,
  'chat/dn42'=>TRUE,
  'chat/relay'=>TRUE,
 ),
// 'nick_map_function'=>create_function('$nick','return str_rot13($nick);'),
// 'nick_unmap_function'=>create_function('$nick','return str_rot13($nick);'),
);

?>
