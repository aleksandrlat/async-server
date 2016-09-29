<?hh

function c() {
	$arr = [];
	return function($el) use(&$arr) {
		$arr[] = $el;
		var_dump($arr);
	};
}

$f = c();
$f(1);
$f(2);
$f(3);