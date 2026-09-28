<?php

$root = dirname(__DIR__);
$source = file_get_contents($root.'/resources/js/locales/ar.json');
if ($source === false) {
    throw new RuntimeException('Cannot read the Arabic interface catalog.');
}
$messages = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
if (! is_array($messages) || array_is_list($messages)) {
    throw new RuntimeException('The catalog must be an object of literal translation keys.');
}
foreach ($messages as $key => $value) {
    if (! is_string($key) || ! is_string($value) || trim($value) === '') {
        throw new RuntimeException('Translation keys and nonempty values must be strings.');
    }
}
if (file_put_contents($root.'/lang/ar.json', $source) === false) {
    throw new RuntimeException('Cannot update the Laravel Arabic catalog.');
}
echo 'Synchronized '.count($messages).' Arabic messages.'.PHP_EOL;
