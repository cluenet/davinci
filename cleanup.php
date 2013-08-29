<?php

include 'cb_functions.php';

$users = get_db();

print_r($users['grawity']);

foreach ($users as $nick => &$data) {
	$keys = array_keys($data["log"]);
	foreach ($keys as $k) {
		$n = preg_replace('/ [+-][0-9]+$/', '', $k);
		$data["log"][$n] = $data["log"][$k];
		unset($data["log"][$k]);
	}
}

save_db();
