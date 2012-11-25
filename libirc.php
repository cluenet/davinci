<?php

### IRC protocol stuff

class Message {
	public $tags;
	public $prefix;
	public $cmd;
	public $params;

	function __construct($tags, $prefix, $params) {
		$this->tags = $tags;
		$this->prefix = $prefix;
		$this->params = $params;
	}

	static function parse($str) {
		$tags = array();
		$prefix = null;
		$params = self::explode($str);

		if ($params[0][0] === "@") {
			$tags = array_shift($params);
			$tags = substr($tags, 1);
			$tagv = explode(";", $tags);
			$tags = array();
			foreach ($tagv as $x) {
				@list($k, $v) = explode("=", $x, 2);
				$tags[$k] = $v === null ? true : $v;
			}
		}

		if ($params[0][0] === ":") {
			$prefix = array_shift($params);
			$prefix = MessagePrefix::parse($prefix);
		}

		$params[0] = strtoupper($params[0]);

		return new self($tags, $prefix, $params);
	}

	static function explode($str) {
		$str = rtrim($str, "\r\n");
		$tags = null;
		if ($str[0] === "@")
			list($tags, $str) = explode(" ", $str, 2);
		$str = explode(" :", $str, 2);
		$params = explode(" ", $str[0]);
		if (count($str) > 1)
			$params[] = $str[1];
		if ($tags !== null)
			array_unshift($params, $tags);
		return $params;
	}

	static function implode($params) {
		$trailing = array_pop($params);
		if (strpos($trailing, " ") !== false
		or strpos($trailing, ":") !== false)
			$trailing = ":".$trailing;
		$params[] = $trailing;
		$str = implode(" ", $params) . "\r\n";
		return $str;
	}
}

class MessagePrefix {
	public $nick;
	public $user;
	public $host;

	function __construct($nick, $user, $host) {
		$this->nick = $nick;
		$this->user = $user;
		$this->host = $host;
	}

	static function parse($prefix) {
		if ($prefix === null)
			return new self(null, null, null);

		$npos = $prefix[0] == ":" ? 1 : 0;
		$upos = strpos($prefix, "!", $npos);
		$hpos = strpos($prefix, "@", $upos);

		if ($upos === false or $hpos === false) {
			$nick = null;
			$user = null;
			$host = substr($prefix, $npos);
		} else {
			$nick = substr($prefix, $npos, $upos++-$npos);
			$user = substr($prefix, $upos, $hpos++-$upos);
			$host = substr($prefix, $hpos);
		}

		return new self($nick, $user, $host);
	}
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

