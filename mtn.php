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

# from mtbachelor.com - printable report - depends on formatting - as HTML
$html = `wget -q "http://www.mtbachelor.com/winter/mountain/snow_report/@@conditions.flyer.html" -O -`;
$html = preg_replace("/[&]quot;/", '"', $html); // this results in bad HTML but ok with the find below
$html = preg_replace("/Season Total /", "Season  ", $html); // aligns columns in output

// find Snowfall conditions
if (preg_match_all("/<th>([^<]* Snowfall)<\/th>.*?<td>([^<]+)<\/td>/s", $html, $conditions)) {
	$n = count($conditions[0]);
	$headings = $conditions[1];
	$values = $conditions[2];

	# output
	print "{$color['BLACK']}http://www.mtbachelor.com/winter/mountain/snow_report/@@conditions.flyer.html{$color['RESET']}\n";
	for($i=0; $i<$n; $i++) {
		print "  {$headings[$i]} - {$color['RED']}{$values[$i]}{$color['RESET']}\n";
	}
}

# from forecast.weather.gov - at lat/lon for mt. bachelor - as JSON
$json = `wget -q "http://forecast.weather.gov/MapClick.php?lat=43.98886243884903&lon=-121.68182373046875&FcstType=json" -O -`;
$obj = json_decode($json);

# descriptions
$data = array_get('data', $obj, array());
$text = array_get('text', $data, array());

# days of the week
$time = array_get('time', $obj, array());
$days = array_get('startPeriodName', $time, array());
$n = count($days);

# output
print "\n\n{$color['BLACK']}http://forecast.weather.gov/MapClick.php?lat=43.98886243884903&lon=-121.68182373046875{$color['RESET']}\n\n";
for($i=0; $i<$n; $i++) {
	print "{$color['BLUE']}{$days[$i]}{$color['RESET']}\n";

	$desc = trim($text[$i]);
	$desc = preg_replace("/\.\s*/", ". ", $desc);
	$desc = wordwrap($desc, 72);
	$desc = preg_replace("/([Ss]now)/", $color['RED'] ."$1". $color['RESET'], $desc);
	$desc = preg_replace("/\n/", "\n  ", $desc);
	print "  {$desc}\n\n"; 
}

