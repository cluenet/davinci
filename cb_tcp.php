#!/usr/bin/env php
<?php

chdir(__DIR__);
require("cb_config.php");
require("cb_functions.php");

function handle_req($req, $out) {
	$args = explode(" ", $req);
	$cmd = array_shift($args);

	switch ($cmd) {
	case "points":
		foreach ($args as $nick) {
			$points = user_get_points($nick);
			fwrite($out, "$nick:$points\n");
		}
		fwrite($out, "OK\n");
		break;
	default:
		fwrite($out, "lolz\n");
	}
}

$users = get_db();

$req = rtrim(fgets(STDIN, 512));

handle_req($req, STDOUT);
