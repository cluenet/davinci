#!/usr/bin/env php
<?php

$arg = $argv[1];

$db = unserialize(file_get_contents("users.db"));

eval("global \$db; $arg");

file_put_contents("users.db", serialize($db));
