<?php

namespace Luper;
use Luper\Async;

function async(string|\Closure $callback) {
	return Async::create($callback);
}

