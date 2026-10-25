<?php

return [

	/*
	|--------------------------------------------------------------------------
	| Gateway
	|--------------------------------------------------------------------------
	|
	| The information intermediary that delivers the e-invoices, reports them
	| to the tax authority and takes the payment reports: "mojeracun" talks to
	| Moj-eRačun's REST API, "eposlovanje" to ePoslovanje's; "fake" answers at
	| once without a network (Testing\FakeEInvoiceGateway), for a test suite.
	| An app that uses both asks GatewayManager for one by name. One call may
	| take attemptSeconds.
	|
	| Every intermediary has a demo (test) service and a production one, and
	| different accounts on each. The production service is refused outside a
	| production installation (APP_ENV=production) unless
	| allowProductionAnywhere is true, so a development machine never reaches
	| it, whatever its .env holds.
	|
	| "fake", switched on, has the fake answer for every intermediary asked
	| for by name, as that intermediary, the gateway keeping the name of the
	| one the suite stands in for; the gateway itself is the fake alone.
	|
	*/

	'gateway' => env('HR_EINVOICING_GATEWAY', 'mojeracun'),

	'fake' => (bool) env('HR_EINVOICING_FAKE', false),

	'attemptSeconds' => 15,

	'allowProductionAnywhere' => (bool) env('HR_EINVOICING_ALLOW_PRODUCTION_ANYWHERE', false),

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

	/*
	|--------------------------------------------------------------------------
	| ePoslovanje
	|--------------------------------------------------------------------------
	|
	| "demo" sends to ePoslovanje's test server, "production" to the real one.
	| The account is the one the calls carry unless the app binds an account
	| provider of its own: the API key ePoslovanje shows in the account's
	| control panel (a test account's key opens only the test server), the
	| issuer's OIB, and a software identifier of the app's own choosing.
	|
	*/

	'ePoslovanje' => [
		'environment' => env('EPOSLOVANJE_ENVIRONMENT', 'demo'),
		'apiKey' => env('EPOSLOVANJE_API_KEY'),
		'oib' => env('EPOSLOVANJE_OIB'),
		'softwareId' => env('EPOSLOVANJE_SOFTWARE_ID'),
	],

];
