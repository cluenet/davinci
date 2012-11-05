<?php
// vim: noet

### IRC protocol stuff

class Prefix {
	public $nick;
	public $user;
	public $host;

	public function __construct($nick, $user, $host) {
		$this->nick = $nick;
		$this->user = $user;
		$this->host = $host;
	}
}

function ircexplode($str) {
	$str = rtrim($str, "\r\n");
	$pos = strpos($str, " :");
	if($pos === false)
		$trailing = null;
	else {
		$trailing = substr($str, $pos+2);
		$str = substr($str, 0, $pos);
	}
	$params = explode(" ", $str);
	if($trailing !== null)
		$params[] = $trailing;
	return $params;
}

function ircimplode($params) {
	$trailing = array_pop($params);
	if(strpos($trailing, " ") !== false
	or strpos($trailing, ":") !== false) {
		$trailing = ":".$trailing;
	}
	$params[] = $trailing;
	$str = implode(" ", $params) . "\r\n";
	return $str;
}

function prefixparse($prefix) {
	if($prefix === null)
		return new Prefix(null, null, null);

	$npos = $prefix[0] == ":" ? 1 : 0;
	$upos = strpos($prefix, "!", $npos);
	$hpos = strpos($prefix, "@", $upos);

	if($upos === false or $hpos === false) {
		$nick = null;
		$user = null;
		$host = substr($prefix, $npos);
	} else {
		$nick = substr($prefix, $npos, $upos++-$npos);
		$user = substr($prefix, $upos, $hpos++-$upos);
		$host = substr($prefix, $hpos);
	}

	return new Prefix($nick, $user, $host);
}

function ischannel($target) {
	return $target[0] == "#";
}

function nicktolower($nick) {
	$nick = strtolower($nick);
	$nick = strtr($nick, "[]\\", "{}|");
	return $nick;
}

function nickeq($a, $b) {
	return nicktolower($a) === nicktolower($b);
}

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
	return log_str(vsprintf($fmt, $args));
}

### User database (high-level)

function user_is_admin($nick) {
	global $users;
	$nick = nicktolower($nick);
	return (bool) @$users[$nick]['admin'];
}

function user_make_admin($nick) {
	global $users;
	$nick = nicktolower($nick);
	$users[$nick]['admin'] = true;
	save_db();
}

function user_is_ignored($nick) {
	global $users;
	$nick = nicktolower($nick);
	return (bool) @$users[$nick]['ignore'];
}

function user_set_ignored($nick, $ignore) {
	global $users;
	$nick = nicktolower($nick);

	$users[$nick]["ignore"] = $ignore;

	if ($ignore) {
		$users[$nick]["points"] = 0;
		$users[$nick]["log"] = array("Ignored =0" => 1);
		log_strv("ignore %s", $nick);
		send("NOTICE", $nick, "You are now ignored by me.");
	} else {
		unset($users[$nick]["log"]["Ignored =0"]);
		log_strv("unignore %s", $nick);
		send("NOTICE", $nick, "I stopped ignoring you.");
	}

}

function user_get_stats($nick) {
	global $users;
	$nick = nicktolower($nick);
	$tmp = "";
	foreach($users[$nick]["log"] as $reason => $count)
		$tmp .= "$reason: $count. ";
	return rtrim($tmp);
}

function user_get_points($nick) {
	global $users;
	$nick = nicktolower($nick);
	return(int) $users[$nick]["points"];
}

function user_adj_points($nick, $delta, $reason) {
	global $users;
	$nick = nicktolower($nick);
	if(@$users[$nick]["ignore"])
		return;
	@$users[$nick]["points"] += $delta;
	@$users[$nick]["log"][$reason]++;
	save_db();

	if ($reason == "Administratively changed")
		$log = @$users[$nick]["vlog"];
	elseif ($delta > 0)
		$log = @$users[$nick]["verbose"];
	else
		$log = @$users[$nick]["vdedo"];
	if ($log)
		send("NOTICE", $nick, "$reason ($delta points)");

	log_strv("change %s %s%d \"%s\"",
		$nick, ($delta < 0 ? "" : "+"), $delta, strescape($reason));
	
	return $delta;
}

function user_reset_points($caller, $nick) {
	global $users;
	$nick = nicktolower($nick);

	unset($users[$nick]);
	save_db();
	log_strv("reset %s", $nick);

	if (!nickeq($nick, $caller))
		send("NOTICE", $nick, "Your ClueBot account was reset by $caller.");
}

function user_merge($old_user, $new_user) {
	global $users;
	$old_user = nicktolower($old_user);
	$new_user = nicktolower($new_user);

	$old_points = user_get_points($old_user);
	user_adj_points($new_user, $old_points, "Merged with $old_user");
	user_reset_points($old_user);
	save_db();
}

