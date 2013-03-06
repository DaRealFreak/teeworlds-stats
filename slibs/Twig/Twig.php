<?php

require(dirname(__FILE__) . "/Twig/Autoloader.php");

Twig_Autoloader::register();

$loader = new Twig_Loader_Filesystem(".");

$twig = new Twig_Environment($loader, array(
  /*'cache' => dirname(__FILE__) . '/cache',*/
  'charset' => 'ISO-8859-1',
  'autoescape' => false
));

$lexer = new Twig_Lexer($twig, array(
	'tag_comment'  => array('{*', '*}'),
	'tag_block'    => array('{{', '}}'),
	'tag_variable' => array('{$', '}'),
));

$twig->setLexer($lexer);

?>
