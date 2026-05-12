<?php
$config = require __DIR__ . '/../config/config.php';
$query = http_build_query([
  'app_id'=>$config['app_id'],
  'l'=> 'EN',
  'brand'=>'deriv',
  'redirect_uri'=>$config['oauth_redirect_uri'],
]);
header('Location: https://oauth.deriv.com/oauth2/authorize?' . $query);
