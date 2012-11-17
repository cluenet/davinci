<?php
// vim: noet

include 'cb_config.php';
include 'cb_functions.php';

const RPL_WELCOME	= '001';
const RPL_ISUPPORT	= '005';
const RPL_ENDOFMOTD	= '376';
const ERR_NOMOTD	= '422';
const ERR_NICKNAMEINUSE	= '433';

function send(/*@args*/) {
	global $socket;

	$args = func_get_args();
	$str = ircimplode($args);
	return fwrite($socket, $str);
}

function on_connect() {
	global $config;

	if (strlen(@$config["irc_pass"]))
		send("PASS", $config["irc_pass"]);

	send("USER", $config["user"], "1", "1", $config["gecos"]);
	send("NICK", $config["nick"]);
}

function on_register() {
	global $config;
	global $mynick;

	if (strlen(@$config["irc_mode"]))
		send("MODE", $mynick, $config["irc_mode"]);

	send("JOIN", implode(",", $config["channels"]));
}

function on_trigger($source, $target, $message) {
	global $config;
	global $users;

	$srcnick = $source->nick;
	$user = &$users[nicktolower($srcnick)];

	$args = explode(" ", $message);
	$cmd = strtolower(substr($args[0], 1));

	$bottom = false;
	$ignore = true;

	switch ($cmd) {
	case 'verbose':
		if ($user['verbose']) {
			$user['verbose'] = false;
			$user['vdedo'] = false;
			send("NOTICE", $srcnick, "Point change notices disabled.");
		} else {
			$user['verbose'] = true;
			$user['vdedo'] = false;
			send("NOTICE", $srcnick, "Will notice you of every point change.");
		}
		break;
	case 'vdeductions':
		$user['verbose'] = true;
		if ($user['vdedo']) {
			$user['vdedo'] = false;
			send("NOTICE", $srcnick, "Will notice you of every point change.");
		} else {
			$user['vdedo'] = true;
			send("NOTICE", $srcnick, "Will notice you only of negative point changes.");
		}
		break;
	case 'vlog':
		send("NOTICE", $srcnick, ".vlog mode is now always enabled.");
		break;
	case 'points':
		$who = $args[1] ? $args[1] : $srcnick;
		$pts = user_get_points($who);
		send("NOTICE", $srcnick, "$who has $pts points.");
		break;
	case 'bottom':
	case 'lamers':
		$bottom = true;
	case 'top':
		$top = gettop($bottom);
		foreach ($top as $who => $pts)
			send("NOTICE", $srcnick, "$who has $pts points.");
		break;
	case 'stats':
		$who = $args[1] ? $args[1] : $srcnick;
		$stats = user_get_stats($who);
		send("NOTICE", $srcnick, "$who's stats:");
		send("NOTICE", $srcnick, $stats);
		break;
	case 'unignore':
		$ignore = false;
	case 'ignore':
		if (count($args) <= 1) {
			send("NOTICE", $srcnick, "Usage: $cmd <user>");
			break;
		}
		if (user_is_admin($srcnick)) {
			$victim = $args[1];
			user_set_ignored($srcnick, $victim, $ignore);
			if ($ignore) {
				send("NOTICE", $srcnick, "$victim is now ignored.");
				send("NOTICE", $victim, "You are now ignored.");
			} else {
				send("NOTICE", $srcnick, "$victim is not ignored anymore.");
				send("NOTICE", $victim, "You are not ignored anymore.");
			}
		} else {
			send("NOTICE", $srcnick, "Access denied.");
		}
		break;
	case 'lock':
		if (user_is_admin($srcnick)) {
			$locked = !$locked;
			if ($locked)
				send("NOTICE", $srcnick, "The database is now in read-only mode.");
			else
				send("NOTICE", $srcnick, "The database is now in read-write mode.");
		} else {
			send("NOTICE", $srcnick, "Access denied.");
		}
		break;
	case 'makeadmin':
		if (count($args) <= 1) {
			send("NOTICE", $srcnick, "Usage: $cmd <user>");
			break;
		}
		if (user_is_admin($srcnick)) {
			$victim = $args[1];
			user_make_admin($srcnick, $victim);
			send("NOTICE", $srcnick, "$victim is now an admin.");
			send("NOTICE", $victim, "$srcnick just made you an admin.");
		} else {
			send("NOTICE", $srcnick, "Access denied.");
		}
		break;
	case 'reload':
		if (user_is_admin($srcnick)) {
			$users = get_db();
			send("NOTICE", $srcnick, "Internal database reloaded according to the MySQL database.");
		} else {
			send("NOTICE", $srcnick, "Access denied.");
		}
		break;
	case 'merge':
		if (count($args) <= 2) {
			send("NOTICE", $srcnick, "Usage: $cmd <old> <new>");
			break;
		}
		if (user_is_admin($srcnick)) {
			$old_user = $args[1];
			$new_user = $args[2];
			user_merge($srcnick, $old_user, $new_user);
			send("NOTICE", $srcnick, "Merged $old_user into $new_user");
		} else {
			send("NOTICE", $srcnick, "Access denied.");
		}
		break;
	case 'chgpts':
		if (count($args) <= 2) {
			send("NOTICE", $srcnick, "Usage: $cmd <user> <delta>");
			break;
		}
		if (user_is_admin($srcnick)) {
			$victim = $args[1];
			$delta = $args[2];
			user_adj_points_by($srcnick, $victim, $delta,
				"Administratively changed");
			send("NOTICE", $srcnick, "Points of $victim changed.");
		} else {
			send("NOTICE", $srcnick, "Access denied.");
		}
		break;
	case 'reset':
		if (count($args) <= 1) {
			send("NOTICE", $srcnick, "Usage: $cmd <user>");
			break;
		}
		$victim = $args[1];
		if (nickeq($srcnick, $victim)) {
			user_reset_points($srcnick, $victim);
			send("NOTICE", $victim, "Your DaVinci account was reset.");
		} elseif (user_is_admin($srcnick)) {
			user_reset_points($srcnick, $victim);
			send("NOTICE", $srcnick, "User $victim reset.");
			send("NOTICE", $victim, "Your DaVinci account was reset by $srcnick.");
		} else {
			send("NOTICE", $srcnick, "Access denied.");
		}
		break;
	case 'whoami':
		$args[1] = $srcnick;
	case 'whois':
		if (count($args) <= 1) {
			send("NOTICE", $srcnick, "Usage: $cmd <user>");
			break;
		}
		$who = $args[1];
		$pts = user_get_points($who);
		$stats = user_get_stats($who);

		if     ($pts ==  1337)	$rank = 'Clueful 3l33t';
		elseif ($pts >=  1000)	$rank = 'Clueful Elite';
		elseif ($pts >=   500)	$rank = 'Super Clueful';
		elseif ($pts >=   200)	$rank = 'Extremely Clueful';
		elseif ($pts >=    50)	$rank = 'Very Clueful';
		elseif ($pts >=    10)	$rank = 'Clueful';
		elseif ($pts >=   -10)	$rank = 'Neutral';
		elseif ($pts >=  -500)	$rank = 'Needs Work';
		elseif ($pts >= -1000)	$rank = 'Not Clueful';
		elseif ($pts >= -1500)	$rank = 'Lamer';
		else			$rank = 'Idiot';
		if ($who == "grawity")	$rank = 'Chaotic Neutral';

		send("NOTICE", $srcnick, "$who has $pts points and holds the rank of $rank.");
		send("NOTICE", $srcnick, "$who's stats: $stats");
		if (user_is_admin($who))
			send("NOTICE", $srcnick, "$who is a DaVinci administrator.");
		if (user_is_ignored($who))
			send("NOTICE", $srcnick, "$who is ignored by DaVinci.");
		break;
	}
}

