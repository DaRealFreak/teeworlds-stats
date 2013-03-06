<?php

function array_unset_empty(&$array) {
	foreach($array as $key => $value)
		if(empty($array[$key]))
			unset($array[$key]);
}


function array_translate($array, $keys) {
	$res = array();
	foreach($keys as $a => $b)
		if(isset($array[$a]))
			$res[$b] = $array[$a];
	return $res;
}


function f($t){ return is_string($t) ? utf8_encode($t) : $t; }

/** Returns a utf8 encoded JSON object */
function json_enc($array) {
	return json_encode( array_map( "f", $array ) );
}

/** Converts contained strings to utf8 */
function array_utf($array) {
	return array_map( "f", $array );
}


function g($t){ return is_string($t) ? utf8_decode($t) : $t; }

/** Returns an iso decoded array */
function json_dec($array) {
	return array_map( "g", json_decode( $array ) );
}

/** Converts contained strings to iso */
function array_iso($array) {
	return array_map( "g", $array );
}

function _empty($val) { return $val !== ''; }

function eimplode($delim, $array) {
	return implode($delim, array_filter($array,"_empty"));
}

?>