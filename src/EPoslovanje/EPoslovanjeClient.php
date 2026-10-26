<?php

namespace GTCrais\HrEInvoicing\EPoslovanje;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use GTCrais\HrEInvoicing\Concerns\ChoosesEnvironment;
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\Contracts\FiscalizesAgain;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\EPoslovanje\Contracts\AccountProvider;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The line to ePoslovanje's REST API v2 (Pondi d.o.o., doc.eposlovanje.hr, 2.0.21 of 24.09.2026, checked against
 * its test server on 2026-10-24): every call carries the account's API key in the Authorization header; the document
 * goes as the XML text of a JSON request with the software identifier, and ePoslovanje validates it, signs it,
 * reports it to the tax authority when the account has outgoing fiscalization switched on, and queues it for the
 * buyer's intermediary. A document's answer names its standing with one of ePoslovanje's codes (1 waiting, 2 being
 * sent, 3 sent, 4 delivered, 5 approved, 6 rejected by the buyer, 7 paid, 8 partly paid, 9 delivery failed) and lists
 * its eReporting requests: the first kind is the document's own fiscalization, the fourth a report of its payment,
 * each answered by the tax authority later (`success`, its `errorCode` and `errorDescription`; a refused request is
 * stamped `completedOn` too). The environment is "demo" (the test server) or "production", and a call may take
 * attemptSeconds; the service provider builds it from config('hrEInvoicing').
 */
class EPoslovanjeClient implements EInvoiceGateway, FiscalizesAgain
{
	use ChoosesEnvironment;

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

	/** ePoslovanje's codes of a document that will not reach the buyer */
	protected const array REJECTED_CODES = [6, 9];

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
		[$fiscalization, $error] = $this->outcome($document, self::FISCALIZATION_REQUEST, anySuccess: true);
		[$payment, $paymentError] = $this->outcome($document, self::PAYMENT_REQUEST, anySuccess: false);

		if ($status === DocumentStatus::REJECTED && $error === null) {
			$error = $this->rejectionReason($document);
		}

		return ['status' => $status, 'fiscalization' => $fiscalization, 'error' => $error, 'payment' => $payment, 'paymentError' => $paymentError, 'answersError' => null];
	}

	public function reportPayment(string $electronicId, string $paidOn, float $amount): void
	{
		$this->call('post', '/api/v2/ereporting/paid/' . rawurlencode($electronicId), [
			'paymentDate' => $paidOn,
			'paidAmount' => round($amount, 2),
			'paymentType' => self::PAYMENT_BY_TRANSFER,
		]);
	}

	public function fiscalizeAgain(string $electronicId): void
	{
		$this->call('post', '/api/v2/ereporting/fiscalize/' . rawurlencode($electronicId));
	}

	/**
	 * The service answers its ping only to a key it knows; the account is then asked whether ePoslovanje fiscalizes
	 * its outgoing documents itself, as an account that does not leaves every e-invoice unreported.
	 */
	public function ping(): void
	{
		$account = $this->account();

		try {
			$response = $this->request($account)->get($this->serviceFor(self::SERVICES, $account) . '/api/v2/ping');
		} catch (ConnectionException $exception) {
			throw new EInvoiceUnavailableException($exception->getMessage());
		}

		// A refusal is the account's, an error the service's
		if ($response->serverError()) {
			throw new EInvoiceUnavailableException('HTTP ' . $response->status());
		}

		if (!$response->successful()) {
			throw new RuntimeException($this->failure($response));
		}

		$settings = $this->call('get', '/api/v2/account/f2settings', [], $account);
		$outgoing = $settings['AutomaticFiscalizeOutgoingInvoices'] ?? $settings['automaticFiscalizeOutgoingInvoices'] ?? null;

		if ($outgoing === false) {
			throw new RuntimeException('ePoslovanje does not fiscalize this account\'s outgoing documents: switch on automatic fiscalization of outgoing documents in the account\'s profile.');
		}
	}

	/**
	 * ePoslovanje's code of the document's standing as the package's.
	 *
	 * @param  array<string, mixed>  $document
	 */
	protected function documentStatus(array $document): DocumentStatus
	{
		$code = (int) ($document['status'] ?? 0);

		return match (true) {
			in_array($code, self::DELIVERED_CODES, true) => DocumentStatus::DELIVERED,
			in_array($code, self::REJECTED_CODES, true) => DocumentStatus::REJECTED,
			default => DocumentStatus::SENT,
		};
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
	 * Why the document will not reach the buyer: the note of its latest rejection or failed delivery, else that
	 * entry's text; null when ePoslovanje says nothing.
	 *
	 * @param  array<string, mixed>  $document
	 */
	protected function rejectionReason(array $document): ?string
	{
		$entry = collect((array) ($document['statusHistory'] ?? []))
			->filter(fn ($row) => is_array($row) && in_array((int) ($row['status'] ?? 0), self::REJECTED_CODES, true))
			->sortBy(fn (array $row) => (string) ($row['createdOn'] ?? ''))
			->last();

		$reason = trim((string) ($entry['note'] ?? '')) ?: trim((string) ($entry['statusText'] ?? ''));

		return $reason !== '' ? $reason : null;
	}

	/**
	 * A JSON call with the account's key; the intermediary's refusal becomes an exception with its message. A send's
	 * 400 that names the rules the document broke is the document refused for good; any other refusal (the account
	 * not active, its prepaid funds spent, the key unknown) may pass on a later try, once the account is put right.
	 *
	 * @param  array<string, mixed>  $payload
	 * @param  array<string, mixed>|null  $account
	 * @return array<int|string, mixed>
	 */
	protected function call(string $method, string $path, array $payload = [], ?array $account = null, bool $refusable = false): array
	{
		$account ??= $this->account();
		$url = $this->serviceFor(self::SERVICES, $account) . $path;

		try {
			$response = $method === 'get'
				? $this->request($account)->get($url, $payload)
				: $this->request($account)->post($url, $payload);
		} catch (ConnectionException $exception) {
			throw new RuntimeException($exception->getMessage());
		}

		if ($refusable && $this->refusesDocument($response)) {
			throw new EInvoiceRefusedException($this->failure($response));
		}

		if (!$response->successful()) {
			throw new RuntimeException($this->failure($response));
		}

		$json = $response->json();

		return is_array($json) ? $json : [];
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
	 * Why the intermediary refused: its error with the details, else the start of the body.
	 */
	protected function failure(Response $response): string
	{
		$json = $response->json();
		$prefix = 'HTTP ' . $response->status() . ': ';

		if (is_array($json)) {
			$error = trim($this->answerText($json['error'] ?? null));
			$details = trim($this->answerText($json['details'] ?? null));
			$message = trim($error . ($details !== '' ? ' (' . $details . ')' : ''));

			if ($message !== '') {
				return $prefix . $message;
			}
		}

		return 'HTTP ' . $response->status() . ($response->body() !== '' ? ': ' . mb_substr(trim($response->body()), 0, 300) : '');
	}
}
