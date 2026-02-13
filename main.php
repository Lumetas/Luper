<?php
require __DIR__ . '/vendor/autoload.php';
use function Luper\async;
$hui = 123;
$asyncFunction = async(function($a, $b) use ($hui) { 
	sleep(1);
	return $a + $b + $hui; 
});	
var_dump($asyncFunction);

$promise = $asyncFunction(1, 2);
$result = $promise->await();
echo $result;