function rate_message($nick, $message) {
	$nickre = '[a-zA-Z0-9\[\]_|~`]+';

	$message = preg_replace("/^$nickre: /", "", $message);

	if (
	   preg_match('!^s/.+/.+/?$!', $message)
	or preg_match('!^s(.).+\1.*\1g?$!', $message)
	or preg_match('/^([a-z]{1,3}|rot?fl|haha?|lmf?ao|bbiab|grr+|hr?m+|um+|uh+m*|er+m*|ah+)[^a-z]*$/i', $message)
	or preg_match('!(http|ftp)s?://!', $message)
	or preg_match('/^[^a-z]/i', $message)
	)
		return;

	$total = 0;

	if (preg_match('/(^| )[ru]( |$)/i', $message))
		$total += user_adj_points($nick, -40, "Use of r, R, u, or U -40");

	if (!preg_match('/[aeiouy]/i', $message))
		$total += user_adj_points($nick, -30, "No vowels -30");

	if (preg_match('/\b(cunt|fuck)\b/i', $message))
		$total += user_adj_points($nick, -20, "Use of uncreative profanity -20");

	if (preg_match('/^[^a-z]{8,}$/', $message))
		$total += user_adj_points($nick, -20, "All caps -20");

	if (preg_match('/(^| )lawl( |$)/', $message))
		$total += user_adj_points($nick, -20,
			"Use of non-clueful variation of \"lol\" -20");

	if (preg_match('/(^| )rawr( |$)/', $message))
		$total += user_adj_points($nick, -20, "Use of non-clueful expression -20");

	if (preg_match('/(^| )i( |$)/', $message))
		$total += user_adj_points($nick, -5, "Lower-case personal pronoun -5");

	// Shit, I have no idea what this does. Let's assume it works.
	if (preg_match('/^([^ ]+(:|,| -) .|[^a-z]).*(\?|\.(`|\'|")?|!|:|'.$smilies.')( '.$smilies.')?$/',$message)) {
		$total += user_adj_points($nick, +2, "Clueful sentence +2");
	} elseif (preg_match('/^([^ ]+(:|,| -) .|[^a-z]).*$/', $message)) {
		$total += user_adj_points($nick, +1, "Normal sentence +1");
	} else {
		$total += user_adj_points($nick, -1, "Abnormal sentence -1");
	}

	return $total;
}