function gettop($bottom = false) {
	global $users;
	foreach($users as $nick => $data) {
		$tmp[$nick] = $data['points'];
	}
	uasort($tmp,'mysort');
	if($bottom == false) { $tmp = array_reverse($tmp,true); }
	$i = 0;
	foreach($tmp as $nick => $pts) {
		$i++;
		$tmp2[$nick] = $pts;
		if($i >= 3) {
			break;
		}
	}
	if($bottom == true) { $tmp2 = array_reverse($tmp2,true); }
	return $tmp2;
}

function mysqlconn($user,$pass,$host,$port,$database) {
	global $mysql;
	$mysql = mysql_connect($host.':'.$port,$user,$pass);
	if(!$mysql) {
		die('Can not connect to MySQL!');
	}
	if(!mysql_select_db($database,$mysql)) {
		die('Can not access database!');
	}
}

const DB_FILE = 'users.db';

function get_db() {
	$ret = unserialize(file_get_contents(DB_FILE));
//	global $mysql;
//	$ret = array();
//	$res = mysql_query('SELECT * FROM `users`');
//	while($x = mysql_fetch_array($res)) {
//		$ret[$x['nick']] = array(
//			'ignore' => $x['ignore'],
//			'admin' => $x['admin'],
//			'points' => $x['points'],
//			'verbose' => $x['verbose'],
//			'vdedo' => $x['vdedo'],
//			'vlog' => $x['vlog'],
//			'log' => unserialize($x['log'])
//		);
//	}
	return $ret;
}

function save_db() {
	global $users;
//	global $mysql;
	global $locked;
	if($locked) { return; }
	file_put_contents(DB_FILE,serialize($users));
//	mysql_query('TRUNCATE `users`');
//	foreach($users as $nick => $data) {
//		$query  = 'INSERT INTO `users` ';
//
//		$query .= '(`id`,`nick`,`points`,';
//		$query .= '`ignore`,`admin`,`log`,';
//		$query .= '`verbose`,`vdedo`,`vlog`) ';
//
//		$query .= 'VALUES (NULL,\''.mysql_real_escape_string($nick).'\',';
//		$query .= '\''.mysql_real_escape_string($data['points']).'\',';
//		$query .= '\''.mysql_real_escape_string($data['ignore']).'\',';
//		$query .= '\''.mysql_real_escape_string($data['admin']).'\',';
//		$query .= '\''.mysql_real_escape_string(serialize($data['log'])).'\',';
//		$query .= '\''.mysql_real_escape_string($data['verbose']).'\',';
//		$query .= '\''.mysql_real_escape_string($data['vdedo']).'\',';
//		$query .= '\''.mysql_real_escape_string($data['vlog']).'\')';
//
//		mysql_query($query);
//	}
}

function get_full_entry($nick, $entry) {
	return $nick .
		':' . $entry['points'] .
		':' . $entry['ignore'] .
		':' . $entry['admin'] .
		':' . $entry['verbose'] .
		':' . $entry['vdedo'] .
		':' . $entry['vlog'] .
		':' . $entry['creation'] .
		':' . $entry['log']['Normal sentence +1'] .
		':' . $entry['log']['Abnormal sentence -1'] .
		':' . $entry['log']['No vowels -30'] .
		':' . $entry['log']['Use of r, R, u, or U -40'] .
		':' . $entry['log']['Administratively changed'] .
		':' . $entry['log']['Clueful sentence +2'] .
		':' . $entry['log']['All caps -20'] .
		':' . $entry['log']['Use of r, R, u, or U -40'] .
		':' . $entry['log']['Lower-case personal pronoun -5'] .
		':' . $entry['log']['Use of profanity -20'] .
		':' . $entry['log']['Use of non-printable ascii characters -5'] .
		"\n";
}

function api_handle_print($nick, $what, $entry) {
	switch($what) {
		case 'full':
			return get_full_entry($nick, $entry);

		case 'points':
			return $nick . ":" . user_get_points($nick) . "\n";

		case 'shortpoints':
			return user_get_points($nick) . "\n";

		case 'header':
			return 'Nick:Points:IgnoreFlag:AdminFlag:VerboseSetting:' . 
					'VDeductionsSetting:VLogSetting:FirstSeenTime:' .
					'NormalSentences:AbnormalSentences:NoVowels:' .
					'OLDLameAbbreviations:AdministrativelyChanged:' . 
					'CluefulSentences:AllCaps:LameAbbreviations:' .
					'LowercaseI:Profanity:NonprintableASCII' . "\n";

		case 'usage':
			return "Usage:\n"
				. "  cluebot dump [Nick1,Nick2,...]\n"
				. "  cluebot points [Nick1,Nick2,...]\n"
				. "  cluebot shortpoints <Nick>\n"
				. "  cluebot dumpheader\n";
	}
}

function api_return_entries($sock, $what, $nicks=Null) {
	$data = get_db();

	if($nicks == NULL)
		foreach($data as $nick => $entry)
			fwrite($sock, api_handle_print($nick, $what, $entry));
	elseif(is_array($nicks))
		foreach($nicks as $nick)
			fwrite($sock, api_handle_print($nick, $what, $data[strtolower($nick)]));
	else
		api_return_entries($sock, $what, explode(',', $nicks));
}
?>
