<?php

namespace GTCrais\HrEInvoicing\EPoslovanje;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use GTCrais\HrEInvoicing\Concerns\ChoosesEnvironment;
use GTCrais\HrEInvoicing\Concerns\RedactsSecrets;
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\Contracts\FiscalizesAgain;
use GTCrais\HrEInvoicing\Contracts\ListsBusinessUnits;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\EPoslovanje\Contracts\AccountProvider;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\Exceptions\PaymentReportRefusedException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use GTCrais\HrEInvoicing\Ubl\HrUbl;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * The line to ePoslovanje's REST API v2 (Pondi d.o.o., doc.eposlovanje.hr, 2.0.21 of 24.09.2026, checked against
 * its test server on 2026-10-24): every call carries the account's API key in the Authorization header; the document
 * goes as the XML text of a JSON request with the software identifier, and ePoslovanje validates it, signs it,
 * reports it to the tax authority when the account has outgoing fiscalization switched on, and queues it for the
 * buyer's intermediary. A document's answer names its standing with one of ePoslovanje's codes (1 waiting, 2 being
 * sent, 3 sent, 4 delivered, 5 approved, 6 rejected by the buyer, 7 paid, 8 partly paid, 9 delivery failed) and lists
 * its eReporting requests. A failed delivery is no final word (ePoslovanje, 2026-10-30): it tries again, mostly within
 * the week after it took the document, so the document is SENT meanwhile, with the moment it gives up
 * (deliveryRetriesUntil), and UNDELIVERED after it; it is fiscalized only once delivered, so an undelivered one needs
 * no storno: deleted in ePoslovanje's web application (its API has no call for it), it goes again corrected under its
 * number, never as a copy, which could not be fiscalized without the original. The eReporting requests: the
 * first kind is the document's own fiscalization, the fourth a report of its payment, each answered by the tax
 * authority later (`success`, its `errorCode` and `errorDescription`; a refused request is stamped `completedOn` too).
 * The environment is "demo" (the test server) or "production", and a call may take attemptSeconds; the service provider
 * builds it from config('hrEInvoicing'). A failure's message never carries the account's API key.
 */
class EPoslovanjeClient implements EInvoiceGateway, FiscalizesAgain, ListsBusinessUnits
{
	use ChoosesEnvironment;
	use RedactsSecrets;

	/** The intermediary's services by environment */
	public const array SERVICES = [
		'demo' => 'https://test.eposlovanje.hr',
		'production' => 'https://eracun.eposlovanje.hr',
	];

	/** The eReporting request that is the document's own fiscalization ("Evidencija izlaznog računa") */
	protected const int FISCALIZATION_REQUEST = 1;

	/** The eReporting request that reports the document's payment ("Evidencija naplate računa") */
	protected const int PAYMENT_REQUEST = 4;

	/** The payment kind of a bank transfer ("T") in a payment report */
	protected const int PAYMENT_BY_TRANSFER = 1;

	/** ePoslovanje's codes of a document the buyer has */
	protected const array DELIVERED_CODES = [4, 5, 7, 8];

	/** ePoslovanje's codes of a document the buyer approved or paid, after which no rejection comes */
	protected const array ACCEPTED_CODES = [5, 7, 8];

	/** ePoslovanje's code of a document the buyer rejected */
	protected const int REJECTED_CODE = 6;

	/** ePoslovanje's code of a document whose delivery to the buyer's intermediary failed */
	protected const int DELIVERY_FAILED_CODE = 9;

	/**
	 * The days after ePoslovanje took a document within which it tries again to deliver it, after which a failed
	 * delivery is taken for final (ePoslovanje, 2026-10-30: mostly within the next seven days)
	 */
	public const int DELIVERY_RETRY_DAYS = 7;

	/** The most outgoing documents one listing answers with */
	protected const int PAGE_SIZE = 1000;

	/** The most listings one lookup reads before it gives up rather than run on (a million documents) */
	protected const int MAX_PAGES = 1000;

