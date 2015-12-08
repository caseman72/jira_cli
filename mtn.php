#!/usr/bin/php
<?php

/**
* array_get value from array/object/ete
*
* @param $needle
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
	'BLACK'   => "\033[30m",
	'RED'     => "\033[31m",
	'GREEN'   => "\033[32m",
	'YELLOW'  => "\033[33m",
	'BLUE'    => "\033[34m",
	'MAGENTA' => "\033[35m",
	'CYAN'    => "\033[36m",
	'WHITE'   => "\033[37m",
	'UNDEF'   => "\033[38m",
	'RESET'   => "\033[39m"
);

$html = `wget -q "http://www.mtbachelor.com/site/plan/info/winterconditions" -O -`;
$html = preg_replace("/ [&] /", " &amp; ", $html);
$html = preg_replace("/<svg.*?<\/svg>/s", '', $html);
$html = preg_replace("/(<\/?)(?:header|footer|nav|section)/", '$1div', $html);
$html = preg_replace("/href=\"([^\"]*)\"/e", '\'href="\' . preg_replace("/[&]/", "&amp;", "$1") . \'"\'' , $html);

$dom = new DomDocument();
$dom->loadHTML($html);
$xpath = new DOMXpath($dom);

$xpaths = array(
	'report'            => $xpath->query('//div[@class="conditionscomments-block"]//p[1]'),
	'report date'       => $xpath->query('//table[@class="full layout"]//tr//td[1]//h3'),
	'report time'       => $xpath->query('//table[@class="full layout"]//tr//td[2]//h3//strong'),
	'since 6am today'   => $xpath->query('//table[@class="snow-conditions-table layout full"][2]//tr[1]//td[1]//p//span'),
	'24 hour snowfall'  => $xpath->query('//table[@class="snow-conditions-table layout full"][2]//tr[1]//td[2]//p//span'),
	'base depth'        => $xpath->query('//table[@class="snow-conditions-table layout full"][2]//tr[1]//td[8]//p//span'),
	'mid depth'         => $xpath->query('//table[@class="snow-conditions-table layout full"][1]//tr[1]//td[8]//p//span'),
	'summit temp'       => $xpath->query('//table[@id="conditions-weather-table"]//tr[2]//td[1]//p[1]//strong'),
	'mid mountain temp' => $xpath->query('//table[@id="conditions-weather-table"]//tr[2]//td[1]//p[2]//strong'),
	'base area temp'    => $xpath->query('//table[@id="conditions-weather-table"]//tr[2]//td[1]//p[3]//strong'),
);

echo $color['BLACK'], 'http://www.mtbachelor.com/site/plan/info/winterconditions', $color['RESET'], "\n";
foreach($xpaths as $key => $elements) {
	if (!is_null($elements)) {
		foreach ($elements as $element) {
			$value = '';
			foreach ($element->childNodes as $node) {
				$value .= preg_replace("/[^ -~]/", ' ', trim($node->nodeValue));
			}
			$value = preg_replace("/[ ]+/", ' ', trim($value));

			if ($key === 'report') {
				echo "\n", $color['BLACK'], wordwrap($value, 72), $color['RESET'], "\n\n";
				echo $color['BLUE'], 'Values', $color['RESET'], "\n";
			}
			elseif (preg_match("/temp/", $key)) {
				echo '  ', str_pad(ucwords($key), 18), ' - ', (intval($value) < 32 ? $color['RED'] : $color['BLACK']), $value, $color['RESET'], "\n";
			}
			elseif (preg_match("/depth|report/", $key)) {
				echo '  ', str_pad(ucwords($key), 18), ' - ', $color['BLACK'], $value, $color['RESET'], "\n";
			}
			elseif (preg_match("/snowfall|since/", $key)) {
				echo '  ', str_pad(ucwords($key), 18), ' - ', (intval($value) > 0 ? $color['RED'] : $color['BLACK']), $value, $color['RESET'], "\n";
			}
		}
	}
}


# from forecast.weather.gov - at lat/lon for mt. bachelor - as JSON
$json = `wget -q "http://forecast.weather.gov/MapClick.php?lat=43.98886243884903&lon=-121.68182373046875&FcstType=json" -O -`;
$obj = json_decode($json);

# descriptions
$key_text = 'text';
$text = array_get($key_text, array_get('data', $obj, array()), array());

# days of the week
$key_days = 'startPeriodName';
$days = array_get($key_days, array_get('time', $obj, array()), array());
$n = count($days);

# output
echo "\n", $color['BLACK'], 'http://forecast.weather.gov/MapClick.php?lat=43.98886243884903&lon=-121.68182373046875', $color['RESET'], "\n";
for($i=0; $i<$n; $i++) {
	echo $color['BLUE'], trim($days[$i]), $color['RESET'], "\n";

	$desc = trim($text[$i]);
	$desc = preg_replace("/\.\s*/", ". ", $desc);
	$desc = wordwrap($desc, 72);
	$desc = preg_replace("/([Ss]now)/", $color['RED'] ."$1". $color['RESET'], $desc);
	$desc = preg_replace("/\n/", "\n  ", $desc);
	echo '  ', $desc, "\n\n";
}

