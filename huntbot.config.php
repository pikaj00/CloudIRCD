<?php

$config=array(
 'udpmsg4_client'=>array(
  'pubkey'=>rtrim(file_get_contents('pubkey')),
  'seckey'=>rtrim(file_get_contents('seckey')),
 ),
 'ircnet'=>getenv('ircnet'),
 'user'=>'huntbot',
);

?>
