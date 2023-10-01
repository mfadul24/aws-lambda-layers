<?php

use App\Http\AwsHttpWorker;
use Spiral\RoadRunner;
use Nyholm\Psr7;

require_once __DIR__. "/../vendor/autoload.php";

$worker = RoadRunner\Worker::create();
$psrFactory = new Psr7\Factory\Psr17Factory();

$psr7 = new AwsHttpWorker($worker, $psrFactory, $psrFactory, $psrFactory);

while (true) {
    try {
        $request = $psr7->waitRequest();

        if (!($request instanceof \Psr\Http\Message\ServerRequestInterface)) { // Termination request received
            break;
        }
    } catch (\Throwable $exception) {
        $psr7->respond(new Psr7\Response(400, [], $exception->getMessage())); // Bad Request
        continue;
    }

    try {
        // Application code logic
        $psr7->respond(
            new Psr7\Response(
            200,
            ['Set-Cookie' => 'test=testValue', 'Content-Type' => 'application/json'],
                json_encode(['RoadRunner' => 'Hello RoadRunner!'], JSON_THROW_ON_ERROR),
            2.0,
            'something'
        )
        );
    } catch (\Throwable) {
        $psr7->respond(new Psr7\Response(500, [], 'Something Went Wrong!'));
    }
}