	/**
	 * The longest one lookup reads listings, after which it gives up as it does past MAX_PAGES: an app holds a lock while
	 * it looks a document up and then sends it, which must outlast both
	 */
	public const int LOOKUP_SECONDS = 60;

	/** The caller's own words, which no other word in the answer outweighs: the caller not recognized, the API key */
	protected const string CALLER_REFUSAL = '/autentifik|autentik|authentic|unauthori[sz]|api[\s_-]*(?:klju|key)/iu';

	/**
	 * The words of a refusal about the recipient (the tax authority's registry of addresses, AMS, the recipient or the
	 * buyer, in any case of the word), which is the document's, whatever state of an account it names
	 */
	protected const string RECIPIENT_REFUSAL = '/\bAMS\b|primatelj\w*|recipient|receiver|kup(?:ac|c)\w*|buyer/iu';

	/** The account's funds run out, which only the clinic's account has (the means of payment a document names are no funds) */
	protected const string FUNDS_REFUSAL = '/(?:nema\w*\s+(?:dovoljno\s+)?|nedovoljn\w*\s+|nedostatn\w*\s+)sredst\w*+(?!\W+plaćanj)|sredst\w*+(?!\W+plaćanj)\W+(?:\w+\W+){0,3}?potrošen|insufficient\s+(?:funds|credit|balance)/iu';

	/** The words of a 400 that refuses the account: authorization, a user account or a subscription not active, switched off, blocked or run out */
	protected const string ACCOUNT_REFUSAL = '/autoriz|not\s+authori[sz]|nemate\s+ovlast|nema\s+ovlast'
		. '|(?:korisni\w*|pretplat\w*|subscription|account)\W+(?:\w+\W+){0,3}?(?:nije\s+(?:aktiv|ovla[sš]ten)|neaktiv|deaktiv|ovla[sš]ten|suspendir|blokir|zaključan|istek|inactive|disabled|suspended|blocked|locked|expired|not\s+active)/iu';

	public function __construct(
		protected AccountProvider $accounts,
		protected string $environment = 'demo',
		protected int $attemptSeconds = 15
	) {}

	public function ready(): bool
	{
		return $this->accountOf($this->accounts) !== null;
	}

	public function send(string $xml): array
	{
		$account = $this->account();
		$answer = $this->call('post', '/api/v2/document/send', ['document' => $xml, 'softwareId' => $account['softwareId']], $account, refusable: true);

		if (blank($answer['id'] ?? null)) {
			throw new RuntimeException('The intermediary answered without a document identifier.');
		}

		return ['electronicId' => (string) $answer['id'], 'status' => DocumentStatus::SENT];
	}

	/**
	 * Looked up among the outgoing documents taken in from the day before the first try on, newest first and a page
	 * at a time, by the number the document carries and, when given, the day it was issued (`issuedOn`); the newest
	 * of that number when it was sent more than once. The listing is read to its end, an empty page, as a page shorter
	 * than asked for may be only the server's own page size; one that answers the same page again, or runs past
	 * MAX_PAGES or LOOKUP_SECONDS, cannot say the document is not there, so the lookup throws rather than answer null.
	 */
	public function sentDocument(string $number, DateTimeInterface $since, ?string $issueDate = null): ?array
	{
		$from = CarbonImmutable::instance($since)->subDay()->toIso8601String();
		$offset = 0;
		$firstId = null;
		$until = CarbonImmutable::now()->addSeconds(self::LOOKUP_SECONDS);

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			if ($page > 0 && CarbonImmutable::now()->gte($until)) {
				throw new RuntimeException('The intermediary\'s list of outgoing documents could not be read through in ' . self::LOOKUP_SECONDS . ' seconds, so it cannot tell whether it holds document ' . $number . '.');
			}

			$documents = $this->call('get', '/api/v2/document/outgoing', ['insertedFrom' => $from, 'limit' => self::PAGE_SIZE, 'offset' => $offset]);
			$documents = array_values(array_filter(array_is_list($documents) ? $documents : [$documents], 'is_array'));

			if ($documents === []) {
				return null;
			}

			if ($page > 0 && ($documents[0]['id'] ?? null) === $firstId) {
				throw new RuntimeException('The intermediary\'s list of outgoing documents answers the same page again, so it cannot tell whether it holds document ' . $number . '.');
			}

			$firstId = $documents[0]['id'] ?? null;

			$document = collect($documents)
				->filter(fn (array $row) => (string) ($row['documentId'] ?? '') === $number && filled($row['id'] ?? null)
					// Numbers start again every year: the day of issue tells one year's document from another's
					&& ($issueDate === null || blank($row['issuedOn'] ?? null) || substr((string) $row['issuedOn'], 0, 10) === $issueDate))
				->sortByDesc(fn (array $row) => (int) $row['id'])
				->first();

			if ($document !== null) {
				return ['electronicId' => (string) $document['id'], 'status' => $this->documentStatus($document)];
			}

			$offset += count($documents);
		}

