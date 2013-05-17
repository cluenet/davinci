<?php
// vim: noet

require "libirc.php";

### Misc utilities

function strescape($str) {
	return addcslashes($str, "\x00..\x1F\x7F..\xFF\\");
}

function mysort($a,$b) {
	if(!isset($a)) $a = 0;
	if(!isset($b)) $b = 0;
	return($a == $b) ? 0 :
		($a > $b) ? 1 : -1;
}

### Logging

function _log_open() {
	global $log_fh;
	if (!isset($log_fh))
		$log_fh = fopen("points.log", "a");
	return $log_fh;
}

function log_str($str) {
	$time = time();
	$fh = _log_open();
	fprintf($fh, "%d %s\n", $time, $str);
	printf("%s %s\n", date("c", $time), $str);
}

function log_strv(/*$fmt, @args*/) {
	$args = func_get_args();
	$fmt = array_shift($args);
	foreach ($args as &$v)
		if ($v === null)
			$v = "-";
	return log_str(vsprintf($fmt, $args));
}

### User database (high-level)

function user_is_admin($nick) {
	global $users;
	$nick = nicktolower($nick);

	return (bool) @$users[$nick]['admin'];
}

function user_make_admin($caller, $nick) {
	global $users;
	$nick = nicktolower($nick);

	@$users[$nick]['admin'] = true;
	save_db();

	log_strv("%s grant:admin %s", $caller, $nick);
}

function user_is_ignored($nick) {
	global $users;
	$nick = nicktolower($nick);

	return (bool) @$users[$nick]['ignore'];
}

function user_set_ignored($caller, $nick, $ignore) {
	global $users;
	$nick = nicktolower($nick);

	@$users[$nick]["ignore"] = $ignore;

	log_strv("%s ignore %s ignored=%s points=%d",
		$caller, $nick, $ignore ? "y" : "n", @$users[$nick]["points"]);
}

function user_get_stats($nick) {
	global $users;
	$nick = nicktolower($nick);

	if (is_array(@$users[$nick]["log"])) {
		$stats = "";
		foreach (@$users[$nick]["log"] as $reason => $count)
			$stats .= "$reason: $count. ";
		return rtrim($stats);
	} else {
		return "No stats.";
	}
}

function user_get_points($nick) {
	global $users;
	$nick = nicktolower($nick);

	return (int) @$users[$nick]["points"];
}

function user_adj_points($nick, $delta, $reason) {
	return user_adj_points_by(null, $nick, $delta, $reason);
}

function user_adj_points_by($caller, $nick, $delta, $reason) {
	global $users;
	$nick = nicktolower($nick);

	if (@$users[$nick]["ignore"])
		return;

	@$users[$nick]["points"] += $delta;
	@$users[$nick]["log"][$reason]++;
	save_db();

	log_strv("%s change %s %s%d \"%s\"",
		@$caller, $nick,
		($delta < 0 ? "" : "+"), $delta,
		strescape($reason));

	if ($caller !== null) {
		$do_log = true;
		$reason .= " by $caller";
	} elseif ($delta >= 0) {
		$do_log = @$users[$nick]["verbose"]
			&& !@$users[$nick]["vdedo"];
	} else {
		$do_log = @$users[$nick]["verbose"];
	}

	if ($do_log)
		send("NOTICE", $nick, "$reason ($delta points)");

	return $delta;
}

function user_reset_points($caller, $nick) {
	global $users;
	$nick = nicktolower($nick);

	$old = @$users[$nick];

	unset($users[$nick]);
	save_db();

	log_strv("%s reset %s oldpoints=%d",
		$caller, $nick, @$old["points"]);
}

function user_merge($caller, $old_user, $new_user) {
	global $users;
	$old_user = nicktolower($old_user);
	$new_user = nicktolower($new_user);

	$old_points = user_get_points($old_user);
	user_adj_points_by($caller, $new_user, $old_points, "Administratively changed");
	user_reset_points($caller, $old_user);
	save_db();

	log_strv("%s merge %s %s %s", $caller, $old_user, $new_user, $old_points);
}

function gettop($bottom=false) {
	global $users;
	foreach ($users as $nick => $data) {
		if (isset($data["points"]))
			$tmp[$nick] = $data["points"];
	}
	uasort($tmp, "mysort");
	if ($bottom)
		return array_slice($tmp, 0, 3);
	else
		return array_slice($tmp, -3);
}

### Database operations

const DB_FILE = 'users.db';

function get_db() {
	$ret = unserialize(file_get_contents(DB_FILE));
	return $ret;
}

function save_db() {
	global $users;
	global $locked;
	if($locked) { return; }
	file_put_contents(DB_FILE,serialize($users));
}
?>
