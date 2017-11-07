<?php

require_once("library/Facebook/autoload.php");

$facebook = new \Facebook\Facebook(array(
    'app_id' => '133904623933394',
    'app_secret' => '',
));


try {
    // Returns a `Facebook\FacebookResponse` object
    $response = $facebook->get('/me?fields=id,name', '133904623933394|vcXEANJfGqkKCSzINDGXsIZHJh0');
} catch(Facebook\Exceptions\FacebookResponseException $e) {
    echo 'Graph returned an error: ' . $e->getMessage();
    exit;
} catch(Facebook\Exceptions\FacebookSDKException $e) {
    echo 'Facebook SDK returned an error: ' . $e->getMessage();
    exit;
}

$user = $response->getGraphUser();

echo 'Name: ' . $user['name'];


?>