		throw new RuntimeException('The intermediary\'s list of outgoing documents runs past ' . self::MAX_PAGES . ' pages, so it cannot tell whether it holds document ' . $number . '.');
	}

	public function status(string $electronicId): array
	{
		$document = $this->call('get', '/api/v2/document/status/' . rawurlencode($electronicId));

		if (blank($document['id'] ?? null)) {
			throw new RuntimeException('The intermediary does not know the document ' . $electronicId . '.');
		}

		$status = $this->documentStatus($document);
		[$fiscalization, $fiscalizationError] = $this->outcome($document, self::FISCALIZATION_REQUEST, anySuccess: true);
		[$payment, $paymentError] = $this->outcome($document, self::PAYMENT_REQUEST, anySuccess: false);

		// Why the buyer rejected it (the buyer's note) is the reason an app keeps for it, and for one that could not be
		// delivered the failed delivery's words; the tax authority's reason about its fiscalization is kept apart
		$rejectionReason = $status === DocumentStatus::REJECTED ? $this->statusWords($document, self::REJECTED_CODE) : null;
		$deliveryError = $status === DocumentStatus::UNDELIVERED ? $this->statusWords($document, self::DELIVERY_FAILED_CODE) : null;
		$retrying = $status === DocumentStatus::SENT && (int) ($document['status'] ?? 0) === self::DELIVERY_FAILED_CODE;

		// Every payment report it took is an eReporting request of its own, answered or not
		$paymentReports = collect((array) ($document['eReportingRequests'] ?? []))
			->filter(fn ($request) => is_array($request) && (int) ($request['type'] ?? 0) === self::PAYMENT_REQUEST)
			->count();

		return [
			'status' => $status, 'fiscalization' => $fiscalization, 'error' => $rejectionReason ?? $deliveryError ?? $fiscalizationError, 'payment' => $payment, 'paymentError' => $paymentError, 'paymentReports' => $paymentReports, 'answersError' => null,
			'rejectionReason' => $rejectionReason, 'fiscalizationError' => $fiscalizationError,
			'deliveryRetriesUntil' => $retrying ? $this->retriesUntil($document)->toIso8601String() : null,
			'accepted' => in_array((int) ($document['status'] ?? 0), self::ACCEPTED_CODES, true),
		];
	}

	public function businessUnits(string $oib): array
	{
		$units = $this->call('get', '/api/v2/businessunits/' . rawurlencode($oib));

		return collect(array_is_list($units) ? $units : [$units])
			->filter(fn ($unit) => is_array($unit) && filled($unit['code'] ?? null))
			->map(fn (array $unit) => ['name' => trim((string) ($unit['name'] ?? '')), 'code' => HrUbl::businessUnitCode((string) $unit['code'], $oib)])
			->filter(fn (array $unit) => $unit['code'] !== '')
			->values()
			->all();
	}

	public function reportPayment(string $electronicId, string $paidOn, float $amount): void
	{
		$this->call('post', '/api/v2/ereporting/paid/' . rawurlencode($electronicId), [
			'paymentDate' => $paidOn,
			'paidAmount' => round($amount, 2),
			'paymentType' => self::PAYMENT_BY_TRANSFER,
		], paymentReport: true);
	}

	public function fiscalizeAgain(string $electronicId): void
	{
		$this->call('post', '/api/v2/ereporting/fiscalize/' . rawurlencode($electronicId));
	}

	/**
	 * The service answers its ping only to a key it knows; the account is then asked whether ePoslovanje fiscalizes
	 * its outgoing documents itself, as an account that does not leaves every e-invoice unreported. Either call that
	 * gets no whole answer, or a server error, leaves the account untried (connectionTest()).
	 */
	public function ping(): void
	{
		$account = $this->account();

		$this->connectionTest('/api/v2/ping', $account);
		$settings = $this->connectionTest('/api/v2/account/f2settings', $account);
		$outgoing = $settings['AutomaticFiscalizeOutgoingInvoices'] ?? $settings['automaticFiscalizeOutgoingInvoices'] ?? null;

		if ($outgoing === false) {
			throw new RuntimeException('ePoslovanje does not fiscalize this account\'s outgoing documents: switch on automatic fiscalization of outgoing documents in the account\'s profile.');
		}
	}

	/**
	 * A call of the connection test with the account's key. One that gets no whole answer (none at all, or one cut off
	 * on its way), or a server error, finds the service down and the account untried: an EInvoiceUnavailableException.
	 * A refusal is the account's.
	 *
	 * @param  array<string, mixed>  $account
	 * @return array<int|string, mixed>
	 */
	protected function connectionTest(string $path, array $account): array
	{
		try {
			$response = $this->request($account)->get($this->serviceFor(self::SERVICES, $account) . $path);
		} catch (HttpClientException $exception) {
			throw new EInvoiceUnavailableException($this->transportFailure($exception, $account));
		}

		// A refusal is the account's, an error the service's
		if ($response->serverError()) {
			throw new EInvoiceUnavailableException('HTTP ' . $response->status());
		}

		if (!$response->successful()) {
			throw new RuntimeException($this->failure($response, $account));
		}

		$json = $response->json();

		return is_array($json) ? $json : [];
	}

	/**
	 * ePoslovanje's code of the document's standing as the package's: a failed delivery is still on its way while
	 * ePoslovanje tries again, and undelivered once the days it does so are over.
	 *
	 * @param  array<string, mixed>  $document
	 */
	protected function documentStatus(array $document): DocumentStatus
	{
		$code = (int) ($document['status'] ?? 0);

		return match (true) {
			in_array($code, self::DELIVERED_CODES, true) => DocumentStatus::DELIVERED,
			$code === self::REJECTED_CODE => DocumentStatus::REJECTED,
			$code === self::DELIVERY_FAILED_CODE => CarbonImmutable::now()->gte($this->retriesUntil($document)) ? DocumentStatus::UNDELIVERED : DocumentStatus::SENT,
			default => DocumentStatus::SENT,
		};
	}

	/**
	 * Until when ePoslovanje tries again to deliver the document: DELIVERY_RETRY_DAYS after it took it (insertedOn), or
	 * from now when it names no such moment.
	 *
	 * @param  array<string, mixed>  $document
	 */
	protected function retriesUntil(array $document): CarbonImmutable
	{
		try {
			$taken = filled($document['insertedOn'] ?? null) ? CarbonImmutable::parse((string) $document['insertedOn']) : CarbonImmutable::now();
		} catch (InvalidArgumentException) {
			$taken = CarbonImmutable::now();
		}

		return $taken->addDays(self::DELIVERY_RETRY_DAYS);
	}

	/**
	 * The tax authority's answer to the document's requests of one kind: taken when ePoslovanje marks the request a
	 * success (for the fiscalization, any of them: a later duplicate refused changes nothing), refused with the tax
	 * authority's code and reason when the latest is marked a failure or stamped completed without success, else
	 * still waiting; nothing while ePoslovanje has made no such request.
	 *
	 * @param  array<string, mixed>  $document
	 * @return array{0: ?FiscalizationStatus, 1: ?string}
	 */
	protected function outcome(array $document, int $type, bool $anySuccess): array
	{
		$requests = collect((array) ($document['eReportingRequests'] ?? []))
			->filter(fn ($request) => is_array($request) && (int) ($request['type'] ?? 0) === $type)
			->sortBy(fn (array $request) => (string) ($request['createdOn'] ?? ''))
			->values();

		$latest = $requests->last();

		if ($latest === null) {
			return [null, null];
		}

		if (($latest['success'] ?? null) === true || ($anySuccess && $requests->contains(fn (array $request) => ($request['success'] ?? null) === true))) {
			return [FiscalizationStatus::FISCALIZED, null];
		}

		$reason = trim(trim((string) ($latest['errorCode'] ?? '')) . ' ' . trim((string) ($latest['errorDescription'] ?? '')));

		if ($reason !== '' || (($latest['success'] ?? null) === false && filled($latest['completedOn'] ?? null))) {
			return [FiscalizationStatus::FAILED, $reason !== '' ? $reason : null];
		}

		return [FiscalizationStatus::WAITING, null];
	}

	/**
	 * The words of the document's latest entry of the given standing (the buyer's rejection, a failed delivery): its
	 * note, else its text; null when ePoslovanje says nothing.
	 *
	 * @param  array<string, mixed>  $document
	 */
	protected function statusWords(array $document, int $code): ?string
	{
		$entry = collect((array) ($document['statusHistory'] ?? []))
			->filter(fn ($row) => is_array($row) && (int) ($row['status'] ?? 0) === $code)
			->sortBy(fn (array $row) => (string) ($row['createdOn'] ?? ''))
			->last();

		$reason = trim((string) ($entry['note'] ?? '')) ?: trim((string) ($entry['statusText'] ?? ''));

		return $reason !== '' ? $reason : null;
	}

	/**
	 * A JSON call with the account's key; the intermediary's refusal becomes an exception with its message. A send's
	 * 400 that names the rules the document broke is the document refused for good; any other refusal (the account
	 * not active, its prepaid funds spent, the key unknown) may pass on a later try, once the account is put right, as
	 * may a call that got no whole answer (none at all, or one cut off on its way).
	 *
	 * @param  array<string, mixed>  $payload
	 * @param  array<string, mixed>|null  $account
	 * @return array<int|string, mixed>
	 */
	protected function call(string $method, string $path, array $payload = [], ?array $account = null, bool $refusable = false, bool $paymentReport = false): array
	{
		$account ??= $this->account();
		$url = $this->serviceFor(self::SERVICES, $account) . $path;

		try {
			$response = $method === 'get'
				? $this->request($account)->get($url, $payload)
				: $this->request($account)->post($url, $payload);
		} catch (HttpClientException $exception) {
			throw new RuntimeException($this->transportFailure($exception, $account));
		}

		if ($refusable && $this->refusesDocument($response)) {
			throw new EInvoiceRefusedException($this->failure($response, $account));
		}

		// A payment report refused on the spot (the tax authority's refusal passed on, a report it could not take) is
		// said as such, for the app to word; a failure of the line or of the service is an ordinary one
		if ($paymentReport && in_array($response->status(), [400, 422], true)) {
			throw new PaymentReportRefusedException($this->failure($response, $account));
		}

		if (!$response->successful()) {
			throw new RuntimeException($this->failure($response, $account));
		}

		$json = $response->json();

		return is_array($json) ? $json : [];
	}

	/**
	 * Why a call got no whole answer: a RequestException carries what arrived of it (a status, and the body as far as it
	 * came), read as any other refusal; any other failure says itself. Never with the account's API key.
	 *
	 * @param  array<string, mixed>  $account
	 */
	protected function transportFailure(HttpClientException $exception, array $account): string
	{
		return $exception instanceof RequestException
			? $this->failure($exception->response, $account)
			: $this->redact($exception->getMessage(), $this->secrets($account));
	}

	/**
	 * What of the account must never be said: its API key.
	 *
	 * @param  array<string, mixed>|null  $account
	 * @return list<mixed>
	 */
	protected function secrets(?array $account): array
	{
		return [$account['apiKey'] ?? null];
	}

	/**
	 * Whether the answer refuses the document itself: a 422, or a 400 that is not the account's own refusal.
	 * ePoslovanje names three causes for a send's 400: the document failing its checks (the buyer missing from the tax
	 * authority's registry among them), the account not active, and its funds spent; a key it does not know it
	 * answers the same way ("Greška pri autentifikaciji korisnika"). The validation's own words or a rule of the
	 * standard ("[HR-BR-25]") always refuse the document; the account's words are tried again, as they pass once the
	 * account is put right; any other words are the document's, whose refusal waits for a person instead of being
	 * sent again until it happens to pass. The words are weighed in turn: the validation's, then the caller's own (the
	 * authentication, the API key) and of its funds, then those about the recipient (AMS, the recipient or the buyer,
	 * whose account blocked is the document's affair), then the account's state; so a document's own ("Primatelj nije
	 * aktivan u AMS-u", "ovlaštena osoba", "sredstva plaćanja") never read as the account's.
	 */
	protected function refusesDocument(Response $response): bool
	{
		if ($response->status() === 422) {
			return true;
		}

		if ($response->status() !== 400) {
			return false;
		}

		$json = $response->json();
		// The words alone are weighed, never the keys of a detail sent as an object
		$text = is_array($json) ? $this->answerWords($json['error'] ?? null) . ' ' . $this->answerWords($json['details'] ?? null) : $response->body();

		return match (true) {
			(bool) preg_match('/validacij|schematron|\[(?:HR-)?BR-[A-Z0-9-]+\]/iu', $text) => true,
			(bool) preg_match(self::CALLER_REFUSAL, $text), (bool) preg_match(self::FUNDS_REFUSAL, $text) => false,
			(bool) preg_match(self::RECIPIENT_REFUSAL, $text) => true,
			default => !preg_match(self::ACCOUNT_REFUSAL, $text),
		};
	}

	/**
	 * The words of a field of the intermediary's answer: a scalar as it is, every scalar inside a list or an object,
	 * nothing for none.
	 */
	protected function answerWords(mixed $value): string
	{
		if (is_array($value)) {
			$words = [];

			array_walk_recursive($value, function (mixed $item) use (&$words) {
				if (is_scalar($item)) {
					$words[] = (string) $item;
				}
			});

			return implode(' ', $words);
		}

		return is_scalar($value) ? (string) $value : '';
	}

	/**
	 * A field of the intermediary's answer as text: a scalar as it is, a list or an object as JSON, nothing as empty.
	 */
	protected function answerText(mixed $value): string
	{
		return match (true) {
			$value === null => '',
			is_scalar($value) => (string) $value,
			default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		};
	}

	/**
	 * @param  array<string, mixed>  $account
	 */
	protected function request(array $account): PendingRequest
	{
		return Http::acceptJson()
			->withHeaders(['Authorization' => (string) $account['apiKey']])
			->timeout(max(1, $this->attemptSeconds))
			->connectTimeout(5);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function account(): array
	{
		return $this->accountOf($this->accounts) ?? throw new RuntimeException('The intermediary account is not set up.');
	}

	/**
	 * Why the intermediary refused: its error with the details, else the start of the body; never with the account's
	 * API key.
	 *
	 * @param  array<string, mixed>|null  $account
	 */
	protected function failure(Response $response, ?array $account): string
	{
		$json = $response->json();

		if (is_array($json)) {
			$error = trim($this->answerText($json['error'] ?? null));
			$details = trim($this->answerText($json['details'] ?? null));
			$message = trim($error . ($details !== '' ? ' (' . $details . ')' : ''));

			if ($message !== '') {
				return 'HTTP ' . $response->status() . ': ' . $this->redact($message, $this->secrets($account));
			}
		}

		$excerpt = $this->bodyExcerpt($response->body(), $this->secrets($account));

		return 'HTTP ' . $response->status() . ($excerpt !== '' ? ': ' . $excerpt : '');
	}
}
