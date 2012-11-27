#!/usr/bin/php
<?php
/**
* array_get value from array/object/et
*
* @param $neelde
* @param $haystack
* @param $default
*/
function array_get($needle, $haystack, $default=null) {
	$value = $default;
	if ($haystack) {
		if (is_array($haystack)) {
			if (isset($haystack[$needle])) {
				$value = $haystack[$needle];
			}
		}
		elseif (is_object($haystack)) {
			if (isset($haystack->{$needle})) {
				$value = $haystack->{$needle};
			}
		}
	}
	return $value;
}

$cwd = dirname(__FILE__);
$color = array(
	'BLACK'     => "\033[30m"
	, 'RED'     => "\033[31m"
	, 'GREEN'   => "\033[32m"
	, 'YELLOW'  => "\033[33m"
	, 'BLUE'    => "\033[34m"
	, 'MAGENTA' => "\033[35m"
	, 'CYAN'    => "\033[36m"
	, 'WHITE'   => "\033[37m"
	, 'UNDEF'   => "\033[38m"
	, 'RESET'   => "\033[39m"
);

$json = `wget -q "http://forecast.weather.gov/MapClick.php?lat=43.98886243884903&lon=-121.68182373046875&site=pdt&smap=1&unit=0&lg=en&FcstType=json" -O -`;
$obj = json_decode($json);
$data = array_get('data', $obj, array());
$text = array_get('text', $data, array());

$time = array_get('time', $obj, array());
$days = array_get('startPeriodName', $time, array());

$n = count($days);
for($i=0; $i<$n; $i++) {
	print "{$color['BLUE']}{$days[$i]}{$color['RESET']}\n";

	$desc = wordwrap($text[$i], 72);
	$desc = preg_replace("/\.\s*/", ". ", $desc);
	$desc = preg_replace("/([Ss]now)/", $color['RED'] . "$1" . $color['RESET'], $desc);

	print "{$desc}\n\n"; 
}

