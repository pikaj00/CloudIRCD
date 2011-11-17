<?php

$config=array(
 'udpmsg4_client'=>array(
  'pubkey'=>rtrim(file_get_contents('pubkey')),
  'seckey'=>rtrim(file_get_contents('seckey')),
 ),
 'ircnet'=>getenv('ircnet'),
 'oldircnet'=>getenv('oldircnet'),
 'hostname'=>getenv('hostname'),
 'motd'=>rtrim(file_get_contents('motd.txt')),
// 'nick_map_function'=>create_function('$nick,$ircd','$nick=explode("/",$nick); array_shift($nick); array_push($nick,array_shift($nick)); return implode("/",$nick);'),
// 'nick_unmap_function'=>create_function('$nick,$ircd','if ($nick[0]==="#") return FALSE; $nick=explode("/",$nick); if (count($nick)===1) return "/".$ircd->config["ircnet"]."/".$nick[0]; array_unshift($nick,array_pop($nick)); array_unshift($nick,""); return implode("/",$nick);'),
);

?>