//mysqlconn($config['mysqluser'],$config['mysqlpass'],$config['mysqlhost'],$config['mysqlport'],$config['mysqldb']);
$locked = false;
$users = get_db();
$git_hash = system('git --git-dir="' . __DIR__ . '/.git" rev-parse --verify HEAD');

if (strpos($config["server"], "://") === false)
	$uri = "tcp://{$config["server"]}:{$config["port"]}";
else
	$uri = $config["server"];

$socket = stream_socket_client($uri, $errno, $errstr, 30);
if (!$socket) {
	echo "$errstr ($errno)\n";
	exit();
}

$mynick = $config["nick"];
$nickctr = 0;

on_connect();

while (!feof($socket)) {
	$line = fgets($socket);
	if (!strlen($line))
		continue;
	$params = ircexplode($line);
	if ($params[0][0] == ":")
		$prefix = array_shift($params);
	else
		$prefix = null;
	$source = prefixparse($prefix);
	$srcnick = $source->nick;
	$cmd = strtoupper($params[0]);

	switch ($cmd) {
	case RPL_WELCOME:
		$mynick = $params[1];
		break;
	case RPL_ENDOFMOTD:
	case ERR_NOMOTD:
		on_register();
		break;
	case ERR_NICKNAMEINUSE:
		$newnick = $config["nick"] . ++$nick_ctr;
		send("NICK", $newnick);
		break;
	case "INVITE":
		$pts = user_get_points($srcnick);
		if ($pts <= 0 && !user_is_admin($srcnick))
			break;
		$target = $params[1];
		$channel = $params[2];
		send("JOIN", $channel);
		send("PRIVMSG", $channel, "\001ACTION waves at $srcnick.\001");
		break;
	case "PING":
		send("PONG", $params[1]);
		break;
	case "PRIVMSG":
		$target = $params[1];
		$message = $params[2];
		if ($message == "\001VERSION\001") {
			send("NOTICE", $srcnick, "\001VERSION DaVinci by Cluenet (HEAD is $git_hash)\001");
		} elseif ($message[0] == $config["trigger"]) {
			on_trigger($source, $target, $message);
		} elseif (ischannel($target)) {
			$delta = rate_message($srcnick, $message);
			$pts = user_get_points($srcnick);
			if ($pts <= -500 && $delta <= 0 && !user_is_admin($srcnick)) {
				send("MODE", $target, "+b", "$srcnick!*@*");
				send("KICK", $target, $srcnick, "CLUEBAAAAAAAAT");
			}
		} else {
			send("NOTICE", $srcnick, "?");
		}
	}
}
?>
