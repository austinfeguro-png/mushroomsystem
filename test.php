<?php

$temp = 31;
$hum = 87;

$predRaw = file_get_contents(
    "http://localhost:5000/predict?temp={$temp}&hum={$hum}"
);

var_dump($predRaw);