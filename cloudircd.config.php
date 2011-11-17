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
);

foreach (array('nick_map_function'=>'$nick,$ircd','nick_unmap_function'=>'$nick,$ircd') as $key => $sig) if (file_exists($key)) $config[$key]=create_function($sig,file_get_contents($key));

?>
