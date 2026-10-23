<?php

return [

	/*
	|--------------------------------------------------------------------------
	| Gateway
	|--------------------------------------------------------------------------
	|
	| The line to the information intermediary that delivers the e-invoices,
	| reports them to the tax authority and takes the payment reports:
	| "mojeracun" talks to Moj-eRačun's REST API; "fake" answers at once
	| without a network (Testing\FakeEInvoiceGateway), for a test suite.
	| One call may take attemptSeconds.
	|
	*/

	'gateway' => env('HR_EINVOICING_GATEWAY', 'mojeracun'),

	'attemptSeconds' => 15,

	/*
	|--------------------------------------------------------------------------
	| Moj-eRačun
	|--------------------------------------------------------------------------
	|
	| "demo" sends to Moj-eRačun's test service, "production" to the real one.
	| The account is the one the calls carry unless the app binds an account
	| provider of its own: the username and password of the Moj-eRačun
	| account, the issuer's OIB, its business unit when it has one, and the
	| software identifier Moj-eRačun issued for the software.
	|
	*/

	'mojEracun' => [
		'environment' => env('MOJ_ERACUN_ENVIRONMENT', 'demo'),
		'username' => env('MOJ_ERACUN_USERNAME'),
		'password' => env('MOJ_ERACUN_PASSWORD'),
		'oib' => env('MOJ_ERACUN_OIB'),
		'businessUnit' => env('MOJ_ERACUN_BUSINESS_UNIT'),
		'softwareId' => env('MOJ_ERACUN_SOFTWARE_ID'),
	],

];
